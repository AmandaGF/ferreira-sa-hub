<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$stmt = $pdo->query("SELECT id, title, status, closed_at, kanban_oculto FROM cases WHERE title LIKE '%Nayara%' ORDER BY id DESC");
$cases = $stmt->fetchAll();
echo "Casos Nayara:\n";
foreach ($cases as $c) {
    echo "  Case #{$c['id']} | {$c['title']} | status={$c['status']} | closed={$c['closed_at']} | oculto={$c['kanban_oculto']}\n";
}

if (isset($_GET['fix'])) {
    foreach ($cases as $c) {
        if ($c['status'] === 'arquivado') {
            $pdo->prepare("UPDATE cases SET status = 'em_andamento', closed_at = NULL, kanban_oculto = 0, updated_at = NOW() WHERE id = ?")
                ->execute(array($c['id']));
            echo "\n  Case #{$c['id']} desarquivado => em_andamento\n";
        }
    }
    echo "\n=== OK ===\n";
} else {
    echo "\nAdicione &fix=1 para desarquivar.\n";
}
