<?php
/**
 * Planilha de Débito — API
 * Recebe PDF → Claude AI extrai dados → Gera XLSX com layout FeS
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

if ($action === 'processar_pdf') {
    $pdfBase64 = $_POST['pdf_base64'] ?? '';
    $pdfName = clean_str($_POST['pdf_name'] ?? 'planilha.pdf', 200);
    $caseId = (int)($_POST['case_id'] ?? 0) ?: null;
    $titulo = clean_str($_POST['titulo'] ?? '', 200) ?: $pdfName;

    if (!$pdfBase64) {
        echo json_encode(array('error' => 'PDF não enviado', 'csrf' => $newCsrf));
        exit;
    }

    // Buscar client_id pelo case
    $clientId = null;
    if ($caseId) {
        $stmtC = $pdo->prepare("SELECT client_id FROM cases WHERE id = ?");
        $stmtC->execute(array($caseId));
        $clientId = (int)$stmtC->fetchColumn() ?: null;
    }

    // ── 1. Enviar PDF para Claude AI extrair dados ──
    $apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
    if (!$apiKey) {
        echo json_encode(array('error' => 'ANTHROPIC_API_KEY não configurada', 'csrf' => $newCsrf));
        exit;
    }

    $systemPrompt = 'Você é um assistente especializado em cálculos judiciais e planilhas de débito. '
        . 'Você recebe um PDF de uma planilha de débito (geralmente do sistema Jusfy ou similar) e deve extrair TODOS os dados em formato JSON estruturado. '
        . 'Retorne APENAS o JSON, sem markdown, sem explicações, sem ```json```. '
        . 'O JSON deve ter esta estrutura exata: '
        . '{"meta":{"titulo":"...","processo":"nº do processo","vara":"...","autor":"...","reu":"...","indice_correcao":"INPC ou IPCA-E etc","juros":"1% ao mês etc","data_calculo":"dd/mm/yyyy","observacoes":"..."},'
        . '"parcelas":[{"numero":1,"descricao":"Jan/2024","vencimento":"01/01/2024","valor_nominal":1500.00,"correcao_monetaria":150.30,"juros":45.20,"valor_atualizado":1695.50,"pago":0,"observacao":""}],'
        . '"subtotais":{"total_nominal":0,"total_correcao":0,"total_juros":0,"total_atualizado":0,"total_pago":0},'
        . '"debito_total":0,"honorarios_pct":0,"honorarios_valor":0,"total_execucao":0}';

    $payload = json_encode(array(
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 16384,
        'temperature' => 0,
        'system' => $systemPrompt,
        'messages' => array(
            array('role' => 'user', 'content' => array(
                array('type' => 'document', 'source' => array(
                    'type' => 'base64',
                    'media_type' => 'application/pdf',
                    'data' => $pdfBase64,
                )),
                array('type' => 'text', 'text' => 'Extraia todos os dados desta planilha de débito e retorne em JSON. Inclua TODAS as parcelas, valores, correções, juros e totais. Se houver pagamentos parciais, inclua como valor negativo ou no campo "pago". Retorne APENAS o JSON.'),
            )),
        ),
    ), JSON_UNESCAPED_UNICODE);

    // Chamar Claude API
    $response = '';
    $httpCode = 0;
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

        if ($curlErr) {
            if ($tentativa < 2) { sleep(2); continue; }
            echo json_encode(array('error' => 'Erro de rede: ' . $curlErr, 'csrf' => $newCsrf));
            exit;
        }
        if ($httpCode !== 529 && $httpCode !== 429) break;
        sleep(pow(2, $tentativa) * 2);
    }

    if ($httpCode >= 400) {
        $errData = json_decode($response, true);
        $errMsg = isset($errData['error']['message']) ? $errData['error']['message'] : 'HTTP ' . $httpCode;
        echo json_encode(array('error' => 'Claude API: ' . $errMsg, 'csrf' => $newCsrf));
        exit;
    }

    $apiData = json_decode($response, true);
    $textContent = '';
    if (isset($apiData['content'])) {
        foreach ($apiData['content'] as $block) {
            if ($block['type'] === 'text') { $textContent .= $block['text']; break; }
        }
    }

    if (!$textContent) {
        echo json_encode(array('error' => 'Claude não retornou dados', 'csrf' => $newCsrf));
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

    $writer->writeSheetHeader('Planilha de Débito', array(
        'Nº' => 'string', 'Descrição' => 'string', 'Vencimento' => 'string',
        'Valor Nominal' => 'money', 'Correção' => 'money', 'Juros' => 'money',
        'Valor Atualizado' => 'money', 'Pago' => 'money', 'Obs.' => 'string'
    ), array('widths' => $widths, 'freeze_rows' => 1));

    // Metadados como linhas iniciais
    $metaStyle = array('font-style' => 'bold', 'font-size' => 10);
    if (isset($meta['titulo'])) $writer->writeSheetRow('Planilha de Débito', array('PLANILHA DE DÉBITO: ' . $meta['titulo']), $metaStyle);
    if (isset($meta['processo'])) $writer->writeSheetRow('Planilha de Débito', array('Processo: ' . $meta['processo']), $metaStyle);
    if (isset($meta['autor'])) $writer->writeSheetRow('Planilha de Débito', array('Autor: ' . $meta['autor'] . '  |  Réu: ' . ($meta['reu'] ?? '')), $metaStyle);
    if (isset($meta['indice_correcao'])) $writer->writeSheetRow('Planilha de Débito', array('Índice: ' . $meta['indice_correcao'] . '  |  Juros: ' . ($meta['juros'] ?? '') . '  |  Data cálculo: ' . ($meta['data_calculo'] ?? '')), $metaStyle);
    $writer->writeSheetRow('Planilha de Débito', array(''));

    // Cabeçalho da tabela
    $writer->writeSheetRow('Planilha de Débito', $colHeaders, $headerStyle);

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

        $writer->writeSheetRow('Planilha de Débito', $row, $rowStyle);
    }

    // Subtotais
    $sub = isset($dados['subtotais']) ? $dados['subtotais'] : array();
    $writer->writeSheetRow('Planilha de Débito', array(
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
    $writer->writeSheetRow('Planilha de Débito', array('', '', 'DÉBITO TOTAL', '', '', '', $debitoTotal, '', ''), $grandTotalStyle);

    // Honorários
    if (isset($dados['honorarios_valor']) && $dados['honorarios_valor'] > 0) {
        $writer->writeSheetRow('Planilha de Débito', array(
            '', '', 'Honorários (' . ($dados['honorarios_pct'] ?? '10') . '%)',
            '', '', '', (float)$dados['honorarios_valor'], '', ''
        ), $subHeaderStyle);
    }
    if (isset($dados['total_execucao']) && $dados['total_execucao'] > 0) {
        $writer->writeSheetRow('Planilha de Débito', array(
            '', '', 'TOTAL PARA EXECUÇÃO', '', '', '', (float)$dados['total_execucao'], '', ''
        ), $grandTotalStyle);
    }

    // Observações
    if (isset($meta['observacoes']) && $meta['observacoes']) {
        $writer->writeSheetRow('Planilha de Débito', array(''));
        $writer->writeSheetRow('Planilha de Débito', array('Observações: ' . $meta['observacoes']), $metaStyle);
    }

    // Rodapé
    $writer->writeSheetRow('Planilha de Débito', array(''));
    $writer->writeSheetRow('Planilha de Débito', array('Ferreira & Sá Advocacia — Portal Ferreira & Sá HUB — Gerado em ' . date('d/m/Y H:i')), array('font-size' => 8, 'color' => '#999999'));

    // Salvar arquivo
    $tempDir = APP_ROOT . '/temp';
    if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);
    $fileName = 'planilha_debito_' . date('YmdHis') . '_' . current_user_id() . '.xlsx';
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

    audit_log('planilha_debito_gerada', 'planilha_debito', $planilhaId, $titulo);

    // Tokens
    $tokensIn = isset($apiData['usage']['input_tokens']) ? (int)$apiData['usage']['input_tokens'] : 0;
    $tokensOut = isset($apiData['usage']['output_tokens']) ? (int)$apiData['usage']['output_tokens'] : 0;

    echo json_encode(array(
        'ok' => true,
        'id' => $planilhaId,
        'xlsx_url' => url($xlsxRelPath),
        'total' => number_format($debitoTotal, 2, ',', '.'),
        'parcelas' => count($dados['parcelas']),
        'gerado_em' => date('d/m/Y H:i'),
        'tokens' => $tokensIn + $tokensOut,
        'csrf' => $newCsrf,
    ));
    exit;
}

echo json_encode(array('error' => 'Ação inválida', 'csrf' => $newCsrf));
