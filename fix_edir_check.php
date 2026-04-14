<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Vincular partes a clientes pelo CPF (GLOBAL) ===\n\n";

// Buscar TODAS as partes que têm CPF mas não têm client_id
$stmt = $pdo->query("
    SELECT cp.id, cp.case_id, cp.nome, cp.cpf, cp.client_id
    FROM case_partes cp
    WHERE cp.cpf IS NOT NULL AND cp.cpf != '' AND (cp.client_id IS NULL OR cp.client_id = 0)
");
$partes = $stmt->fetchAll();
echo "Partes com CPF sem client_id: " . count($partes) . "\n";

$vinculados = 0;
foreach ($partes as $p) {
    $cpfLimpo = preg_replace('/\D/', '', $p['cpf']);
    if (strlen($cpfLimpo) < 11) continue;

    $stmtCli = $pdo->prepare("SELECT id, name FROM clients WHERE REPLACE(REPLACE(cpf,'.',''),'-','') = ? LIMIT 1");
    $stmtCli->execute(array($cpfLimpo));
    $cli = $stmtCli->fetch();

    if ($cli) {
        if (isset($_GET['fix'])) {
            $pdo->prepare("UPDATE case_partes SET client_id = ? WHERE id = ?")->execute(array($cli['id'], $p['id']));
        }
        echo "  Parte #{$p['id']} ({$p['nome']}) => Client #{$cli['id']} ({$cli['name']})\n";
        $vinculados++;
    }
}

echo "\nTotal vinculados: {$vinculados}\n";
if (!isset($_GET['fix'])) echo "\nAdicione &fix=1 para aplicar.\n";
else echo "\n=== APLICADO ===\n";
