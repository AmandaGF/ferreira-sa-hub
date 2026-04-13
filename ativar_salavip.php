<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Ativar todos os processos do cliente 447 (Amanda) na Sala VIP
$updated = $pdo->exec("UPDATE cases SET salavip_ativo = 1 WHERE client_id = 447 AND status NOT IN ('cancelado','arquivado')");
echo "Processos ativados na Sala VIP: $updated\n\n";

$rows = $pdo->query("SELECT id, title, status, salavip_ativo FROM cases WHERE client_id = 447 ORDER BY id DESC")->fetchAll();
foreach ($rows as $r) {
    echo "#" . $r['id'] . " salavip=" . $r['salavip_ativo'] . " status=" . $r['status'] . " :: " . $r['title'] . "\n";
}
