<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Config Apps Script ===\n";
if (defined('GOOGLE_APPS_SCRIPT_URL')) {
    $url = GOOGLE_APPS_SCRIPT_URL;
    echo "  URL: " . substr($url, 0, 80) . (strlen($url) > 80 ? '...' : '') . "\n";
    echo "  Definido: SIM\n";
} else {
    echo "  Definido: NAO (GOOGLE_APPS_SCRIPT_URL nao existe)\n";
    exit;
}

echo "\n=== Audit log de criacao de pastas (ultimas 10) ===\n";
try {
    $st = $pdo->query("SELECT id, user_id, action, entity_id, details, created_at
                       FROM audit_log
                       WHERE action IN ('drive_folder_created','drive_folder_failed')
                       ORDER BY id DESC LIMIT 10");
    $rows = $st->fetchAll();
    if (!$rows) echo "  (nenhum registro)\n";
    foreach ($rows as $r) {
        echo "  {$r['created_at']}  {$r['action']}  case#{$r['entity_id']}  user={$r['user_id']}\n";
        if ($r['details']) echo "    " . substr($r['details'], 0, 120) . "\n";
    }
} catch (Exception $e) { echo "  (erro: " . $e->getMessage() . ")\n"; }

echo "\n=== Cases sem drive_folder_url criados ultimos 14 dias ===\n";
try {
    $st = $pdo->query("SELECT c.id, c.title, c.case_type, c.created_at, cl.name AS cliente
                       FROM cases c LEFT JOIN clients cl ON cl.id = c.client_id
                       WHERE (c.drive_folder_url IS NULL OR c.drive_folder_url = '')
                         AND c.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                         AND c.status NOT IN ('cancelado','arquivado')
                       ORDER BY c.created_at DESC LIMIT 15");
    $rows = $st->fetchAll();
    if (!$rows) echo "  (nenhum — todos com pasta)\n";
    foreach ($rows as $r) {
        echo "  #{$r['id']}  {$r['created_at']}  {$r['cliente']} — {$r['title']}\n";
    }
} catch (Exception $e) { echo "  (erro: " . $e->getMessage() . ")\n"; }

echo "\n=== Teste vivo do Apps Script (cria pasta de TESTE) ===\n";
$payload = json_encode(array(
    'folderName'  => 'TESTE_DIAG_' . date('YmdHis'),
    'clientName'  => 'TESTE DIAG — pode apagar',
    'caseType'    => 'teste',
    'caseId'      => 0,
    'caseTitle'   => 'Diagnostico de criacao de pasta',
    'timestamp'   => date('Y-m-d H:i:s'),
));
$t0 = microtime(true);
$ch = curl_init(GOOGLE_APPS_SCRIPT_URL);
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => false,
));
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);
$ms = round((microtime(true) - $t0) * 1000);

echo "  HTTP {$http} ({$ms}ms)\n";
if ($err) echo "  cURL erro: $err\n";
if ($resp) {
    echo "  Resposta crua (primeiros 500 chars):\n";
    echo "    " . substr($resp, 0, 500) . "\n";
    $data = json_decode($resp, true);
    if (is_array($data)) {
        echo "  JSON parseado: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo "  (resposta NAO e JSON valido — provavel HTML de login/erro do Google)\n";
    }
}
