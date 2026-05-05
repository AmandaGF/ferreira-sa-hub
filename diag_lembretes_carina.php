<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Carina (user) ===\n";
$st = $pdo->query("SELECT id, name, email, role FROM users WHERE name LIKE '%arina%'");
foreach ($st->fetchAll() as $r) echo "  #{$r['id']} {$r['name']} ({$r['email']}) role={$r['role']}\n";

echo "\n=== Lembretes onde Carina é DESTINATÁRIA (usuario_id) ===\n";
$st = $pdo->query("SELECT e.id, e.titulo, e.data_evento, e.hora_inicio, e.concluido, IFNULL(e.arquivado,0) AS arq, e.criado_em, e.criado_por, u.name AS criador_nome
                   FROM eventos_dia e
                   LEFT JOIN users u ON u.id = e.criado_por
                   WHERE e.tipo = 'lembrete' AND e.usuario_id IN (SELECT id FROM users WHERE name LIKE '%arina%')
                   ORDER BY e.id DESC LIMIT 30");
$rows = $st->fetchAll();
echo "  total: " . count($rows) . "\n";
foreach ($rows as $r) {
    $st2 = $r['concluido'] ? '✓ feito' : '○ aberto';
    if ($r['arq']) $st2 .= ' 📁 arquivado';
    echo "  #{$r['id']}  {$r['data_evento']} " . ($r['hora_inicio'] ?: '') . "  [$st2]  por={$r['criador_nome']}  '{$r['titulo']}'\n";
}

echo "\n=== Lembretes CRIADOS pela Carina (criado_por) ===\n";
$st = $pdo->query("SELECT e.id, e.titulo, e.data_evento, e.usuario_id, IFNULL(e.arquivado,0) AS arq, e.concluido, u.name AS para_quem
                   FROM eventos_dia e
                   LEFT JOIN users u ON u.id = e.usuario_id
                   WHERE e.tipo = 'lembrete' AND e.criado_por IN (SELECT id FROM users WHERE name LIKE '%arina%')
                   ORDER BY e.id DESC LIMIT 30");
$rows = $st->fetchAll();
echo "  total: " . count($rows) . "\n";
foreach ($rows as $r) {
    $st2 = $r['concluido'] ? '✓ feito' : '○ aberto';
    if ($r['arq']) $st2 .= ' 📁 arquivado';
    echo "  #{$r['id']}  {$r['data_evento']}  [$st2]  pra={$r['para_quem']}  '{$r['titulo']}'\n";
}

echo "\n=== Audit log de exclusoes recentes (eventos_dia) ===\n";
try {
    $st = $pdo->query("SELECT * FROM audit_log WHERE entity_type IN ('lembrete','eventos_dia') ORDER BY id DESC LIMIT 10");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "  {$r['created_at']}  {$r['action']}  user={$r['user_id']}  details=" . substr((string)$r['details'], 0, 100) . "\n";
    }
} catch (Exception $e) { echo "  (sem registros ou erro)\n"; }
