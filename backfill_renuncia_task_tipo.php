<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== BACKFILL tipo das tasks de renuncia/desistencia ===\n\n";
echo "Antes: tasks criadas pela renuncia eram tipo='juntar_documento' e ficavam\n";
echo "misturadas com outras tarefas de juntar doc. Agora vao pra tipo=r.tipo\n";
echo "('renuncia' ou 'desistencia') pro banner destacado achar.\n\n";

$sel = $pdo->query("
    SELECT r.id AS ren_id, r.task_id, r.tipo AS ren_tipo,
           t.id AS tid, t.tipo AS task_tipo, t.status
    FROM renuncias r
    INNER JOIN case_tasks t ON t.id = r.task_id
    WHERE t.tipo = 'juntar_documento'
    ORDER BY r.id DESC
");
$upd = $pdo->prepare("UPDATE case_tasks SET tipo = ? WHERE id = ?");
$n = 0;
foreach ($sel as $row) {
    $upd->execute([$row['ren_tipo'], $row['tid']]);
    echo "task#{$row['tid']} (ren#{$row['ren_id']}): juntar_documento -> {$row['ren_tipo']} (status={$row['status']})\n";
    $n++;
}
echo "\nTotal: $n task(s) atualizada(s).\n";
