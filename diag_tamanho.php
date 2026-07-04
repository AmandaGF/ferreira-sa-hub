<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('no');
set_time_limit(60);
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
function mb($b){ return number_format($b/1048576, 1, ',', '.') . ' MB'; }

echo "===== TAMANHO DO PROJETO — números reais =====\n\n";

// Banco: tamanho total + maiores tabelas
$db = $pdo->query("SELECT DATABASE()")->fetchColumn();
echo "Banco: $db\n";
$tot = $pdo->query("SELECT IFNULL(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchColumn();
$ntab = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchColumn();
echo "Tamanho total do banco: " . mb($tot) . "  ($ntab tabelas)\n\n";
echo "-- 12 maiores tabelas --\n";
$rows = $pdo->query("SELECT table_name, table_rows, (data_length+index_length) sz
                     FROM information_schema.tables WHERE table_schema=DATABASE()
                     ORDER BY sz DESC LIMIT 12")->fetchAll();
foreach ($rows as $r) printf("  %-28s %10s linhas   %10s\n", $r['table_name'], number_format($r['table_rows'],0,'','.'), mb($r['sz']));

// Contagens de negócio
echo "\n-- Volume de negócio --\n";
foreach (array('users','clients','cases','pipeline_leads','zapi_mensagens','zapi_conversas','asaas_cobrancas','audit_log') as $t) {
    try { echo "  " . str_pad($t, 20) . ": " . number_format((int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(),0,'','.') . "\n"; } catch (Exception $e) {}
}

// Arquivos: /files e /uploads
echo "\n-- Armazenamento de arquivos --\n";
foreach (array('files','uploads') as $dir) {
    $p = __DIR__ . '/' . $dir; $n=0; $sz=0;
    if (is_dir($p)) {
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) { if ($f->isFile()) { $n++; $sz += $f->getSize(); } }
        } catch (Exception $e) {}
    }
    echo "  /$dir: $n arquivos, " . mb($sz) . "\n";
}

// Disco da conta
echo "\n-- Disco --\n";
$free = @disk_free_space(__DIR__); $totd = @disk_total_space(__DIR__);
if ($totd) echo "  Livre: " . mb($free) . " de " . mb($totd) . " (" . round($free/$totd*100) . "% livre)\n";
