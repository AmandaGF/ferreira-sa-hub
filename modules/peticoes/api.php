<?php
/**
 * Fábrica de Petições — API
 * Chama API Anthropic (Claude Sonnet 4.6) e retorna HTML da petição
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

if (!has_min_role('gestao') && !has_role('cx') && !has_role('operacional')) {
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'Sem permissão'));
    exit;
}

require_once __DIR__ . '/system_prompt.php';

header('Content-Type: application/json; charset=utf-8');

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

    // Labels
    $tiposAcao = get_tipos_acao();
    $tiposPeca = get_tipos_peca();
    $labelAcao = $tiposAcao[$tipoAcao] ?? $tipoAcao;
    $labelPeca = $tiposPeca[$tipoPeca] ?? $tipoPeca;

    // Montar prompt do usuário
    $userPrompt = "Tipo de peça: $labelPeca\nTipo de ação: $labelAcao\n\n";
    $userPrompt .= "DADOS DO CLIENTE (PARTE AUTORA):\n$dadosCliente\n\n";
    $userPrompt .= "DADOS ESPECÍFICOS DA AÇÃO:\n$camposEspecificos\n\n";
    // Extrair cidade/UF do campo cl_cidade (formato "Cidade/UF")
    $comarcaCidade = 'Barra Mansa';
    $comarcaUF = 'RJ';
    if ($clCidade && strpos($clCidade, '/') !== false) {
        $parts = explode('/', $clCidade);
        $comarcaCidade = trim($parts[0]) ?: 'Barra Mansa';
        $comarcaUF = trim($parts[1]) ?: 'RJ';
    }

    $userPrompt .= "Data atual: " . date('d/m/Y') . "\n";
    $userPrompt .= "Comarca: " . $comarcaCidade . '/' . $comarcaUF . "\n\n";
    $userPrompt .= "Elabore a petição completa em HTML formatado.";

    // ══ OTIMIZAÇÃO 2: Pré-processamento de documentos ══
    // PDFs e imagens limitados a 5MB cada, máx 10 arquivos
    // PDFs são enviados como document (Claude lê nativamente)
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
        $maxSize = 5 * 1024 * 1024; // 5MB por arquivo (otimizado vs 10MB)
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
    // Anexos primeiro, depois o texto
    foreach ($contentBlocks as $block) {
        $messageContent[] = $block;
    }
    $messageContent[] = array('type' => 'text', 'text' => $userPrompt);

    // ══ OTIMIZAÇÃO 4: Cache de petições recentes (mesmo dia, mesmos dados) ══
    $cacheKey = md5($tipoAcao . $tipoPeca . $clNome . $clientId . date('Y-m-d'));
    $cacheDir = sys_get_temp_dir();
    $cacheFile = $cacheDir . '/peticao_cache_' . $cacheKey . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached && isset($cached['html'])) {
            // Salvar no banco mesmo usando cache
            $titulo = $labelPeca . ' — ' . $labelAcao . ' — ' . ($clNome ?: 'Sem cliente');
            $stmt = $pdo->prepare(
                "INSERT INTO case_documents (case_id, client_id, tipo_peca, tipo_acao, titulo, conteudo_html, gerado_por, tokens_input, tokens_output, custo_usd)
                 VALUES (?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->execute(array($caseId ?: 0, $clientId, $tipoPeca, $tipoAcao, $titulo, $cached['html'], current_user_id(), 0, 0, 0));
            $docId = (int)$pdo->lastInsertId();
            audit_log('PETICAO_GERADA', 'case', $caseId ?: 0, $titulo . ' (cache)');
            echo json_encode(array(
                'ok' => true, 'doc_id' => $docId, 'titulo' => $titulo,
                'html' => $cached['html'], 'tokens_in' => 0, 'tokens_out' => 0,
                'custo' => '0.0000', 'cache' => true,
            ));
            exit;
        }
    }

    // Chamar API Anthropic
    $apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
    if (!$apiKey) {
        echo json_encode(array('error' => 'ANTHROPIC_API_KEY não configurada. Configure no config.php.'));
        exit;
    }

    // ══ OTIMIZAÇÃO 1: Prompt Caching — cache_control: ephemeral no system prompt ══
    // O system prompt (~3.500 tokens) é cacheado pela Anthropic por 5min,
    // reduzindo custo em ~90% nas chamadas subsequentes.
    // ══ OTIMIZAÇÃO 5: temperature 0.3, max_tokens 4096 ══
    $payload = json_encode(array(
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 4096,
        'temperature' => 0.3,
        'system' => array(
            array(
                'type' => 'text',
                'text' => get_system_prompt(),
                'cache_control' => array('type' => 'ephemeral'),
            )
        ),
        'messages' => array(
            array('role' => 'user', 'content' => $messageContent)
        ),
    ));

    // ══ Retry automático com espera progressiva (overloaded = 529, rate limit = 429) ══
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
            CURLOPT_TIMEOUT => 180,
            CURLOPT_SSL_VERIFYPEER => false,
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Se não é erro de sobrecarga/rate limit, sair do loop
        if ($httpCode !== 529 && $httpCode !== 429) break;

        // Espera progressiva: 2s, 4s, 8s
        if ($tentativa < $maxTentativas - 1) {
            sleep(pow(2, $tentativa) * 2);
        }
    }

    if ($error) {
        echo json_encode(array('error' => 'Erro de conexão com a API. Verifique sua internet e tente novamente.'));
        exit;
    }

    $data = json_decode($response, true);

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
    $tokensIn = isset($data['usage']['input_tokens']) ? (int)$data['usage']['input_tokens'] : 0;
    $tokensOut = isset($data['usage']['output_tokens']) ? (int)$data['usage']['output_tokens'] : 0;
    $tokensCacheRead = isset($data['usage']['cache_read_input_tokens']) ? (int)$data['usage']['cache_read_input_tokens'] : 0;
    $tokensCacheCreate = isset($data['usage']['cache_creation_input_tokens']) ? (int)$data['usage']['cache_creation_input_tokens'] : 0;

    // Custo: input normal $3/MTok, cache read $0.30/MTok, cache create $3.75/MTok, output $15/MTok
    $custoUsd = (($tokensIn - $tokensCacheRead) * 3 / 1000000)
              + ($tokensCacheRead * 0.30 / 1000000)
              + ($tokensCacheCreate * 3.75 / 1000000)
              + ($tokensOut * 15 / 1000000);

    // ══ OTIMIZAÇÃO 4: Salvar no cache local ══
    $cacheData = array('html' => $htmlPeticao, 'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut);
    @file_put_contents($cacheFile, json_encode($cacheData));

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

    audit_log('PETICAO_GERADA', 'case', $caseId, $titulo);

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
