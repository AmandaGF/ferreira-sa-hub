<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== USER #12 (quem cria caso duplicado da Ana Paula) ===\n";
$u = $pdo->query("SELECT id, name, role FROM users WHERE id = 12")->fetch();
if ($u) printf("  user#%d = %s (role=%s)\n\n", $u['id'], $u['name'], $u['role']);

echo "=== CLIENTES COM 2+ CASES ATIVOS DE MESMO TÍTULO (duplicatas reais) ===\n";
$q = $pdo->query("
    SELECT client_id, title, COUNT(*) c, GROUP_CONCAT(id ORDER BY id) ids,
           GROUP_CONCAT(status ORDER BY id) sts
    FROM cases
    WHERE client_id > 0
    GROUP BY client_id, title
    HAVING c > 1
    ORDER BY c DESC, client_id DESC
    LIMIT 40
");
$linhas = 0;
foreach ($q as $r) {
    $linhas++;
    $nome = (string)$pdo->query("SELECT name FROM clients WHERE id={$r['client_id']}")->fetchColumn();
    printf("  client#%d %s | %d cases (ids: %s) status: [%s]\n    titulo: %s\n\n",
        $r['client_id'], $nome, $r['c'], $r['ids'], $r['sts'], mb_substr($r['title'], 0, 80));
}
$total = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT client_id, title FROM cases WHERE client_id > 0 GROUP BY client_id, title HAVING COUNT(*) > 1) t")->fetchColumn();
echo "TOTAL grupos duplicados (mesmo cliente+mesmo titulo): $total (mostrei $linhas)\n";

echo "\n=== CLIENTES COM 2+ CASES DE MESMO DRIVE_URL (mesma pasta fisica) ===\n";
$q = $pdo->query("
    SELECT drive_folder_url, COUNT(*) c, GROUP_CONCAT(id ORDER BY id) ids, GROUP_CONCAT(DISTINCT client_id) cli
    FROM cases
    WHERE drive_folder_url IS NOT NULL AND drive_folder_url <> ''
    GROUP BY drive_folder_url
    HAVING c > 1
    ORDER BY c DESC
    LIMIT 30
");
$total2 = 0;
foreach ($q as $r) {
    $total2++;
    printf("  %s | %d cases (ids: %s) client(s): %s\n", substr($r['drive_folder_url'], 55), $r['c'], $r['ids'], $r['cli']);
}
echo "TOTAL pastas compartilhadas: $total2\n";
