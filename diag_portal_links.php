<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== LINKS atuais no portal_links ===\n\n";
$q = $pdo->query("SELECT id, category, title, url, audience, is_favorite, sort_order FROM portal_links ORDER BY category, sort_order");
$cat = '';
foreach ($q->fetchAll() as $r) {
    if ($r['category'] !== $cat) {
        echo "\n── {$r['category']} ──\n";
        $cat = $r['category'];
    }
    $urlShow = $r['url'] ? $r['url'] : '(sem URL)';
    echo "  #{$r['id']} {$r['title']}\n    → {$urlShow}\n";
}

echo "\n=== TOTAIS ===\n";
$totais = $pdo->query("SELECT category, COUNT(*) as n FROM portal_links GROUP BY category ORDER BY category")->fetchAll();
foreach ($totais as $t) echo "  {$t['category']}: {$t['n']} links\n";
echo "\nTotal geral: " . $pdo->query("SELECT COUNT(*) FROM portal_links")->fetchColumn() . "\n";
