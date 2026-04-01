<?php
/**
 * Limpa registros de "pastas" que foram importados como clientes.
 * 1. Migra certidões/links para portal_links
 * 2. Revíncula processos ao cliente real (pelo nome antes do " x ")
 * 3. Exclui os 87 registros falsos de clients
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(120);

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

$pdo = db();
$dryRun = !isset($_GET['executar']);
echo "=== LIMPAR PASTAS DO CADASTRO DE CLIENTES ===\n";
echo $dryRun ? ">>> MODO SIMULAÇÃO <<<\n\n" : ">>> EXECUTANDO <<<\n\n";

// ═══════════════════════════════════════════════
// PASSO 1: Migrar links/certidões para portal_links
// ═══════════════════════════════════════════════
echo "--- PASSO 1: Migrar links/certidões para portal_links ---\n\n";

$linkIds = array(400, 562, 563, 681);
$linkData = array(
    400 => array(
        'category' => 'Institucional',
        'title' => 'Academia Militar das Agulhas Negras - AMAN',
        'url' => '',
        'description' => 'Referência institucional migrada do cadastro de clientes',
    ),
    562 => array(
        'category' => 'Certidões',
        'title' => 'Certidão Negativa de Débitos - Dívida Ativa Estadual RJ',
        'url' => '',
        'description' => 'Certidão negativa de débitos inscritos na Dívida Ativa Estadual - RJ',
    ),
    563 => array(
        'category' => 'Certidões',
        'title' => 'Certidão Negativa Imobiliária - Porto Real',
        'url' => '',
        'description' => 'Certidão negativa imobiliária da comarca de Porto Real',
    ),
    681 => array(
        'category' => 'Institucional',
        'title' => 'Escola Superior de Cruzeiro - Pref. Hamilton Vieira Mendes',
        'url' => '',
        'description' => 'Referência institucional migrada do cadastro de clientes (parte em processo)',
    ),
);

foreach ($linkData as $id => $data) {
    // Verificar se já existe no portal
    $chk = $pdo->prepare("SELECT id FROM portal_links WHERE title = ? LIMIT 1");
    $chk->execute(array($data['title']));
    if ($chk->fetch()) {
        echo "  [JÁ EXISTE] {$data['title']}\n";
        continue;
    }

    if ($dryRun) {
        echo "  [MIGRAR] #{$id} → portal_links: {$data['title']} ({$data['category']})\n";
    } else {
        $pdo->prepare(
            "INSERT INTO portal_links (category, title, url, description, audience, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'todos', 99, NOW(), NOW())"
        )->execute(array($data['category'], $data['title'], $data['url'], $data['description']));
        echo "  [OK] #{$id} → portal_links: {$data['title']}\n";
    }
}
echo "\n";

// ═══════════════════════════════════════════════
// PASSO 2: Revincular processos ao cliente real
// ═══════════════════════════════════════════════
echo "--- PASSO 2: Revincular processos ao cliente real ---\n\n";

// Esses 6 clientes-pasta têm processos vinculados
$casosVinculados = array(
    2282 => 'Leonardo Tavares',           // BIBI - Leonardo Tavares
    2283 => 'LUCIENE BERTEGES',            // LUCIENE BERTEGES X APOSENTADORIA
    2284 => 'Monique Elaine Oliveira Mateus', // X LOAS
    2285 => 'Monique Elaine Oliveira Mateus', // X LOAS MARCELA (mesmo cliente)
    2287 => 'LEONARDO TAVARES FERREIRA',   // X CONSUMIDOR
    2288 => 'Maria Eduarda da Silva Sousa', // x Guarda Unilateral (corrigido typo "Sousax")
);

foreach ($casosVinculados as $falseClientId => $realName) {
    // Buscar cliente real pelo nome
    $stmt = $pdo->prepare("SELECT id, name FROM clients WHERE name LIKE ? AND id != ? ORDER BY id LIMIT 1");
    $stmt->execute(array('%' . $realName . '%', $falseClientId));
    $realClient = $stmt->fetch();

    // Buscar processos vinculados ao falso
    $stmtCases = $pdo->prepare("SELECT id, title FROM cases WHERE client_id = ?");
    $stmtCases->execute(array($falseClientId));
    $linkedCases = $stmtCases->fetchAll();

    if (empty($linkedCases)) {
        echo "  [SEM CASOS] #{$falseClientId} ($realName) — nenhum caso\n";
        continue;
    }

    if ($realClient) {
        foreach ($linkedCases as $cs) {
            if ($dryRun) {
                echo "  [REVINCULAR] Caso #{$cs['id']} '{$cs['title']}' → cliente #{$realClient['id']} '{$realClient['name']}'\n";
            } else {
                $pdo->prepare("UPDATE cases SET client_id = ? WHERE id = ?")->execute(array($realClient['id'], $cs['id']));
                echo "  [OK] Caso #{$cs['id']} → #{$realClient['id']} ({$realClient['name']})\n";
            }
        }
    } else {
        // Não encontrou cliente real — criar um
        echo "  [AVISO] Cliente real '$realName' não encontrado. ";
        if ($dryRun) {
            echo "Criaria novo cliente.\n";
        } else {
            $pdo->prepare("INSERT INTO clients (name, source, notes, created_at) VALUES (?, 'pipeline', 'Criado automaticamente na limpeza de pastas', NOW())")
                ->execute(array($realName));
            $newClientId = (int)$pdo->lastInsertId();
            foreach ($linkedCases as $cs) {
                $pdo->prepare("UPDATE cases SET client_id = ? WHERE id = ?")->execute(array($newClientId, $cs['id']));
            }
            echo "Criado #{$newClientId}. Casos revinculados.\n";
        }
    }
}
echo "\n";

// ═══════════════════════════════════════════════
// PASSO 3: Excluir os 87 registros falsos
// ═══════════════════════════════════════════════
echo "--- PASSO 3: Excluir registros falsos de clients ---\n\n";

$allFalseIds = array(
    400, 562, 563, 681,
    1562, 1563, 1565, 1566, 1567, 1568, 1569, 1570, 1571, 1572, 1573, 1574, 1575, 1576,
    1577, 1578, 1579, 1580, 1581, 1582, 1583, 1584, 1585, 1586, 1587, 1588, 1589, 1590,
    1591, 1592, 1593, 1594, 1595, 1596, 1597, 1598, 1599, 1600, 1601, 1602, 1603, 1604,
    1605, 1606, 1607, 1608, 1609, 1610, 1611, 1612, 1613, 1614, 1615, 1616, 1617, 1618,
    1619, 1620, 1621, 1622, 1623, 1624, 1625, 1626, 1627, 1628, 1629, 1630, 1631, 1632,
    1633, 1634, 1635, 1636, 1637, 1638, 1641,
    2282, 2283, 2284, 2285, 2287, 2288,
);

$antes = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
echo "Total clientes ANTES: $antes\n";

// Desvincular form_submissions e pipeline_leads antes de excluir
if ($dryRun) {
    echo "Desvincularia form_submissions e pipeline_leads\n";
    echo "Excluiria " . count($allFalseIds) . " registros de clients\n";
} else {
    $placeholders = implode(',', array_fill(0, count($allFalseIds), '?'));

    // Desvincular form_submissions
    $pdo->prepare("UPDATE form_submissions SET linked_client_id = NULL WHERE linked_client_id IN ($placeholders)")
        ->execute($allFalseIds);

    // Desvincular pipeline_leads
    $pdo->prepare("UPDATE pipeline_leads SET client_id = NULL WHERE client_id IN ($placeholders)")
        ->execute($allFalseIds);

    // Excluir clientes falsos
    $deleted = $pdo->prepare("DELETE FROM clients WHERE id IN ($placeholders)");
    $deleted->execute($allFalseIds);
    $deletedCount = $deleted->rowCount();

    $depois = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
    echo "Excluídos: $deletedCount\n";
    echo "Total clientes DEPOIS: $depois\n";
}

echo "\n=== RESUMO ===\n";
echo "Links migrados para portal: " . count($linkIds) . "\n";
echo "Processos revinculados: " . count($casosVinculados) . " clientes verificados\n";
echo "Registros a excluir: " . count($allFalseIds) . "\n";
if ($dryRun) echo "\n>>> Para executar: adicione &executar <<<\n";
echo "\n=== FIM ===\n";
