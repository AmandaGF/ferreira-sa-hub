<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

$origem = 55; $destino = 2;

echo "=== Mesclando conv #{$origem} → #{$destino} ===\n\n";

$cO = $pdo->query("SELECT * FROM zapi_conversas WHERE id = {$origem}")->fetch();
$cD = $pdo->query("SELECT * FROM zapi_conversas WHERE id = {$destino}")->fetch();
if (!$cO) { echo "Conv origem não existe\n"; exit; }
if (!$cD) { echo "Conv destino não existe\n"; exit; }

$n = (int)$pdo->query("SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = {$origem}")->fetchColumn();
echo "Origem  #{$origem}: canal={$cO['canal']} tel={$cO['telefone']} nome={$cO['nome_contato']} — {$n} msgs\n";
echo "Destino #{$destino}: canal={$cD['canal']} tel={$cD['telefone']} nome={$cD['nome_contato']}\n\n";

try {
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE zapi_mensagens SET conversa_id = ? WHERE conversa_id = ?")->execute(array($destino, $origem));
    try {
        $pdo->prepare("UPDATE IGNORE zapi_conversa_etiquetas SET conversa_id = ? WHERE conversa_id = ?")->execute(array($destino, $origem));
        $pdo->prepare("DELETE FROM zapi_conversa_etiquetas WHERE conversa_id = ?")->execute(array($origem));
    } catch (Exception $e) {}
    if (empty($cD['client_id']) && !empty($cO['client_id'])) {
        $pdo->prepare("UPDATE zapi_conversas SET client_id = ? WHERE id = ?")->execute(array($cO['client_id'], $destino));
    }
    $pdo->prepare("DELETE FROM zapi_conversas WHERE id = ?")->execute(array($origem));
    $pdo->prepare("UPDATE zapi_conversas SET ultima_msg_em = (SELECT MAX(created_at) FROM zapi_mensagens WHERE conversa_id = ?) WHERE id = ?")->execute(array($destino, $destino));
    $pdo->commit();
    echo "✓ MESCLADO — {$n} mensagens migradas, conv #{$origem} apagada.\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "✗ ERRO: " . $e->getMessage() . "\n";
}
