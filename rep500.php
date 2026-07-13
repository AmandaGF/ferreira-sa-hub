<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
ini_set('display_errors', '1'); error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

require_once dirname(__DIR__) . '/salavip/config.php';
$pdo = sv_db();

$threadId = (int)($_GET['id'] ?? 8);
echo "=== THREAD $threadId ===\n";
$t = $pdo->prepare("SELECT * FROM salavip_threads WHERE id = ?");
$t->execute([$threadId]);
$thread = $t->fetch();
if (!$thread) { echo "thread nao existe\n"; exit; }
print_r($thread);

$cid = (int)$thread['cliente_id'];
echo "\n=== SIMULANDO SESSAO DO CLIENTE $cid ===\n";

// Ver o auth.php pra entender como valida sessão
$auth = file_get_contents(dirname(__DIR__) . '/salavip/includes/auth.php');
echo "auth.php (primeiras 60 linhas):\n";
$lines = explode("\n", $auth);
for ($i = 0; $i < min(60, count($lines)); $i++) echo "  " . ($i+1) . ": " . $lines[$i] . "\n";

echo "\n=== FUNCTIONS.PHP topo ===\n";
$fn = file_get_contents(dirname(__DIR__) . '/salavip/includes/functions.php');
$lines = explode("\n", $fn);
for ($i = 0; $i < min(50, count($lines)); $i++) echo "  " . ($i+1) . ": " . $lines[$i] . "\n";
