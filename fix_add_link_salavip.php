<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
try {
    $pdo = db();
    // Verificar se já existe
    $exists = $pdo->query("SELECT id FROM portal_links WHERE url LIKE '%salavip%' LIMIT 1")->fetchColumn();
    if ($exists) {
        echo "Link Sala VIP já existe (ID={$exists}).\n";
    } else {
        $pdo->prepare(
            "INSERT INTO portal_links (title, category, url, username, password_encrypted, hint, audience, is_favorite, sort_order, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            'Sala VIP F&S — Portal do Cliente',
            'Sistemas',
            'https://ferreiraesa.com.br/salavip/',
            null, null, 'Portal de acompanhamento para clientes',
            'todos', 1, 0, 1
        ]);
        echo "Link Sala VIP adicionado com sucesso! ID=" . $pdo->lastInsertId() . "\n";
    }
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}
