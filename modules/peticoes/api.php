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

    if (!$caseId || !$tipoPeca || !$tipoAcao) {
        echo json_encode(array('error' => 'Dados incompletos'));
        exit;
    }

    // Buscar dados do caso e cliente
    $stmt = $pdo->prepare(
        "SELECT cs.*, cl.name as client_name, cl.cpf, cl.rg, cl.birth_date,
                cl.address_street, cl.address_city, cl.address_state, cl.address_zip,
                cl.profession, cl.marital_status, cl.gender, cl.email as client_email,
                cl.phone as client_phone, cl.pix_key, cl.children_names
         FROM cases cs
         LEFT JOIN clients cl ON cl.id = cs.client_id
         WHERE cs.id = ?"
    );
    $stmt->execute(array($caseId));
    $caso = $stmt->fetch();

    if (!$caso) {
        echo json_encode(array('error' => 'Caso não encontrado'));
        exit;
    }

    // Montar dados do cliente
    $dadosCliente = "Nome: " . ($caso['client_name'] ?: '[NÃO INFORMADO]');
    $dadosCliente .= "\nCPF: " . ($caso['cpf'] ?: '[NÃO INFORMADO]');
    $dadosCliente .= "\nRG: " . ($caso['rg'] ?: '[NÃO INFORMADO]');
    $dadosCliente .= "\nData de nascimento: " . ($caso['birth_date'] ? date('d/m/Y', strtotime($caso['birth_date'])) : '[NÃO INFORMADO]');
    $dadosCliente .= "\nProfissão: " . ($caso['profession'] ?: '[NÃO INFORMADO]');
    $dadosCliente .= "\nEstado civil: " . ($caso['marital_status'] ?: '[NÃO INFORMADO]');
    $dadosCliente .= "\nEndereço: " . ($caso['address_street'] ?: '[NÃO INFORMADO]');
    $dadosCliente .= "\nCidade/UF: " . ($caso['address_city'] ?: '[NÃO INFORMADO]') . '/' . ($caso['address_state'] ?: '[NÃO INFORMADO]');
    $dadosCliente .= "\nCEP: " . ($caso['address_zip'] ?: '[NÃO INFORMADO]');
    $dadosCliente .= "\nTelefone: " . ($caso['client_phone'] ?: '[NÃO INFORMADO]');
    $dadosCliente .= "\nE-mail: " . ($caso['client_email'] ?: '[NÃO INFORMADO]');
    $dadosCliente .= "\nChave PIX: " . ($caso['pix_key'] ?: '[NÃO INFORMADO]');
    if ($caso['children_names']) $dadosCliente .= "\nFilhos: " . $caso['children_names'];

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
    $userPrompt .= "Data atual: " . date('d/m/Y') . "\n";
    $userPrompt .= "Comarca: " . ($caso['address_city'] ?: 'Barra Mansa') . '/' . ($caso['address_state'] ?: 'RJ') . "\n\n";
    $userPrompt .= "Elabore a petição completa em HTML formatado.";

    // Chamar API Anthropic
    $apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
    if (!$apiKey) {
        echo json_encode(array('error' => 'ANTHROPIC_API_KEY não configurada. Configure no config.php.'));
        exit;
    }

    $payload = json_encode(array(
        'model' => 'claude-sonnet-4-6-20250514',
        'max_tokens' => 4096,
        'system' => get_system_prompt(),
        'messages' => array(
            array('role' => 'user', 'content' => $userPrompt)
        ),
    ));

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
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        echo json_encode(array('error' => 'Erro de conexão: ' . $error));
        exit;
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200 || !isset($data['content'][0]['text'])) {
        $errMsg = isset($data['error']['message']) ? $data['error']['message'] : 'Erro HTTP ' . $httpCode;
        echo json_encode(array('error' => 'API Anthropic: ' . $errMsg));
        exit;
    }

    $htmlPeticao = $data['content'][0]['text'];
    $tokensIn = isset($data['usage']['input_tokens']) ? (int)$data['usage']['input_tokens'] : 0;
    $tokensOut = isset($data['usage']['output_tokens']) ? (int)$data['usage']['output_tokens'] : 0;
    $custoUsd = ($tokensIn * 0.003 / 1000) + ($tokensOut * 0.015 / 1000);

    // Salvar no banco
    $titulo = $labelPeca . ' — ' . $labelAcao . ' — ' . ($caso['client_name'] ?: 'Caso #' . $caseId);
    $stmt = $pdo->prepare(
        "INSERT INTO case_documents (case_id, client_id, tipo_peca, tipo_acao, titulo, conteudo_html, gerado_por, tokens_input, tokens_output, custo_usd)
         VALUES (?,?,?,?,?,?,?,?,?,?)"
    );
    $stmt->execute(array(
        $caseId, $caso['client_id'] ?: 0, $tipoPeca, $tipoAcao,
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
