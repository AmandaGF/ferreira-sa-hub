<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== FIX CONV 686 — desvincula da Alícia e arquiva ===\n\n";

$q = $pdo->prepare("SELECT id, client_id, status, chat_lid, telefone FROM zapi_conversas WHERE id = 686");
$q->execute();
$antes = $q->fetch();
echo "ANTES:\n";
print_r($antes);

if (!$antes) { echo "conv 686 não existe\n"; exit; }

// Desvincular e arquivar
$pdo->prepare("UPDATE zapi_conversas SET client_id = NULL, status = 'arquivado', nome_contato = NULL WHERE id = 686")->execute();

$q->execute();
$depois = $q->fetch();
echo "\nDEPOIS:\n";
print_r($depois);

echo "\n[OK] Conv 686 desvinculada da Alícia e arquivada.\n";
echo "     Motivo: chat_lid 99037785145538 estava atrelado erroneamente ao client 331.\n";
echo "     As mensagens dentro dela continuam gravadas (histórico preservado),\n";
echo "     mas ela não aparece mais como conversa da Alícia no Hub.\n";
