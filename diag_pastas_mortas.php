<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== PASTAS DE DESTINO DO RESGATE ===\n\n";
// Quais cases receberiam os arquivos e quantos arquivos cada um
$q = $pdo->query(
  "SELECT c.id case_id, c.title, c.drive_folder_url, COUNT(*) arquivos
   FROM zapi_mensagens m
   JOIN zapi_conversas co ON co.id = m.conversa_id
   JOIN cases c ON c.id = (
       SELECT c2.id FROM cases c2
        WHERE c2.client_id = co.client_id
          AND c2.drive_folder_url IS NOT NULL AND c2.drive_folder_url != ''
        ORDER BY c2.updated_at DESC, c2.id DESC LIMIT 1)
   WHERE m.tipo IN ('imagem','video','audio','documento')
     AND m.arquivo_url IS NOT NULL AND m.arquivo_url != ''
     AND (m.arquivo_salvo_drive = 0 OR m.arquivo_salvo_drive IS NULL)
     AND m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     AND co.client_id IS NOT NULL
   GROUP BY c.id ORDER BY arquivos DESC LIMIT 30");
$tot = 0;
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $fid = '';
    if (preg_match('#/folders/([\w-]+)#', (string)$r['drive_folder_url'], $m)) { $fid = $m[1]; }
    printf("  %-5s arq | case#%-5s %-45s | %s\n", $r['arquivos'], $r['case_id'],
        mb_substr((string)$r['title'],0,45), $fid ?: 'URL_INVALIDA');
    $tot += $r['arquivos'];
}
echo "\n  (top 30 destinos = $tot arquivos)\n";

echo "\n=== Estado atual do backup_status ===\n";
foreach ($pdo->query("SELECT COALESCE(backup_status,'(null)') st, COUNT(*) n
                      FROM zapi_mensagens WHERE arquivo_url IS NOT NULL AND arquivo_url<>''
                      GROUP BY backup_status ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    printf("  %-16s %s\n", $r['st'], $r['n']);
}
