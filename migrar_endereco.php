<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migração: Atualizar endereço do escritório ===\n\n";

// 1. Atualizar templates de mensagens WhatsApp
$old = 'Av. Albino de Almeida, 119 - Salas 201/202, Campos Elíseos, Resende/RJ';
$new = 'Rua Dr. Aldrovando de Oliveira, 140 - Ano Bom, Barra Mansa/RJ';
try {
    $stmt = $pdo->prepare("UPDATE mensagens_templates SET corpo = REPLACE(corpo, ?, ?) WHERE corpo LIKE ?");
    $stmt->execute(array($old, $new, '%Albino%'));
    echo "[OK] Templates mensagens: " . $stmt->rowCount() . " atualizados\n";
} catch (Exception $e) {
    echo "[INFO] mensagens_templates: " . $e->getMessage() . "\n";
}

// 2. Atualizar portal_links (descrição com endereço antigo)
try {
    $stmt = $pdo->prepare("UPDATE portal_links SET description = REPLACE(description, 'Albino de Almeida', 'Dr. Aldrovando de Oliveira') WHERE description LIKE '%Albino%'");
    $stmt->execute();
    echo "[OK] Portal links (Albino→Aldrovando): " . $stmt->rowCount() . " atualizados\n";

    $stmt = $pdo->prepare("UPDATE portal_links SET description = REPLACE(description, '119', '140') WHERE description LIKE '%Aldrovando%'");
    $stmt->execute();

    $stmt = $pdo->prepare("UPDATE portal_links SET description = REPLACE(description, 'Campos Elíseos', 'Ano Bom') WHERE description LIKE '%Aldrovando%'");
    $stmt->execute();

    $stmt = $pdo->prepare("UPDATE portal_links SET description = REPLACE(description, 'Resende', 'Barra Mansa') WHERE description LIKE '%Aldrovando%'");
    $stmt->execute();

    $stmt = $pdo->prepare("UPDATE portal_links SET title = REPLACE(title, 'Resende', 'Barra Mansa') WHERE title LIKE '%Resende%' AND title LIKE '%Sede%'");
    $stmt->execute();

    echo "[OK] Portal links: endereço completo atualizado\n";
} catch (Exception $e) {
    echo "[INFO] portal_links: " . $e->getMessage() . "\n";
}

echo "\n=== FIM ===\n";
