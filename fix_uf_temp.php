<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
try {
    $pdo = db();

    // UFs com problemas (não são exatamente 2 letras ou estão incorretos)
    $stmt = $pdo->query("
        SELECT id, name, address_state, address_city
        FROM clients
        WHERE address_state IS NOT NULL AND address_state != ''
        AND LENGTH(address_state) != 2
        ORDER BY address_state
    ");
    $bad = $stmt->fetchAll();
    echo "UFs com tamanho != 2: " . count($bad) . "\n";
    foreach ($bad as $r) {
        echo "  ID={$r['id']} | {$r['name']} | UF=\"{$r['address_state']}\" | Cidade={$r['address_city']}\n";
    }

    // UFs com valor estranho (lowercase, etc)
    $stmt2 = $pdo->query("
        SELECT DISTINCT address_state, COUNT(*) as qtd
        FROM clients
        WHERE address_state IS NOT NULL AND address_state != ''
        GROUP BY address_state
        ORDER BY address_state
    ");
    echo "\n\nDistribuição de UFs:\n";
    foreach ($stmt2->fetchAll() as $r) {
        echo "  \"{$r['address_state']}\" => {$r['qtd']} clientes\n";
    }

    if (isset($_GET['fix'])) {
        // Corrigir UFs truncados ou em lowercase
        $fixes = [
            'Ri' => 'RJ', 'ri' => 'RJ', 'rj' => 'RJ',
            'Sp' => 'SP', 'sp' => 'SP',
            'Mg' => 'MG', 'mg' => 'MG',
            'Rio de Janeiro' => 'RJ', 'Rio de janeiro' => 'RJ',
            'São Paulo' => 'SP', 'Sao Paulo' => 'SP',
            'Minas Gerais' => 'MG',
            'Sã' => 'SP',
        ];
        $total = 0;
        foreach ($fixes as $wrong => $right) {
            $n = $pdo->exec("UPDATE clients SET address_state = '{$right}' WHERE address_state = '{$wrong}'");
            if ($n > 0) {
                echo "\nCorrigido: \"{$wrong}\" -> \"{$right}\": {$n} registro(s)";
                $total += $n;
            }
        }
        echo "\n\n=== TOTAL CORRIGIDOS: {$total} ===\n";
    } else {
        echo "\nAdicione &fix=1 para corrigir.\n";
    }
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}
