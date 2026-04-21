<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== TELEFONES gravados nos leads da Planilha ===\n\n";
$rows = $pdo->query("SELECT id, name, phone, LENGTH(phone) as len FROM pipeline_leads WHERE converted_at IS NOT NULL AND stage NOT IN ('arquivado') ORDER BY id DESC LIMIT 15")->fetchAll();
foreach ($rows as $r) {
    echo sprintf("#%-5d len=%-3s [%s] name=[%s]\n",
        $r['id'], $r['len'] ?: '0', $r['phone'] ?: '(vazio)', mb_substr($r['name'], 0, 40)
    );
}
