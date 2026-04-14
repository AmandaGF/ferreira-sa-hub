<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Buscar caso Edir e Elias
$stmt = $pdo->query("SELECT id, title, client_id FROM cases WHERE title LIKE '%Edir%Elias%' OR title LIKE '%Elias%Edir%'");
$cases = $stmt->fetchAll();
echo "Casos:\n";
foreach ($cases as $c) {
    echo "  Case #{$c['id']} | {$c['title']} | client_id={$c['client_id']}\n";
    // Partes
    $parts = $pdo->prepare("SELECT id, papel, nome, cpf, client_id FROM case_partes WHERE case_id = ?");
    $parts->execute(array($c['id']));
    foreach ($parts->fetchAll() as $p) {
        echo "    Parte #{$p['id']} | {$p['papel']} | {$p['nome']} | CPF={$p['cpf']} | client_id={$p['client_id']}\n";
    }
}

// Buscar clientes Edir e Elias
$stmt2 = $pdo->query("SELECT id, name, cpf FROM clients WHERE name LIKE '%Edir%' OR name LIKE '%Elias Soares%'");
echo "\nClientes:\n";
foreach ($stmt2->fetchAll() as $cl) {
    echo "  Client #{$cl['id']} | {$cl['name']} | CPF={$cl['cpf']}\n";
}
