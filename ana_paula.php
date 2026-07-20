<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1'); error_reporting(E_ALL);
$pdo = db();

// tenta simples primeiro
echo "1. SELECT * FROM cases WHERE client_id = 2479 ORDER BY id\n";
try {
    $rows = $pdo->query("SELECT * FROM cases WHERE client_id = 2479 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    echo "  " . count($rows) . " linha(s)\n";
    foreach ($rows as $r) {
        echo "\n  case#{$r['id']}:\n";
        foreach ($r as $k => $v) {
            if ($v === null || $v === '') continue;
            $s = mb_substr((string)$v, 0, 100, 'UTF-8');
            echo "    $k = $s\n";
        }
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }
