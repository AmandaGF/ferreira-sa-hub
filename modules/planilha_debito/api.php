<?php
/**
 * Planilha de Cálculo — API
 * Aceita PDF, imagem (PNG/JPG, inclui paste Ctrl+V) ou texto colado.
 * Claude AI extrai dados → Gera XLSX com layout FeS.
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
$pdo = db();
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(array('error' => 'POST only')); exit; }
if (!validate_csrf()) { echo json_encode(array('error' => 'Token inválido', 'csrf' => generate_csrf_token())); exit; }

$newCsrf = generate_csrf_token();
$action = $_POST['action'] ?? '';

// Prompt unificado pros 3 modos. Detalha formato DrCalc.net E Jusfy.
$systemPrompt = 'Você é um assistente especializado em cálculos judiciais (planilhas de débito). '
    . 'Você recebe um cálculo em PDF, imagem ou texto — geralmente do DrCalc.net, Jusfy ou similar — e deve extrair TODOS os dados em JSON estruturado. '
    . 'Retorne APENAS o JSON, sem markdown, sem explicações, sem ```json```. '
    . 'FORMATOS COMUNS: '
    . '(A) DrCalc.net: cabeçalho com "PLANILHA DE DÉBITOS JUDICIAIS", "Data de atualização", "Indexador utilizado", "Juros moratórios", "Acréscimo de X% referente a multa", "Honorários advocatícios de X%". Tabela com colunas ITEM, DESCRIÇÃO, DATA, VALOR SINGELO, VALOR ATUALIZADO, JUROS MORATÓRIOS TAXA LEGAL, PERÍODO DO JUROS, TOTAL. Mapeie: valor_nominal=VALOR SINGELO, valor_atualizado=VALOR ATUALIZADO, juros=JUROS, total da linha=TOTAL. correcao_monetaria = VALOR ATUALIZADO - VALOR SINGELO. '
    . '(B) Jusfy: tabela com parcelas, valor nominal, correção, juros, atualizado, pago. Mapeie direto. '
    . 'IMPORTANTE: NÃO inclua nas "observacoes" qualquer dado sobre "Escritório", "Endereço", endereço pessoal de partes, telefones ou rodapé do PDF. O sistema injeta o cabeçalho/rodapé do escritório automaticamente. As observações devem conter APENAS notas técnicas do cálculo: período do atraso, base de cálculo, índice usado, fundamentação legal, multa, honorários. '
    . 'Se você não conseguir identificar o "processo", "autor" ou "réu" no input, deixe esses campos como string vazia "" — não invente. '
    . 'O JSON deve ter esta estrutura exata: '
    . '{"meta":{"titulo":"...","processo":"nº do processo","vara":"...","autor":"...","reu":"...","indice_correcao":"IPCA (IBGE) ou INPC ou IPCA-E etc","juros":"Taxa Legal art 406 ou 1% ao mês etc","data_calculo":"dd/mm/yyyy","observacoes":"APENAS notas do cálculo, sem endereços/telefones"},'
    . '"parcelas":[{"numero":1,"descricao":"Jan/2024","vencimento":"01/01/2024","valor_nominal":1500.00,"correcao_monetaria":150.30,"juros":45.20,"valor_atualizado":1695.50,"pago":0,"observacao":""}],'
    . '"subtotais":{"total_nominal":0,"total_correcao":0,"total_juros":0,"total_atualizado":0,"total_pago":0},'
    . '"debito_total":0,"honorarios_pct":0,"honorarios_valor":0,"total_execucao":0}';

/**
 * Chama Claude API com um array de content blocks (document/image/text) e retorna [textContent, apiData].
 * Bate em $apiKey via closure pra evitar global.
 */
function pc_chamar_claude($apiKey, $systemPrompt, $contentBlocks) {
    $payload = json_encode(array(
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 16384,
        'temperature' => 0,
        'system' => $systemPrompt,
        'messages' => array(
            array('role' => 'user', 'content' => $contentBlocks),
        ),
    ), JSON_UNESCAPED_UNICODE);

    $response = ''; $httpCode = 0; $curlErr = '';
    for ($tentativa = 0; $tentativa < 3; $tentativa++) {
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($curlErr) { if ($tentativa < 2) { sleep(2); continue; } return array(null, null, 'Erro de rede: ' . $curlErr); }
        if ($httpCode !== 529 && $httpCode !== 429) break;
        sleep(pow(2, $tentativa) * 2);
    }
    if ($httpCode >= 400) {
        $errData = json_decode($response, true);
        $errMsg = isset($errData['error']['message']) ? $errData['error']['message'] : 'HTTP ' . $httpCode;
        return array(null, null, 'Claude API: ' . $errMsg);
    }
    $apiData = json_decode($response, true);
    $textContent = '';
    if (isset($apiData['content'])) {
        foreach ($apiData['content'] as $block) {
            if ($block['type'] === 'text') { $textContent .= $block['text']; break; }
        }
    }
    if (!$textContent) return array(null, null, 'Claude não retornou dados');
    return array($textContent, $apiData, null);
}

// Ações suportadas: processar_pdf, processar_imagem, processar_texto
if (in_array($action, array('processar_pdf', 'processar_imagem', 'processar_texto'), true)) {
    $caseId = (int)($_POST['case_id'] ?? 0) ?: null;
    $titulo = clean_str($_POST['titulo'] ?? '', 200) ?: 'Cálculo ' . date('d/m/Y H:i');

    // Amanda 10/06/2026: aceita client_id direto (quando nao tem processo)
    $clientIdInput = (int)($_POST['client_id'] ?? 0) ?: null;

    // Amanda 15/06/2026: nomes como fallback — se o front enviou só o NOME
    // (porque o datalist nao casou exato), resolve via SQL.
    $clientNome = trim((string)($_POST['client_nome'] ?? ''));
    $caseLabel  = trim((string)($_POST['case_label'] ?? ''));

    // Buscar client_id pelo case (precedencia: do case quando ha case)
    $clientId = $clientIdInput;
    if ($caseId) {
        $stmtC = $pdo->prepare("SELECT client_id FROM cases WHERE id = ?");
        $stmtC->execute(array($caseId));
        $clientId = (int)$stmtC->fetchColumn() ?: $clientIdInput;
    }

    // Fallback: cliente nao foi resolvido por ID nem por case — tenta pelo nome
    if (!$clientId && $clientNome !== '') {
        try {
            $stCl = $pdo->prepare("SELECT id FROM clients WHERE LOWER(name) = LOWER(?) ORDER BY id DESC LIMIT 1");
            $stCl->execute(array($clientNome));
            $achouId = (int)$stCl->fetchColumn();
            if (!$achouId) {
                // Tenta busca parcial por palavras (quebra em palavras com AND)
                $palavras = preg_split('/\s+/', $clientNome);
                $palavras = array_filter($palavras, function($p){ return mb_strlen($p) >= 3; });
                if (!empty($palavras)) {
                    $wh = array(); $bind = array();
                    foreach ($palavras as $p) { $wh[] = 'name LIKE ?'; $bind[] = '%' . $p . '%'; }
                    $sql = "SELECT id FROM clients WHERE " . implode(' AND ', $wh) . " ORDER BY id DESC LIMIT 1";
                    $stCl2 = $pdo->prepare($sql);
                    $stCl2->execute($bind);
                    $achouId = (int)$stCl2->fetchColumn();
                }
            }
            if ($achouId) $clientId = $achouId;
        } catch (Throwable $e) { /* silent */ }
    }

    // Fallback: caso nao foi resolvido por ID — tenta pelo label do select
    if (!$caseId && $caseLabel !== '') {
        try {
            // Geralmente o label vem como 'Titulo — 1234567-89.xxxx' — quebra
            $partes = explode(' — ', $caseLabel);
            $titBusca = trim($partes[0] ?? '');
            $cnjBusca = trim($partes[1] ?? '');
            if ($cnjBusca !== '') {
                $stC2 = $pdo->prepare("SELECT id, client_id FROM cases WHERE case_number LIKE ? LIMIT 1");
                $stC2->execute(array('%' . $cnjBusca . '%'));
                $r = $stC2->fetch();
                if ($r) { $caseId = (int)$r['id']; if (!$clientId) $clientId = (int)$r['client_id']; }
            } elseif ($titBusca !== '') {
                $stC3 = $pdo->prepare("SELECT id, client_id FROM cases WHERE LOWER(title) = LOWER(?) LIMIT 1");
                $stC3->execute(array($titBusca));
                $r = $stC3->fetch();
                if ($r) { $caseId = (int)$r['id']; if (!$clientId) $clientId = (int)$r['client_id']; }
            }
        } catch (Throwable $e) { /* silent */ }
    }

    $apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
    if (!$apiKey) {
        echo json_encode(array('error' => 'ANTHROPIC_API_KEY não configurada', 'csrf' => $newCsrf));
        exit;
    }

    // Monta content blocks conforme o tipo
    $contentBlocks = array();
    if ($action === 'processar_pdf') {
        $pdfBase64 = $_POST['pdf_base64'] ?? '';
        if (!$pdfBase64) { echo json_encode(array('error' => 'PDF não enviado', 'csrf' => $newCsrf)); exit; }
        $contentBlocks[] = array('type' => 'document', 'source' => array(
            'type' => 'base64', 'media_type' => 'application/pdf', 'data' => $pdfBase64,
        ));
        $contentBlocks[] = array('type' => 'text', 'text' => 'Extraia todos os dados desta planilha em JSON. Inclua TODAS as parcelas/itens. Retorne APENAS o JSON.');
    } elseif ($action === 'processar_imagem') {
        $imgBase64 = $_POST['img_base64'] ?? '';
        $imgMime = $_POST['img_mime'] ?? 'image/png';
        if (!$imgBase64) { echo json_encode(array('error' => 'Imagem não enviada', 'csrf' => $newCsrf)); exit; }
        if (!in_array($imgMime, array('image/png','image/jpeg','image/jpg','image/webp'), true)) {
            $imgMime = 'image/png';
        }
        $contentBlocks[] = array('type' => 'image', 'source' => array(
            'type' => 'base64', 'media_type' => $imgMime, 'data' => $imgBase64,
        ));
        $contentBlocks[] = array('type' => 'text', 'text' => 'Esta imagem é um print de uma planilha de cálculo judicial (provavelmente DrCalc.net). Leia TODOS os números e datas com atenção e extraia em JSON. Atenção a vírgulas decimais brasileiras. Retorne APENAS o JSON.');
    } elseif ($action === 'processar_texto') {
        $texto = trim((string)($_POST['texto'] ?? ''));
        if (mb_strlen($texto) < 50) { echo json_encode(array('error' => 'Texto muito curto', 'csrf' => $newCsrf)); exit; }
        if (mb_strlen($texto) > 80000) $texto = mb_substr($texto, 0, 80000, 'UTF-8');
        $contentBlocks[] = array('type' => 'text', 'text' =>
            "Abaixo está o texto colado de uma planilha de cálculo judicial. Extraia os dados em JSON:\n\n---\n" . $texto . "\n---\n\nRetorne APENAS o JSON.");
    }

    // Chamar Claude
    list($textContent, $apiData, $errClaude) = pc_chamar_claude($apiKey, $systemPrompt, $contentBlocks);
    if ($errClaude) {
        echo json_encode(array('error' => $errClaude, 'csrf' => $newCsrf));
        exit;
    }

    // Limpar possíveis markdown wrappers
    $textContent = trim($textContent);
    $textContent = preg_replace('/^```json\s*/i', '', $textContent);
    $textContent = preg_replace('/\s*```$/i', '', $textContent);

    $dados = json_decode($textContent, true);
    if (!$dados || !isset($dados['parcelas'])) {
        echo json_encode(array('error' => 'Dados extraídos inválidos. Resposta: ' . mb_substr($textContent, 0, 300, 'UTF-8'), 'csrf' => $newCsrf));
        exit;
    }

    // ── 2. Gerar XLSX com layout Ferreira & Sá ──
    require_once APP_ROOT . '/core/XLSXWriter.php';

    $writer = new XLSXWriter();

    // Estilos
    $headerStyle = array('font-style' => 'bold', 'fill' => '#052228', 'color' => '#FFFFFF', 'border' => 'left,right,top,bottom', 'font-size' => 11);
    $subHeaderStyle = array('font-style' => 'bold', 'fill' => '#B87333', 'color' => '#FFFFFF', 'border' => 'left,right,top,bottom', 'font-size' => 10);
    $normalStyle = array('border' => 'left,right,top,bottom', 'font-size' => 10);
    $moneyStyle = array('border' => 'left,right,top,bottom', 'font-size' => 10);
    $totalStyle = array('font-style' => 'bold', 'fill' => '#E8E0D5', 'border' => 'left,right,top,bottom', 'font-size' => 11);
    $grandTotalStyle = array('font-style' => 'bold', 'fill' => '#052228', 'color' => '#FFFFFF', 'border' => 'left,right,top,bottom', 'font-size' => 12);

    $meta = isset($dados['meta']) ? $dados['meta'] : array();

    // Sheet: Planilha de Débito
    $colTypes = array('string','string','string','money','money','money','money','money','string');
    $colHeaders = array('Nº', 'Descrição', 'Vencimento', 'Valor Nominal', 'Correção', 'Juros', 'Valor Atualizado', 'Pago', 'Obs.');
    $widths = array(6, 20, 14, 16, 16, 16, 18, 16, 20);

    $writer->writeSheetHeader('Planilha de Cálculo', array(
        'Nº' => 'string', 'Descrição' => 'string', 'Vencimento' => 'string',
        'Valor Nominal' => 'money', 'Correção' => 'money', 'Juros' => 'money',
        'Valor Atualizado' => 'money', 'Pago' => 'money', 'Obs.' => 'string'
    ), array('widths' => $widths, 'freeze_rows' => 1));

    // Metadados como linhas iniciais
    $metaStyle = array('font-style' => 'bold', 'font-size' => 10);
    if (isset($meta['titulo'])) $writer->writeSheetRow('Planilha de Cálculo', array('PLANILHA DE CÁLCULO: ' . $meta['titulo']), $metaStyle);
    if (isset($meta['processo'])) $writer->writeSheetRow('Planilha de Cálculo', array('Processo: ' . $meta['processo']), $metaStyle);
    if (isset($meta['autor'])) $writer->writeSheetRow('Planilha de Cálculo', array('Autor: ' . $meta['autor'] . '  |  Réu: ' . ($meta['reu'] ?? '')), $metaStyle);
    if (isset($meta['indice_correcao'])) $writer->writeSheetRow('Planilha de Cálculo', array('Índice: ' . $meta['indice_correcao'] . '  |  Juros: ' . ($meta['juros'] ?? '') . '  |  Data cálculo: ' . ($meta['data_calculo'] ?? '')), $metaStyle);
    $writer->writeSheetRow('Planilha de Cálculo', array(''));

    // Cabeçalho da tabela
    $writer->writeSheetRow('Planilha de Cálculo', $colHeaders, $headerStyle);

    // Parcelas
    foreach ($dados['parcelas'] as $p) {
        $row = array(
            isset($p['numero']) ? (string)$p['numero'] : '',
            isset($p['descricao']) ? $p['descricao'] : '',
            isset($p['vencimento']) ? $p['vencimento'] : '',
            isset($p['valor_nominal']) ? (float)$p['valor_nominal'] : 0,
            isset($p['correcao_monetaria']) ? (float)$p['correcao_monetaria'] : 0,
            isset($p['juros']) ? (float)$p['juros'] : 0,
            isset($p['valor_atualizado']) ? (float)$p['valor_atualizado'] : 0,
            isset($p['pago']) ? (float)$p['pago'] : 0,
            isset($p['observacao']) ? $p['observacao'] : '',
        );

        $rowStyle = $normalStyle;
        if ((float)($p['pago'] ?? 0) > 0) {
            $rowStyle = array_merge($normalStyle, array('font-style' => 'italic', 'color' => '#059669'));
        }
        if ((float)($p['valor_nominal'] ?? 0) < 0 || (isset($p['descricao']) && stripos($p['descricao'], 'pag') !== false)) {
            $rowStyle = array_merge($normalStyle, array('font-style' => 'italic', 'color' => '#DC2626'));
        }

        $writer->writeSheetRow('Planilha de Cálculo', $row, $rowStyle);
    }

    // Subtotais
    $sub = isset($dados['subtotais']) ? $dados['subtotais'] : array();
    $writer->writeSheetRow('Planilha de Cálculo', array(
        '', '', 'SUBTOTAIS',
        isset($sub['total_nominal']) ? (float)$sub['total_nominal'] : 0,
        isset($sub['total_correcao']) ? (float)$sub['total_correcao'] : 0,
        isset($sub['total_juros']) ? (float)$sub['total_juros'] : 0,
        isset($sub['total_atualizado']) ? (float)$sub['total_atualizado'] : 0,
        isset($sub['total_pago']) ? (float)$sub['total_pago'] : 0,
        '',
    ), $totalStyle);

    // Débito total
    $debitoTotal = isset($dados['debito_total']) ? (float)$dados['debito_total'] : 0;
    $writer->writeSheetRow('Planilha de Cálculo', array('', '', 'DÉBITO TOTAL', '', '', '', $debitoTotal, '', ''), $grandTotalStyle);

    // Honorários
    if (isset($dados['honorarios_valor']) && $dados['honorarios_valor'] > 0) {
        $writer->writeSheetRow('Planilha de Cálculo', array(
            '', '', 'Honorários (' . ($dados['honorarios_pct'] ?? '10') . '%)',
            '', '', '', (float)$dados['honorarios_valor'], '', ''
        ), $subHeaderStyle);
    }
    if (isset($dados['total_execucao']) && $dados['total_execucao'] > 0) {
        $writer->writeSheetRow('Planilha de Cálculo', array(
            '', '', 'TOTAL PARA EXECUÇÃO', '', '', '', (float)$dados['total_execucao'], '', ''
        ), $grandTotalStyle);
    }

    // Observações — sanitiza pra remover endereco pessoal/escritorio errado
    // que o Claude AI extrai do PDF Jusfy original e injeta o bloco correto.
    $obsRaw = isset($meta['observacoes']) ? $meta['observacoes'] : '';
    $obsParts = array();
    if ($obsRaw) {
        foreach (preg_split('/\s*\|\s*/', $obsRaw) as $p) {
            $p = trim($p);
            if ($p === '') continue;
            if (preg_match('/^(Escrit[óo]rio|Endere[çc]o)\s*:/i', $p)) continue;
            if (stripos($p, 'Jorge Gon') !== false) continue;
            $obsParts[] = $p;
        }
    }
    $obsParts[] = 'Escritório: Ferreira & Sá Advocacia — Rua Dr. Aldrovando de Oliveira, 140 — Ano Bom — Barra Mansa/RJ — Tel: (24) 99205-0096';
    $writer->writeSheetRow('Planilha de Cálculo', array(''));
    $writer->writeSheetRow('Planilha de Cálculo', array('Observações: ' . implode(' | ', $obsParts)), $metaStyle);

    // Rodapé
    $writer->writeSheetRow('Planilha de Cálculo', array(''));
    $writer->writeSheetRow('Planilha de Cálculo', array('Ferreira & Sá Advocacia — Portal Ferreira & Sá HUB — Gerado em ' . date('d/m/Y H:i')), array('font-size' => 8, 'color' => '#999999'));

    // Salvar arquivo
    $tempDir = APP_ROOT . '/temp';
    if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);
    $fileName = 'planilha_calculo_' . date('YmdHis') . '_' . current_user_id() . '.xlsx';
    $filePath = $tempDir . '/' . $fileName;
    $writer->writeToFile($filePath);

    // Salvar path relativo para download
    $xlsxRelPath = 'temp/' . $fileName;

    // ── 3. Salvar no banco ──
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS planilha_debito (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            titulo VARCHAR(200),
            case_id INT UNSIGNED DEFAULT NULL,
            client_id INT UNSIGNED DEFAULT NULL,
            dados_json LONGTEXT,
            xlsx_path VARCHAR(300),
            created_by INT UNSIGNED,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_case (case_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    $pdo->prepare(
        "INSERT INTO planilha_debito (titulo, case_id, client_id, dados_json, xlsx_path, created_by) VALUES (?,?,?,?,?,?)"
    )->execute(array(
        $titulo, $caseId, $clientId, json_encode($dados, JSON_UNESCAPED_UNICODE),
        $xlsxRelPath, current_user_id()
    ));
    $planilhaId = (int)$pdo->lastInsertId();

    audit_log('planilha_calculo_gerada', 'planilha_debito', $planilhaId, $titulo);

    // Tokens
    $tokensIn = isset($apiData['usage']['input_tokens']) ? (int)$apiData['usage']['input_tokens'] : 0;
    $tokensOut = isset($apiData['usage']['output_tokens']) ? (int)$apiData['usage']['output_tokens'] : 0;

    // Amanda 10/06/2026: confirma vinculo salvo pra mostrar na tela
    $vincTxt = '';
    if ($caseId) {
        $stmtVT = $pdo->prepare("SELECT cs.title, cl.name FROM cases cs LEFT JOIN clients cl ON cl.id = cs.client_id WHERE cs.id = ?");
        $stmtVT->execute(array($caseId));
        $vt = $stmtVT->fetch();
        if ($vt) $vincTxt = '⚖️ ' . $vt['title'] . ($vt['name'] ? ' · 👤 ' . $vt['name'] : '');
    } elseif ($clientId) {
        $stmtCV = $pdo->prepare("SELECT name FROM clients WHERE id = ?");
        $stmtCV->execute(array($clientId));
        $cnm = $stmtCV->fetchColumn();
        if ($cnm) $vincTxt = '👤 ' . $cnm;
    }
    if (!$vincTxt) {
        // Amanda 15/06/2026: diagnostico visivel quando der vazio
        $diag = array();
        if (isset($_POST['case_id']))    $diag[] = 'case_id="' . $_POST['case_id'] . '"';
        if (isset($_POST['client_id']))  $diag[] = 'client_id="' . $_POST['client_id'] . '"';
        if (isset($_POST['client_nome']))$diag[] = 'client_nome="' . mb_substr($_POST['client_nome'], 0, 50, 'UTF-8') . '"';
        if (isset($_POST['case_label'])) $diag[] = 'case_label="' . mb_substr($_POST['case_label'], 0, 50, 'UTF-8') . '"';
        $vincTxt = '⚠️ Sem vínculo. O navegador enviou: ' . implode(' · ', $diag);
    }

    echo json_encode(array(
        'ok' => true,
        'id' => $planilhaId,
        'xlsx_url' => url($xlsxRelPath),
        'total' => number_format($debitoTotal, 2, ',', '.'),
        'parcelas' => count($dados['parcelas']),
        'gerado_em' => date('d/m/Y H:i'),
        'tokens' => $tokensIn + $tokensOut,
        'csrf' => $newCsrf,
        'case_id_salvo' => $caseId,
        'client_id_salvo' => $clientId,
        'vinculo_txt' => $vincTxt,
    ));
    exit;
}

echo json_encode(array('error' => 'Ação inválida', 'csrf' => $newCsrf));
