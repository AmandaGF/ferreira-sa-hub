<?php
ini_set('display_errors','1'); error_reporting(E_ALL);
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$l = $pdo->query("SELECT id, stage, linked_case_id FROM pipeline_leads WHERE id=1234")->fetch();
$c = $pdo->query("SELECT id, status, title FROM cases WHERE id=614")->fetch();
echo "Lead #1234: stage=" . $l['stage'] . " linked_case=" . $l['linked_case_id'] . "\n";
echo "Case #614: status=" . $c['status'] . " title=" . $c['title'] . "\n";
