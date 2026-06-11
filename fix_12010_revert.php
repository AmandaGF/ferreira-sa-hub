<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$novo = 'Petição do autor (advogada Amanda Guedes Ferreira) informando não ter interesse na produção de demais provas.';
$st = $pdo->prepare("UPDATE case_andamentos SET descricao = ? WHERE id = 12010");
$st->execute(array($novo));
echo "✓ Andamento #12010 corrigido (autora -> autor).\n";

$check = $pdo->query("SELECT descricao FROM case_andamentos WHERE id = 12010")->fetchColumn();
echo "Conteudo atual: " . $check . "\n";
