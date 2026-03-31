<?php
/**
 * Fábrica de Petições — API
 * Chama API Anthropic (Claude Sonnet 4.6) e retorna HTML da petição
 */

// Carregar middleware normalmente
require_once __DIR__ . '/../../core/middleware.php';

// Interceptar: se não logado, retornar JSON em vez de redirect HTML
if (!is_logged_in()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('error' => 'Sessão expirada. Recarregue a página e faça login.'));
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if (!has_min_role('gestao') && !has_role('cx') && !has_role('operacional')) {
    echo json_encode(array('error' => 'Sem permissão'));
    exit;
}

require_once __DIR__ . '/system_prompt.php';

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

    // Extrair cidade/UF do campo cl_cidade (formato "Cidade/UF")
    $comarcaCidade = 'Barra Mansa';
    $comarcaUF = 'RJ';
    if ($clCidade && strpos($clCidade, '/') !== false) {
        $parts = explode('/', $clCidade);
        $comarcaCidade = trim($parts[0]) ?: 'Barra Mansa';
        $comarcaUF = trim($parts[1]) ?: 'RJ';
    }

    // ══ PROMPT FIXO — Skill dra-amanda ══
    // Esta instrução é SEMPRE prepended ao prompt do usuário.
    // Garante que a petição seja gerada seguindo o padrão completo
    // da Skill "dra-amanda" do escritório Ferreira & Sá.
    $promptFixo = <<<'FIXO'
[INSTRUÇÃO OBRIGATÓRIA — SKILL DRA-AMANDA]
Você está operando como a skill "dra-amanda" do escritório Ferreira & Sá Advocacia Especializada.
Siga RIGOROSAMENTE todas as regras do system prompt: princípios inegociáveis, estrutura da petição,
visual law (HTML com estilos inline), qualificação das partes, seções fixas e referências jurídicas.

A petição deve ser COMPLETA, do endereçamento à assinatura, incluindo:
- Timbrado do escritório no topo (logo F + FERREIRA & SÁ)
- Endereçamento ao Juízo competente
- Indicações à direita (Gratuidade / Juízo 100% Digital quando aplicável)
- Qualificação completa do Autor (nome em negrito+versalete, texto corrido)
- Caixa da ação (fundo #052228, texto branco, faixa cobre à esquerda)
- Qualificação do Réu (dados faltantes em vermelho)
- Todas as seções com títulos à DIREITA + bloco petrol na margem direita
- Subtópicos com barra cobre à esquerda
- Fundamentação jurídica robusta com artigos, súmulas e doutrina
- Tabela de pedidos com coluna de letras em fundo petrol
- Seção de futuras intimações (Dra. Amanda, OAB-RJ 163.260)
- Seção de provas e valor da causa (com extenso)
- Assinatura centralizada + rodapé com 5 filiais
- Use APENAS estilos inline, nunca CSS externo
- Fonte Calibri, 12pt, justificado, text-indent 1.5cm, line-height 1.8
[FIM DA INSTRUÇÃO OBRIGATÓRIA]

FIXO;

    // Montar prompt do usuário com instrução fixa prepended
    $userPrompt = $promptFixo . "\n";
    $userPrompt .= "Tipo de peça: $labelPeca\nTipo de ação: $labelAcao\n\n";
    $userPrompt .= "DADOS DO CLIENTE (PARTE AUTORA):\n$dadosCliente\n\n";
    $userPrompt .= "DADOS ESPECÍFICOS DA AÇÃO:\n$camposEspecificos\n\n";
    $userPrompt .= "Data atual: " . date('d/m/Y') . "\n";
    $userPrompt .= "Comarca: " . $comarcaCidade . '/' . $comarcaUF . "\n\n";
    $userPrompt .= "Elabore a petição completa em HTML.\n\n";
    $userPrompt .= "REGRAS DE OUTPUT OBRIGATÓRIAS:\n";
    $userPrompt .= "- Retorne SOMENTE o HTML puro, SEM markdown, SEM ```html, SEM ```\n";
    $userPrompt .= "- NÃO use tags <html>, <head>, <body>, <style>, <!DOCTYPE>\n";
    $userPrompt .= "- Use APENAS estilos inline (style=\"...\") em cada elemento\n";
    $userPrompt .= "- Comece direto com o <div> do timbrado do escritório\n";
    $userPrompt .= "- Fonte: Calibri,sans-serif (NUNCA Times New Roman)\n";
    $userPrompt .= "- Siga EXATAMENTE os templates HTML do system prompt (timbrado, caixa da ação, seções à direita, pedidos em tabela)\n";
    $userPrompt .= "- NÃO gere timbrado/logo nem rodapé — o papel timbrado é aplicado como fundo pela plataforma\n";
    $userPrompt .= "- Comece DIRETO pelo endereçamento ao Juízo\n";
    $userPrompt .= "- A petição deve ser COMPLETA: endereçamento, indicações, qualificação, caixa da ação, todas as seções (fatos, direito, pedidos), intimações, provas, assinatura\n";
    $userPrompt .= "- NUNCA corte ou interrompa. Se necessário, seja mais conciso nos fatos, mas SEMPRE inclua todas as seções até a assinatura final\n";

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

    // ══ Cache DESABILITADO temporariamente — forçar chamadas novas à API ══
    // O cache estava retornando petições antigas com formato incorreto.
    // Reabilitar quando o visual law estiver estável.
    $cacheKey = md5($tipoAcao . $tipoPeca . $clNome . $clientId . date('Y-m-d-H'));
    $cacheDir = sys_get_temp_dir();
    $cacheFile = $cacheDir . '/peticao_cache_' . $cacheKey . '.json';
    // Cache desabilitado: sempre chama a API

    // ══ MODO DEBUG: ?debug=1 mostra o que seria enviado sem chamar a API ══
    if (isset($_POST['debug']) && $_POST['debug'] === '1') {
        $debugInfo = array(
            'system_prompt' => get_system_prompt(),
            'system_prompt_tokens_estimado' => (int)(mb_strlen(get_system_prompt(), 'UTF-8') / 4),
            'user_message' => $messageContent,
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 16384,
            'temperature' => 0.3,
            'cache_control' => 'ephemeral',
            'anexos_count' => count($contentBlocks),
        );
        echo json_encode($debugInfo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // Chamar API Anthropic
    $apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
    if (!$apiKey) {
        echo json_encode(array('error' => 'ANTHROPIC_API_KEY não configurada. Configure no config.php.'));
        exit;
    }

    // ══ OTIMIZAÇÃO 1: Prompt Caching — cache_control: ephemeral no system prompt ══
    // ══ OTIMIZAÇÃO 5: temperature 0.3, max_tokens 4096 ══
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

    // Salvar log do retorno bruto da última chamada (para debug)
    $logDir = __DIR__ . '/../../uploads';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    @file_put_contents($logDir . '/peticao_last_response.json', json_encode(array(
        'timestamp' => date('Y-m-d H:i:s'),
        'http_code' => $httpCode,
        'tentativas' => $tentativa + 1,
        'response_raw' => $data,
        'payload_system_prompt' => mb_substr($systemPromptText, 0, 200) . '...',
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
