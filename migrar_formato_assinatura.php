<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$atual = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'zapi_signature_format'")->fetchColumn();
echo "Formato atual: " . ($atual ?: '(não configurado)') . "\n";

$novo = '*_{{atendente}}_*:';
if (!$atual) {
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?)")->execute(array('zapi_signature_format', $novo));
    echo "→ INSERIDO: $novo\n";
} elseif ($atual !== $novo) {
    $pdo->prepare("UPDATE configuracoes SET valor = ? WHERE chave = 'zapi_signature_format'")->execute(array($novo));
    echo "→ ATUALIZADO pra: $novo\n";
} else {
    echo "Já está no formato novo. Nada a fazer.\n";
}

echo "\nPró-teste: ativa 'Cliente vê assinatura: ON' e envia uma mensagem —\n";
echo "chegará no celular como: *Amanda Ferreira*: Boa noite\n";
