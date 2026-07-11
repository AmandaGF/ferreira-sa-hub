<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== AMBIENTE ===\n";
echo "Server host: " . ($_SERVER['HTTP_HOST'] ?? '?') . "\n";
echo "Server name: " . ($_SERVER['SERVER_NAME'] ?? '?') . "\n";
echo "Doc root: " . ($_SERVER['DOCUMENT_ROOT'] ?? '?') . "\n";
echo "PHP SAPI: " . php_sapi_name() . "\n";
echo "Data/hora servidor: " . date('Y-m-d H:i:s') . "\n";

echo "\n=== BANCO ===\n";
echo "DB host: " . (defined('DB_HOST') ? DB_HOST : '?') . "\n";
echo "DB nome: " . (defined('DB_NAME') ? DB_NAME : '?') . "\n";
$r = $pdo->query("SELECT DATABASE() dbnow, @@hostname host, @@version ver")->fetch(PDO::FETCH_ASSOC);
echo "Banco ativo: " . $r['dbnow'] . "\n";
echo "MySQL host: " . $r['host'] . "\n";
echo "MySQL ver: " . $r['ver'] . "\n";

echo "\n=== ARQUIVO perfis.php ===\n";
$f = __DIR__ . '/modules/presenca/perfis.php';
echo "Path: $f\n";
if (file_exists($f)) {
    echo "Tamanho: " . filesize($f) . " bytes\n";
    echo "Modificado: " . date('Y-m-d H:i:s', filemtime($f)) . "\n";
    echo "Hash md5: " . md5_file($f) . "\n";
} else echo "NAO EXISTE!\n";

echo "\n=== PERFIS NO BANCO (raw) ===\n";
$total = (int)$pdo->query("SELECT COUNT(*) FROM presenca_perfil")->fetchColumn();
echo "Total: $total\n\n";
foreach ($pdo->query("SELECT id, nome, slug, LENGTH(nome) len_nome, ticket_min, ticket_max, verba_min, verba_max, ativo, created_at FROM presenca_perfil ORDER BY id") as $p) {
    echo "id=$p[id] | nome='" . $p['nome'] . "' (len=$p[len_nome]) | slug=$p[slug] | tMin=$p[ticket_min] tMax=$p[ticket_max] | vMin=$p[verba_min] vMax=$p[verba_max] | ativo=$p[ativo] | criado=$p[created_at]\n";
}
echo "\nSe a Amanda ve 'perfil a com zeros' mas isto retorna Essencial/Premium/Alta, o problema esta no BROWSER dela (service worker/cache PWA), nao no servidor.\n";
