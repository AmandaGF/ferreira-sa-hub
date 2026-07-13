<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Simular o que o waEditarTelefone faz — trocar telefone da conv 2984
$convId = 2984;
$novoTel = $_GET['novotel'] ?? '5563991229267'; // exemplo, DDD 63 Tocantins

echo "=== CONV #$convId ANTES ===\n";
foreach ($pdo->query("SELECT id, canal, telefone, nome_contato FROM zapi_conversas WHERE id = $convId") as $r) print_r($r);

// Replica logica do editar_conversa (linha 1215+)
$telOk = null;
$tDigits = preg_replace('/\D/', '', $novoTel);
$tDigits = ltrim($tDigits, '0');
if (preg_match('/^[1-9]\d{9,10}$/', $tDigits)) $tDigits = '55' . $tDigits;
if (!preg_match('/^55\d{10,11}$/', $tDigits)) {
    echo "\nERRO: Telefone invalido '$novoTel' -> '$tDigits'\n";
    exit;
}
$telOk = $tDigits;

// Verifica conflito
$canalConv = $pdo->query("SELECT canal FROM zapi_conversas WHERE id = $convId")->fetchColumn();
$stmtConf = $pdo->prepare("SELECT id, telefone, nome_contato, atendente_id, status, created_at FROM zapi_conversas WHERE canal = ? AND telefone = ? AND id != ?");
$stmtConf->execute(array($canalConv, $telOk, $convId));
$conflitos = $stmtConf->fetchAll(PDO::FETCH_ASSOC);
if ($conflitos) {
    echo "\n*** CONFLITO! Ja existe(m) " . count($conflitos) . " conversa(s) com esse tel no canal $canalConv: ***\n";
    foreach ($conflitos as $c) print_r($c);
    echo "\n=> Nativania estava tentando corrigir pra '$novoTel', mas o backend REJEITA porque ha outra conversa com esse numero.\n";
    echo "   Ela ve mensagem 'Ja existe outra conversa com esse numero no mesmo canal (#X). Considere mesclar ao inves de duplicar.'\n";
    exit;
}
echo "\nSEM conflito. Update seria feito com sucesso.\n";
