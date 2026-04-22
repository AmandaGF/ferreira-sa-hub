<?php
/**
 * zerar_whatsapp_badges.php
 *
 * Zera o contador nao_lidas de todas as conversas WhatsApp.
 * Usar 1 vez pra resetar os badges acumulados pré-migração.
 * Depois, só mensagens realmente novas contam.
 *
 * URL: /conecta/zerar_whatsapp_badges.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida.'); }
require_once __DIR__ . '/core/database.php';

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Zerar badges WhatsApp ===\n\n";

try {
    // Conta antes
    $antes = $pdo->query("SELECT
        SUM(CASE WHEN canal='21' THEN nao_lidas ELSE 0 END) AS c21,
        SUM(CASE WHEN canal='24' THEN nao_lidas ELSE 0 END) AS c24,
        COUNT(*) AS total_convs,
        SUM(CASE WHEN nao_lidas > 0 THEN 1 ELSE 0 END) AS convs_com_nao_lidas
    FROM zapi_conversas")->fetch();

    echo "Antes:\n";
    echo "  Badge Comercial (21): " . (int)$antes['c21'] . "\n";
    echo "  Badge CX/Operac (24): " . (int)$antes['c24'] . "\n";
    echo "  Total de conversas: " . (int)$antes['total_convs'] . "\n";
    echo "  Conversas com nao_lidas > 0: " . (int)$antes['convs_com_nao_lidas'] . "\n\n";

    // Zera
    $stmt = $pdo->prepare("UPDATE zapi_conversas SET nao_lidas = 0 WHERE nao_lidas > 0");
    $stmt->execute();
    $afetadas = $stmt->rowCount();

    echo "Zeradas: {$afetadas} conversas\n\n";

    // Confirma
    $depois = $pdo->query("SELECT
        SUM(CASE WHEN canal='21' THEN nao_lidas ELSE 0 END) AS c21,
        SUM(CASE WHEN canal='24' THEN nao_lidas ELSE 0 END) AS c24
    FROM zapi_conversas")->fetch();

    echo "Depois:\n";
    echo "  Badge Comercial (21): " . (int)$depois['c21'] . "\n";
    echo "  Badge CX/Operac (24): " . (int)$depois['c24'] . "\n\n";

    echo "✅ Pronto. Recarrega o Hub (F5) que os badges vão zerar.\n";
    echo "A partir de agora, só mensagens novas contam.\n";
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
}
