<?php
// Lista os casos das colunas do pipeline de iniciais com o folderId do Drive,
// pra auditar quais ja possuem peticao escrita.
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$status = $_GET['status'] ?? 'em_elaboracao';
$q = $pdo->prepare("SELECT id, title, case_type, drive_folder_url,
                    DATEDIFF(NOW(), updated_at) AS dias
                    FROM cases WHERE status = ? ORDER BY updated_at ASC");
$q->execute([$status]);
$rows = $q->fetchAll(PDO::FETCH_ASSOC);

echo "# Casos em '$status': " . count($rows) . "\n";
echo "# formato: case_id | dias_parado | folderId | titulo\n\n";
foreach ($rows as $r) {
    $fid = '';
    if (!empty($r['drive_folder_url']) && preg_match('#/folders/([\w-]+)#', $r['drive_folder_url'], $m)) {
        $fid = $m[1];
    }
    printf("%s | %s | %s | %s\n", $r['id'], $r['dias'], $fid ?: 'SEM_PASTA',
        str_replace(["\n", "|"], ' ', (string)$r['title']));
}
