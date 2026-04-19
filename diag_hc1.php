<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Histórico da cobrança #1 (Lais Carla, venc 2024-09-10) ===\n\n";

$hc = $pdo->query("SELECT * FROM honorarios_cobranca WHERE id = 1")->fetch();
print_r($hc);

echo "\n━━━ Histórico na honorarios_cobranca_historico ━━━\n";
$hist = $pdo->query("SELECT hh.*, u.name FROM honorarios_cobranca_historico hh LEFT JOIN users u ON u.id = hh.enviado_por WHERE cobranca_id = 1 ORDER BY hh.created_at")->fetchAll();
foreach ($hist as $h) {
    echo sprintf("  %s  etapa=%-25s  por=%-20s  via=%-10s  desc=%s\n",
        $h['created_at'], $h['etapa'], $h['name'] ?? '(id '.$h['enviado_por'].')', $h['enviado_via'] ?? '-', mb_substr($h['descricao']??'', 0, 80));
}

echo "\n━━━ Log de auditoria relacionado ━━━\n";
$audit = $pdo->query("SELECT al.*, u.name FROM audit_log al LEFT JOIN users u ON u.id = al.user_id WHERE al.entity_type = 'honorarios_cobranca' AND (al.entity_id = 1 OR al.details LIKE '%id=1%' OR al.details LIKE '%cobranca_id=1%') ORDER BY al.created_at DESC LIMIT 20")->fetchAll();
foreach ($audit as $a) {
    echo sprintf("  %s  user=%-18s  action=%-25s  details=%s\n",
        $a['created_at'], $a['name'] ?? '?', $a['action'], mb_substr($a['details']??'', 0, 100));
}
