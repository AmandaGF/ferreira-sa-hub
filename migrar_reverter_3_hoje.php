<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
$pdo = db();

$ids = array(718, 892, 678);
echo "Revertendo " . count($ids) . " cases ocultos hoje:\n\n";
foreach ($ids as $cid) {
    $st = $pdo->prepare("SELECT id, title, status, kanban_oculto, closed_at FROM cases WHERE id = ?");
    $st->execute(array($cid));
    $c = $st->fetch();
    if (!$c) { echo "  case#{$cid} — não encontrado\n"; continue; }
    echo "  case#{$cid} | {$c['title']} (era status={$c['status']} oculto={$c['kanban_oculto']} closed={$c['closed_at']})";
    try {
        $pdo->prepare("UPDATE cases SET kanban_oculto = 0, closed_at = NULL, updated_at = NOW() WHERE id = ?")
            ->execute(array($cid));
        try { audit_log('case_unhide_revert', 'case', $cid, "kanban_oculto -> 0, closed_at -> NULL (revert hoje)"); } catch (Exception $e) {}
        echo " ✓\n";
    } catch (Exception $e) { echo " ✗ {$e->getMessage()}\n"; }
}
echo "\nPronto.\n";
