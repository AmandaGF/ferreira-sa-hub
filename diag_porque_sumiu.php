<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
echo "HOJE: " . date('Y-m-d') . "\n\n";

echo "== TODOS prazos_processuais (pendentes E concluidos) ==\n";
$r = $pdo->query("SELECT id, descricao_acao, prazo_fatal, concluido, concluido_em, created_at FROM prazos_processuais ORDER BY id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
foreach ($r as $p) {
    echo "id={$p['id']} | fatal={$p['prazo_fatal']} | concl={$p['concluido']} | concl_em=" . ($p['concluido_em']?:'-') . " | criado=" . substr($p['created_at']??'-',0,10) . " | desc: " . substr($p['descricao_acao']??'',0,60) . "\n";
}

echo "\n== prazos_processuais concluidos NAS ULTIMAS 48H ==\n";
$r = $pdo->query("SELECT id, descricao_acao, prazo_fatal, concluido_em FROM prazos_processuais WHERE concluido=1 AND concluido_em >= DATE_SUB(NOW(), INTERVAL 48 HOUR) ORDER BY concluido_em DESC")->fetchAll(PDO::FETCH_ASSOC);
echo "TOTAL: " . count($r) . "\n";
foreach ($r as $p) echo "  id={$p['id']} | fatal={$p['prazo_fatal']} | concl_em={$p['concluido_em']} | desc: " . substr($p['descricao_acao']??'',0,60) . "\n";

echo "\n== prazos_processuais PENDENTES (qualquer data) ==\n";
$r = $pdo->query("SELECT id, descricao_acao, prazo_fatal FROM prazos_processuais WHERE concluido=0 ORDER BY prazo_fatal ASC")->fetchAll(PDO::FETCH_ASSOC);
echo "TOTAL: " . count($r) . "\n";
foreach ($r as $p) {
    $d = (int)((strtotime($p['prazo_fatal']) - strtotime('today')) / 86400);
    echo "  id={$p['id']} | fatal={$p['prazo_fatal']} ({$d}d) | desc: " . substr($p['descricao_acao']??'',0,60) . "\n";
}

echo "\n== ULTIMOS 5 audit_log envolvendo prazos ==\n";
try {
    $r = $pdo->query("SELECT created_at, action, target_type, target_id, details FROM audit_log WHERE action LIKE '%prazo%' OR target_type='prazo' OR details LIKE '%prazo%' ORDER BY id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($r as $a) echo "  {$a['created_at']} | {$a['action']} | {$a['target_type']}:{$a['target_id']} | " . substr($a['details']??'',0,100) . "\n";
} catch (Throwable $e) { echo "  (audit_log indisponivel: ".$e->getMessage().")\n"; }
