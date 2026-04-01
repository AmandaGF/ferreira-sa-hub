<?php
/**
 * Fábrica de Petições — API
 * Chama API Anthropic (Claude Sonnet 4.6) e retorna HTML da petição
 */

// Capturar QUALQUER output (redirects, erros, etc.)
ob_start();

// Carregar dependências diretamente (sem middleware que faz redirect HTML)
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/functions.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/system_prompt.php';

// Descartar qualquer output dos requires
ob_end_clean();

// A partir daqui: SOMENTE JSON
header('Content-Type: application/json; charset=utf-8');

// Verificar login (sem redirect)
if (!is_logged_in()) {
    echo json_encode(array('error' => 'Sessão expirada. Recarregue a página (F5) e faça login.'));
    exit;
}

if (!has_min_role('gestao') && !has_role('cx') && !has_role('operacional')) {
    echo json_encode(array('error' => 'Sem permissão'));
    exit;
}

$pdo = db();
$action = $_POST['action'] ?? '';

if ($action === 'gerar') {
    $caseId = (int)($_POST['case_id'] ?? 0);
    $tipoPeca = clean_str($_POST['tipo_peca'] ?? '', 80);
    $tipoAcao = clean_str($_POST['tipo_acao'] ?? '', 80);

    // Nome do cliente é obrigatório (vem do formulário)
    $clNome = trim($_POST['cl_nome'] ?? '');

    if (!$tipoPeca || !$tipoAcao) {
        echo json_encode(array('error' => 'Selecione o tipo de ação e o tipo de peça.'));
        exit;
    }
    if (!$clNome) {
        echo json_encode(array('error' => 'Preencha ao menos o nome do cliente.'));
        exit;
    }

    // Buscar dados do caso (se vinculado)
    $caso = null;
    $clientId = 0;
    if ($caseId) {
        $stmt = $pdo->prepare(
            "SELECT cs.*, cl.name as client_name, cl.id as client_id, cl.pix_key, cl.children_names
             FROM cases cs LEFT JOIN clients cl ON cl.id = cs.client_id WHERE cs.id = ?"
        );
        $stmt->execute(array($caseId));
        $caso = $stmt->fetch();
        if ($caso) $clientId = (int)($caso['client_id'] ?: 0);
    }

    // ══ OTIMIZAÇÃO 3: Dados do cliente em formato compacto (JSON) ══
    $clCpf = trim($_POST['cl_cpf'] ?? '');
    $clRg = trim($_POST['cl_rg'] ?? '');
    $clNascimento = trim($_POST['cl_nascimento'] ?? '');
    $clProfissao = trim($_POST['cl_profissao'] ?? '');
    $clEstadoCivil = trim($_POST['cl_estado_civil'] ?? '');
    $clEndereco = trim($_POST['cl_endereco'] ?? '');
    $clCidade = trim($_POST['cl_cidade'] ?? '');
    $clCep = trim($_POST['cl_cep'] ?? '');
    $clTelefone = trim($_POST['cl_telefone'] ?? '');
    $clEmail = trim($_POST['cl_email'] ?? '');

    $dadosClienteArr = array(
        'nome' => $clNome ?: '[NÃO INFORMADO]',
        'cpf' => $clCpf ?: '[NÃO INFORMADO]',
        'rg' => $clRg ?: '[NÃO INFORMADO]',
        'nascimento' => $clNascimento ? date('d/m/Y', strtotime($clNascimento)) : '[NÃO INFORMADO]',
        'profissao' => $clProfissao ?: '[NÃO INFORMADO]',
        'estado_civil' => $clEstadoCivil ?: '[NÃO INFORMADO]',
        'endereco' => $clEndereco ?: '[NÃO INFORMADO]',
        'cidade_uf' => $clCidade ?: '[NÃO INFORMADO]',
        'cep' => $clCep ?: '[NÃO INFORMADO]',
        'telefone' => $clTelefone ?: '[NÃO INFORMADO]',
        'email' => $clEmail ?: '[NÃO INFORMADO]',
    );
    if ($caso && $caso['pix_key']) $dadosClienteArr['pix'] = $caso['pix_key'];
    if ($caso && $caso['children_names']) $dadosClienteArr['filhos'] = $caso['children_names'];

    $dadosCliente = json_encode($dadosClienteArr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    // Campos específicos da ação
    $camposEspecificos = '';
    $campos = get_campos_acao($tipoAcao);
    foreach ($campos as $campo) {
        $valor = $_POST[$campo['name']] ?? '';
        if ($valor) {
            $camposEspecificos .= $campo['label'] . ': ' . $valor . "\n";
        }
    }

    // SEMPRE capturar observações (campo genérico que aparece para todos os tipos)
    $obsGeral = trim($_POST['observacoes_caso'] ?? '');
    if ($obsGeral && strpos($camposEspecificos, $obsGeral) === false) {
        $camposEspecificos .= "Observações do caso: " . $obsGeral . "\n";
    }

    // Campos extras de peças intercorrentes (juntada, ciência)
    $camposIntercorrentes = array(
        'numero_processo' => 'Nº do processo',
        'vara_juizo' => 'Vara / Juízo',
        'lista_documentos' => 'Documentos a juntar',
        'justificativa_juntada' => 'Justificativa da juntada',
        'objeto_ciencia' => 'Objeto da ciência',
        'reserva_manifestacao' => 'Reservar manifestação posterior',
    );
    foreach ($camposIntercorrentes as $key => $label) {
        $val = trim($_POST[$key] ?? '');
        if ($val) {
            $camposEspecificos .= $label . ': ' . $val . "\n";
        }
    }

    // Opções processuais fixas
    $opGratuidade = trim($_POST['op_gratuidade'] ?? 'sim');
    $opAudiencia = trim($_POST['op_audiencia'] ?? 'dispensar');
    $opTutela = trim($_POST['op_tutela'] ?? 'nao');
    $opTutelaDesc = trim($_POST['op_tutela_desc'] ?? '');

    $opcoesProc = '';
    if ($opGratuidade === 'sim') {
        $opcoesProc .= "GRATUIDADE DE JUSTIÇA: SIM — Incluir seção 'DA GRATUIDADE DE JUSTIÇA' e indicação à direita.\n";
    } else {
        $opcoesProc .= "GRATUIDADE DE JUSTIÇA: NÃO — Não incluir seção de gratuidade nem indicação.\n";
    }

    if ($opAudiencia === 'dispensar') {
        $opcoesProc .= "AUDIÊNCIA: DISPENSAR — Pedir a dispensa de audiência de conciliação/mediação prévia, considerando o grau de litigiosidade. Caso indeferida a dispensa, requerer que a sessão seja designada de forma remota, via CEJUSC, com mediador especializado em conflitos familiares, com fundamento na Lei n. 13.140/2015 e no art. 165, §3º, do CPC.\n";
    } else {
        $opcoesProc .= "AUDIÊNCIA: SIM, REMOTA — Requerer a designação de sessão de mediação/conciliação de forma remota, via CEJUSC, em razão da adoção do Juízo 100% Digital (Resolução 385/2021 do CNJ), com mediador especializado, com fundamento na Lei n. 13.140/2015 e no art. 165, §3º, do CPC.\n";
    }

    $tutelaLabels = array(
        'urgencia_antecipada' => 'Tutela provisória de urgência antecipada (art. 300 CPC)',
        'cautelar' => 'Tutela cautelar (art. 305 CPC)',
        'evidencia' => 'Tutela de evidência (art. 311 CPC)',
    );
    if ($opTutela !== 'nao' && isset($tutelaLabels[$opTutela])) {
        $opcoesProc .= "TUTELA PROVISÓRIA: SIM — " . $tutelaLabels[$opTutela] . "\n";
        if ($opTutelaDesc) {
            $opcoesProc .= "PEDIDO DE TUTELA: " . $opTutelaDesc . "\n";
            $opcoesProc .= "IMPORTANTE: Elabore uma seção específica 'DA TUTELA PROVISÓRIA' com fundamentação jurídica robusta para o pedido descrito acima. Demonstre a probabilidade do direito e o perigo de dano/risco ao resultado útil do processo. Inclua o pedido de tutela nos pedidos finais.\n";
        }
    } else {
        $opcoesProc .= "TUTELA PROVISÓRIA: NÃO — Não pedir tutela.\n";
    }

    // Recursos visuais solicitados
    $visuais = array();
    $visuaisMap = array(
        'visual_tabela_despesas' => 'Inclua uma TABELA DE DESPESAS MENSAIS ESSENCIAIS detalhada com categorias (Alimentação, Educação, Saúde, Vestuário, Lazer, Moradia, Transporte, Necessidades Diversas), descrição e valor mensal estimado. Header fundo #052228 texto branco, linhas alternadas #FFFFFF/#F4F4F4. Rodapé com total e fonte.',
        'visual_linha_tempo' => 'Inclua uma LINHA DO TEMPO visual dos fatos em formato de tabela com 3 colunas: marcador (cor #B87333), data (bold #052228) e evento. Linhas alternadas #FFFFFF/#F4F4F4. Borda esquerda #D7AB90 na coluna de eventos.',
        'visual_tabela_comparativa' => 'Inclua uma TABELA COMPARATIVA com duas ou mais colunas comparando situações (ex: antes x depois, autor x réu, necessidades x possibilidades). Header fundo #052228 texto branco.',
        'visual_tabela_convivencia' => 'Inclua uma TABELA DE REGIME DE CONVIVÊNCIA com colunas: Tipo de Convivência, Frequência, Horário/Regra, Pernoite. Header fundo #052228 texto branco, linhas alternadas.',
        'visual_tabela_alimentos' => 'Inclua uma TABELA DE CÁLCULO DE ALIMENTOS mostrando: base de cálculo, percentual, valor resultante, incidência sobre 13º/férias/FGTS. Header fundo #052228 texto branco.',
        'visual_tabela_bens' => 'Inclua uma TABELA DE PARTILHA DE BENS com colunas: Bem, Descrição, Valor Estimado, Proposta de Partilha. Header fundo #052228 texto branco, linhas alternadas.',
    );
    foreach ($visuaisMap as $key => $instrucao) {
        if (!empty($_POST[$key])) {
            $visuais[] = $instrucao;
        }
    }
    $instrucaoVisuais = '';
    if (!empty($visuais)) {
        $instrucaoVisuais = "\n\nRECURSOS VISUAIS SOLICITADOS (incluir obrigatoriamente):\n" . implode("\n", $visuais);
    }

    // Labels
    $tiposAcao = get_tipos_acao();
    $tiposPeca = get_tipos_peca();
    $labelAcao = $tiposAcao[$tipoAcao] ?? $tipoAcao;
    $labelPeca = $tiposPeca[$tipoPeca] ?? $tipoPeca;

    // Extrair cidade/UF do campo cl_cidade (formato "Cidade/UF")
    $comarcaCidade = 'Barra Mansa';
    $comarcaUF = 'RJ';
    if ($clCidade && strpos($clCidade, '/') !== false) {
        $parts = explode('/', $clCidade);
        $comarcaCidade = trim($parts[0]) ?: 'Barra Mansa';
        $comarcaUF = trim($parts[1]) ?: 'RJ';
    }

    // ══ PROMPT FIXO — Skill dra-amanda ══
    $promptFixo = <<<'FIXO'
[INSTRUÇÃO OBRIGATÓRIA — SKILL DRA-AMANDA]
Você está operando como a skill "dra-amanda" do escritório Ferreira & Sá Advocacia Especializada.
Siga RIGOROSAMENTE todas as regras do system prompt: princípios inegociáveis, estrutura da petição,
visual law (HTML com estilos inline), qualificação das partes, seções fixas e referências jurídicas.
[FIM DA INSTRUÇÃO OBRIGATÓRIA]
FIXO;

    // Montar prompt do usuário
    $userPrompt = $promptFixo . "\n\n";
    $userPrompt .= "Tipo de peça: $labelPeca\nTipo de ação: $labelAcao\n\n";
    $userPrompt .= "DADOS DO CLIENTE (PARTE AUTORA):\n$dadosCliente\n\n";
    $userPrompt .= "OPÇÕES PROCESSUAIS:\n$opcoesProc\n";
    $userPrompt .= "DADOS ESPECÍFICOS DA AÇÃO:\n$camposEspecificos\n\n";
    $userPrompt .= "Data atual: " . date('d/m/Y') . "\n";
    $userPrompt .= "Comarca: " . $comarcaCidade . '/' . $comarcaUF . "\n\n";
    $userPrompt .= "Elabore a petição completa em HTML.\n\n";
    $userPrompt .= "REGRAS DE OUTPUT OBRIGATÓRIAS:\n";
    $userPrompt .= "- Retorne SOMENTE o HTML puro, SEM markdown, SEM ```html, SEM ```\n";
    $userPrompt .= "- NÃO use tags <html>, <head>, <body>, <style>, <!DOCTYPE>\n";
    $userPrompt .= "- Use APENAS estilos inline (style=\"...\") em cada elemento\n";
    $userPrompt .= "- NÃO gere timbrado/logo nem rodapé — o papel timbrado é aplicado como fundo pela plataforma\n";
    $userPrompt .= "- Comece DIRETO pelo endereçamento ao Juízo\n";
    $userPrompt .= "- Fonte: Calibri,sans-serif (NUNCA Times New Roman)\n";
    $userPrompt .= "- Siga EXATAMENTE os templates HTML do system prompt (caixa da ação, seções à direita, pedidos em tabela)\n";
    $userPrompt .= "- A petição deve ser COMPLETA: endereçamento, indicações, qualificação, caixa da ação, todas as seções, intimações, provas, assinatura\n";
    $userPrompt .= "- NUNCA corte ou interrompa. Seja conciso se necessário, mas inclua TODAS as seções até a assinatura\n";
    if ($instrucaoVisuais) {
        $userPrompt .= $instrucaoVisuais . "\n";
    }

    // ══ Pré-processamento de documentos ══
    $contentBlocks = array();
    $anexosInfo = '';
    if (!empty($_FILES['anexos']['name'][0])) {
        $tiposAceitos = array(
            'application/pdf' => 'document',
            'image/jpeg' => 'image',
            'image/png' => 'image',
            'image/webp' => 'image',
        );
        $mediaTypes = array(
            'application/pdf' => 'application/pdf',
            'image/jpeg' => 'image/jpeg',
            'image/png' => 'image/png',
            'image/webp' => 'image/webp',
        );
        $maxFiles = 10;
        $maxSize = 5 * 1024 * 1024;
        $count = min(count($_FILES['anexos']['name']), $maxFiles);

        for ($i = 0; $i < $count; $i++) {
            if ($_FILES['anexos']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $mime = $_FILES['anexos']['type'][$i];
            $tmpPath = $_FILES['anexos']['tmp_name'][$i];
            $fileName = $_FILES['anexos']['name'][$i];
            $fileSize = $_FILES['anexos']['size'][$i];

            if (!isset($tiposAceitos[$mime]) || $fileSize > $maxSize) continue;

            $rawContent = file_get_contents($tmpPath);
            $base64 = base64_encode($rawContent);
            $blockType = $tiposAceitos[$mime];

            if ($blockType === 'document') {
                $contentBlocks[] = array(
                    'type' => 'document',
                    'source' => array(
                        'type' => 'base64',
                        'media_type' => 'application/pdf',
                        'data' => $base64,
                    ),
                );
            } else {
                $contentBlocks[] = array(
                    'type' => 'image',
                    'source' => array(
                        'type' => 'base64',
                        'media_type' => $mediaTypes[$mime],
                        'data' => $base64,
                    ),
                );
            }
            $anexosInfo .= "\n- " . $fileName . ' (' . round($fileSize / 1024) . ' KB)';
        }

        if ($anexosInfo) {
            $userPrompt .= "\n\nDOCUMENTOS ANEXADOS PARA ANÁLISE:" . $anexosInfo;
            $userPrompt .= "\nExtraia apenas as informações relevantes dos documentos para fundamentar a petição.";
        }
    }

    // Montar conteúdo da mensagem (texto + anexos)
    $messageContent = array();
    foreach ($contentBlocks as $block) {
        $messageContent[] = $block;
    }
    $messageContent[] = array('type' => 'text', 'text' => $userPrompt);

    // Chamar API Anthropic
    $apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
    if (!$apiKey) {
        echo json_encode(array('error' => 'ANTHROPIC_API_KEY não configurada. Configure no config.php.'));
        exit;
    }

    // ══ OTIMIZAÇÃO 1: Prompt Caching ══
    $systemPromptText = get_system_prompt();
    $payload = json_encode(array(
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 16384,
        'temperature' => 0.3,
        'system' => array(
            array(
                'type' => 'text',
                'text' => $systemPromptText,
                'cache_control' => array('type' => 'ephemeral'),
            )
        ),
        'messages' => array(
            array('role' => 'user', 'content' => $messageContent)
        ),
    ));

    // ══ Retry automático (overloaded = 529, rate limit = 429) ══
    $maxTentativas = 3;
    $response = '';
    $httpCode = 0;
    $error = '';
    $data = null;

    for ($tentativa = 0; $tentativa < $maxTentativas; $tentativa++) {
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
            CURLOPT_TIMEOUT => 200,
            CURLOPT_SSL_VERIFYPEER => false,
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 529 && $httpCode !== 429) break;

        if ($tentativa < $maxTentativas - 1) {
            sleep(pow(2, $tentativa) * 2);
        }
    }

    if ($error) {
        echo json_encode(array('error' => 'Erro de conexão com a API. Verifique sua internet e tente novamente.'));
        exit;
    }

    $data = json_decode($response, true);
    if ($data === null && $httpCode === 200) {
        echo json_encode(array('error' => 'Resposta inválida da API (JSON malformado). Tente novamente.'));
        exit;
    }
    if (!is_array($data)) $data = array();

    // Salvar log do retorno bruto
    $logDir = __DIR__ . '/../../uploads';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    @file_put_contents($logDir . '/peticao_last_response.json', json_encode(array(
        'timestamp' => date('Y-m-d H:i:s'),
        'http_code' => $httpCode,
        'tentativas' => $tentativa + 1,
        'response_raw' => $data,
        'payload_user_prompt' => $userPrompt,
    ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    if ($httpCode === 529 || $httpCode === 429) {
        echo json_encode(array(
            'error' => 'A API está momentaneamente sobrecarregada. Aguarde alguns segundos e tente novamente.',
            'retry' => true,
        ));
        exit;
    }

    if ($httpCode !== 200 || !isset($data['content'][0]['text'])) {
        $errMsg = isset($data['error']['message']) ? $data['error']['message'] : '';
        if ($httpCode === 401) {
            $errMsg = 'Chave da API inválida ou expirada. Verifique a configuração.';
        } elseif ($httpCode === 400) {
            $errMsg = 'Erro nos dados enviados: ' . $errMsg;
        } elseif (!$errMsg) {
            $errMsg = 'Erro inesperado (HTTP ' . $httpCode . '). Tente novamente.';
        }
        echo json_encode(array('error' => $errMsg));
        exit;
    }

    $htmlPeticao = $data['content'][0]['text'];

    // Limpar output: remover code blocks markdown e tags HTML externas
    $htmlPeticao = preg_replace('/^```html?\s*/i', '', $htmlPeticao);
    $htmlPeticao = preg_replace('/\s*```\s*$/', '', $htmlPeticao);
    $htmlPeticao = preg_replace('/<!(DOCTYPE|doctype)[^>]*>/', '', $htmlPeticao);
    $htmlPeticao = preg_replace('/<\/?html[^>]*>/', '', $htmlPeticao);
    $htmlPeticao = preg_replace('/<head>.*?<\/head>/s', '', $htmlPeticao);
    $htmlPeticao = preg_replace('/<\/?body[^>]*>/', '', $htmlPeticao);
    $htmlPeticao = preg_replace('/<style[^>]*>.*?<\/style>/s', '', $htmlPeticao);
    $htmlPeticao = trim($htmlPeticao);

    $tokensIn = isset($data['usage']['input_tokens']) ? (int)$data['usage']['input_tokens'] : 0;
    $tokensOut = isset($data['usage']['output_tokens']) ? (int)$data['usage']['output_tokens'] : 0;
    $tokensCacheRead = isset($data['usage']['cache_read_input_tokens']) ? (int)$data['usage']['cache_read_input_tokens'] : 0;
    $tokensCacheCreate = isset($data['usage']['cache_creation_input_tokens']) ? (int)$data['usage']['cache_creation_input_tokens'] : 0;

    // Custo
    $custoUsd = (($tokensIn - $tokensCacheRead) * 3 / 1000000)
              + ($tokensCacheRead * 0.30 / 1000000)
              + ($tokensCacheCreate * 3.75 / 1000000)
              + ($tokensOut * 15 / 1000000);

    // Salvar no banco
    $titulo = $labelPeca . ' — ' . $labelAcao . ' — ' . ($clNome ?: 'Sem cliente');
    $stmt = $pdo->prepare(
        "INSERT INTO case_documents (case_id, client_id, tipo_peca, tipo_acao, titulo, conteudo_html, gerado_por, tokens_input, tokens_output, custo_usd)
         VALUES (?,?,?,?,?,?,?,?,?,?)"
    );
    $stmt->execute(array(
        $caseId ?: 0, $clientId, $tipoPeca, $tipoAcao,
        $titulo, $htmlPeticao, current_user_id(),
        $tokensIn, $tokensOut, $custoUsd
    ));
    $docId = (int)$pdo->lastInsertId();

    audit_log('PETICAO_GERADA', 'case', $caseId ?: 0, $titulo);

    echo json_encode(array(
        'ok' => true,
        'doc_id' => $docId,
        'titulo' => $titulo,
        'html' => $htmlPeticao,
        'tokens_in' => $tokensIn,
        'tokens_out' => $tokensOut,
        'custo' => number_format($custoUsd, 4),
    ));
    exit;
}

echo json_encode(array('error' => 'Ação inválida'));
