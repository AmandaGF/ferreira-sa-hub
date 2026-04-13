<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors','1'); error_reporting(E_ALL);
require_once dirname(__DIR__) . '/salavip/config.php';
require_once dirname(__DIR__) . '/salavip/includes/auth.php';
require_once dirname(__DIR__) . '/salavip/includes/functions.php';
$pdo = sv_db();
echo "Config OK\n";
echo "DB OK\n";

// Test documentos query
try {
    $stmt = $pdo->prepare("SELECT dp.*, c.title AS processo_titulo FROM documentos_pendentes dp LEFT JOIN cases c ON c.id = dp.case_id WHERE dp.client_id = ? AND dp.visivel_cliente = 1 ORDER BY dp.solicitado_em DESC");
    $stmt->execute([447]);
    echo "Query 1 OK: " . count($stmt->fetchAll()) . " rows\n";
} catch (Exception $e) { echo "Query 1 ERR: " . $e->getMessage() . "\n"; }

try {
    $stmt = $pdo->prepare("SELECT dc.*, c.title AS processo_titulo FROM salavip_documentos_cliente dc LEFT JOIN cases c ON c.id = dc.case_id WHERE dc.cliente_id = ? ORDER BY dc.criado_em DESC");
    $stmt->execute([447]);
    echo "Query 2 OK: " . count($stmt->fetchAll()) . " rows\n";
} catch (Exception $e) { echo "Query 2 ERR: " . $e->getMessage() . "\n"; }

// Check columns
echo "\n--- salavip_documentos_cliente columns ---\n";
$cols = $pdo->query("SHOW COLUMNS FROM salavip_documentos_cliente")->fetchAll();
foreach ($cols as $c) echo $c['Field'] . "\n";
