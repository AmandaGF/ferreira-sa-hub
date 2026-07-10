<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors','1');

// Carrega o cron sem auto-executar
define('CLAUDIN_NO_AUTORUN', true);
require_once __DIR__ . '/cron/djen_monitor.php';

$pdo = db();

echo "=== TESTAR envio email diario DJEN ===\n\n";

echo "EMAIL_ALERTAS constant: ";
if (defined('EMAIL_ALERTAS')) {
    if (is_array(EMAIL_ALERTAS)) echo implode(', ', EMAIL_ALERTAS) . "\n\n";
    else echo EMAIL_ALERTAS . "\n\n";
} else echo "(NAO DEFINIDA)\n\n";

// Pega ultima execução e simula params
$run = $pdo->query("SELECT * FROM claudin_runs ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo "Ultima execucao: id=$run[id] data_alvo=$run[data_alvo] imported=$run[imported] pending=$run[pending]\n";
$contadores = array(
    'imported'   => (int)$run['imported'],
    'duplicated' => (int)$run['duplicated'],
    'pending'    => (int)$run['pending'],
    'errors'     => 0,
);

echo "\nChamando claudin_montar_email_diario_html…\n";
if (!function_exists('claudin_montar_email_diario_html')) {
    echo "FUNCAO NAO EXISTE! o codigo do email nao esta deployado.\n";
    exit;
}
$email = claudin_montar_email_diario_html($pdo, $run['data_alvo'], $contadores, $run['horario']);
if (!$email) {
    echo "Retornou null (provavelmente sem publicacoes na data_alvo)\n";
    exit;
}
echo "Assunto: $email[assunto]\n";
echo "Tamanho HTML: " . number_format(strlen($email['html'])) . " bytes\n";
echo "Tamanho texto: " . number_format(strlen($email['texto'])) . " bytes\n\n";

echo "Testando envio via claudin_enviar_email…\n";
claudin_enviar_email($email['assunto'], $email['texto'], $email['html']);
echo "OK, enviado (ver log do Brevo pra confirmar recebimento).\n";
