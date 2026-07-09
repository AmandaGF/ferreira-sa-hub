<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Destinatarios do email GERID (resultado POSITIVO) ===\n\n";
echo "Query exata do codigo:\n";
echo "  SELECT id, name, email FROM users\n";
echo "  WHERE is_active = 1 AND email IS NOT NULL AND email <> ''\n\n";

$st = $pdo->query("SELECT id, name, email, role FROM users WHERE is_active = 1 AND email IS NOT NULL AND email <> '' ORDER BY role, name");
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$i = 1;
foreach ($rows as $r) {
    echo str_pad($i++ . '.', 4)
       . str_pad($r['name'], 40)
       . str_pad('<' . $r['email'] . '>', 40)
       . '[' . $r['role'] . "]\n";
}

echo "\nTOTAL: " . count($rows) . " destinatarios\n\n";

// Ativos SEM email (nao recebem)
echo "-- Usuarios ATIVOS mas SEM email (nao recebem) --\n";
$st2 = $pdo->query("SELECT id, name, role FROM users WHERE is_active = 1 AND (email IS NULL OR email = '') ORDER BY name");
$fora = $st2->fetchAll(PDO::FETCH_ASSOC);
if (!$fora) echo "  (nenhum — todos ativos tem email)\n";
foreach ($fora as $f) echo "  #{$f['id']} {$f['name']} [{$f['role']}]\n";

echo "\n-- Usuarios INATIVOS (nunca recebem — nao aparecem na lista principal) --\n";
$st3 = $pdo->query("SELECT id, name, role FROM users WHERE is_active = 0 ORDER BY name");
$inat = $st3->fetchAll(PDO::FETCH_ASSOC);
if (!$inat) echo "  (nenhum)\n";
foreach ($inat as $f) echo "  #{$f['id']} {$f['name']} [{$f['role']}]\n";
