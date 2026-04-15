<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Limpar cache de CPF para forçar busca na base interna
$cpf = $_GET['cpf'] ?? '16591132708';
$n = $pdo->exec("DELETE FROM cpf_cache WHERE cpf = '{$cpf}'");
echo "Cache removido para CPF {$cpf}: {$n} registro(s)\n";

// Testar busca
require_once __DIR__ . '/core/functions.php';
$r = buscar_documento($cpf);
echo "\nResultado busca:\n";
echo "  Fonte: " . ($r['fonte'] ?? 'erro') . "\n";
if (isset($r['dados'])) {
    echo "  Nome: " . ($r['dados']['nome'] ?? '?') . "\n";
    echo "  Telefone: " . ($r['dados']['telefone'] ?? '?') . "\n";
    echo "  Endereço: " . ($r['dados']['endereco'] ?? '?') . "\n";
    echo "  Cidade: " . ($r['dados']['cidade'] ?? '?') . "\n";
    echo "  client_id: " . ($r['dados']['client_id'] ?? '?') . "\n";
}
