<?php
/**
 * Atualiza o submit.php do formulário de Gastos Pensão
 * para gravar direto no banco do Conecta (sem curl/SSL)
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
header('Content-Type: text/plain; charset=utf-8');

$destino = $_SERVER['DOCUMENT_ROOT'] . '/gastos_pensao/submit.php';
echo "Destino: $destino\n";
echo "Existe: " . (file_exists($destino) ? 'SIM' : 'NÃO') . "\n\n";

if (!file_exists($destino)) {
    // Tentar outros caminhos
    $alternativas = array(
        $_SERVER['DOCUMENT_ROOT'] . '/Gastos Pensao/submit.php',
        $_SERVER['DOCUMENT_ROOT'] . '/gastos-pensao/submit.php',
        $_SERVER['DOCUMENT_ROOT'] . '/formularios/gastos-pensao/submit.php',
        $_SERVER['DOCUMENT_ROOT'] . '/formularios/gastos_pensao/submit.php',
    );
    foreach ($alternativas as $alt) {
        if (file_exists($alt)) {
            $destino = $alt;
            echo "Encontrado em: $destino\n\n";
            break;
        }
    }
}

if (!file_exists($destino)) {
    echo "ERRO: Arquivo submit.php não encontrado em nenhum caminho!\n";
    echo "Listando pastas no document_root:\n";
    $dirs = scandir($_SERVER['DOCUMENT_ROOT']);
    foreach ($dirs as $d) {
        if (stripos($d, 'gasto') !== false || stripos($d, 'pensao') !== false || stripos($d, 'formulario') !== false) {
            echo "  → $d\n";
        }
    }
    exit;
}

// Backup
$backup = $destino . '.bak_' . date('Ymd_His');
copy($destino, $backup);
echo "Backup criado: $backup\n\n";

// Ler credenciais do Conecta
require_once __DIR__ . '/core/config.php';
$conectaUser = DB_USER;
$conectaPass = DB_PASS;

// Novo conteúdo do submit.php
$novoConteudo = '<?php
/**
 * Gastos Pensão — Submit (ATUALIZADO)
 * Grava DIRETO no banco do Conecta via PDO localhost.
 * Também grava no banco legado como backup.
 */
require __DIR__ . \'/config.php\';

header(\'Content-Type: application/json; charset=utf-8\');

function responder($ok, $mensagem, $extra = []) {
    echo json_encode(array_merge([
        \'ok\' => $ok,
        \'message\' => $mensagem
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER[\'REQUEST_METHOD\'] !== \'POST\') {
        responder(false, \'Método inválido.\');
    }

    $conteudo = file_get_contents(\'php://input\');
    $dados = json_decode($conteudo, true);

    if (!is_array($dados)) {
        responder(false, \'Dados inválidos.\');
    }

    $nome_responsavel = trim($dados[\'nome_responsavel\'] ?? \'\');
    $whatsapp = trim($dados[\'whatsapp\'] ?? \'\');
    $nome_filho_referente = trim($dados[\'nome_filho_referente\'] ?? \'\');
    $fonte_renda = trim($dados[\'fonte_renda\'] ?? \'\');

    if ($nome_responsavel === \'\') responder(false, \'Nome do responsável é obrigatório.\');
    if ($whatsapp === \'\') responder(false, \'Telefone/WhatsApp é obrigatório.\');
    if ($nome_filho_referente === \'\') responder(false, \'Nome do filho(a) é obrigatório.\');
    if ($fonte_renda === \'\') responder(false, \'Fonte principal de renda é obrigatória.\');

    $protocolo = \'GST-\' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 10));
    $ip = $_SERVER[\'REMOTE_ADDR\'] ?? \'\';
    $payload_json = json_encode($dados, JSON_UNESCAPED_UNICODE);

    // GRAVAR NO CONECTA (direto via PDO localhost)
    $pdoConecta = new PDO(
        \'mysql:host=localhost;dbname=ferre3151357_conecta;charset=utf8mb4\',
        \'' . addslashes($conectaUser) . '\',
        \'' . addslashes($conectaPass) . '\',
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );

    $pdoConecta->prepare(
        "INSERT INTO form_submissions (form_type, protocol, client_name, client_phone, client_email, status, payload_json, ip_address, created_at)
         VALUES (?, ?, ?, ?, \'\', \'novo\', ?, ?, NOW())"
    )->execute(array(\'gastos_pensao\', $protocolo, $nome_responsavel, $whatsapp, $payload_json, $ip));

    // BACKUP: gravar também no banco legado
    try {
        $pdo = db();
        $totais = $dados[\'totais\'] ?? [];
        $pdo->prepare("INSERT INTO pensao_respostas (protocolo, created_at, ip, user_agent, nome_responsavel, cpf_responsavel, whatsapp, nome_filho_referente, fonte_renda, renda_mensal_cents, qtd_filhos, moradores, payload_json) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute(array($protocolo, $ip, substr($_SERVER[\'HTTP_USER_AGENT\'] ?? \'\', 0, 255), $nome_responsavel, trim($dados[\'cpf_responsavel\'] ?? \'\') ?: null, $whatsapp, $nome_filho_referente, $fonte_renda, (int)($dados[\'renda_mensal_cents\'] ?? 0), (int)($dados[\'qtd_filhos\'] ?? 0), max(1,(int)($dados[\'moradores\'] ?? 1)), $payload_json));
    } catch (Throwable $e) {
        error_log(\'submit.php backup legado falhou: \' . $e->getMessage());
    }

    responder(true, \'Salvo com sucesso.\', [\'protocolo\' => $protocolo]);

} catch (Throwable $e) {
    responder(false, \'Erro interno: \' . $e->getMessage());
}
';

file_put_contents($destino, $novoConteudo);
echo "[OK] submit.php atualizado com sucesso!\n";
echo "Tamanho: " . strlen($novoConteudo) . " bytes\n";
echo "\nAgora o formulário grava direto no banco do Conecta (sem curl/SSL).\n";
