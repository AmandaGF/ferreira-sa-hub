<?php
/**
 * Fix one-shot: Renata pos-merge das conversas.
 *
 * Estado atual:
 * - cliente #2412 (Renata da Silva de Amorim, phone=+34661457631, intl=0)
 * - conv#1399 tel=5534661457631 (BR errado), client_id=NULL, 29 msgs
 * - conv#1991 (que tinha tel correto 34661457631) sumiu no merge
 *
 * Acoes:
 * 1. UPDATE conv#1399 SET client_id=2412 (vincula)
 * 2. UPDATE conv#1399 SET telefone='34661457631' (corrige -- DDI 34 Espanha)
 * 3. UPDATE clients#2412 SET is_internacional=1
 * 4. audit_log de cada
 *
 * Uso: ?key=XXX             -> PREVIEW
 *      ?key=XXX&confirm=1   -> APLICA
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$confirm = !empty($_GET['confirm']);

$CONV_ID = 1399;
$CLIENT_ID = 2412;
$TEL_CORRETO = '34661457631';
$TEL_ERRADO  = '5534661457631';

echo "=== ESTADO ATUAL ===\n";

$st = $pdo->prepare("SELECT id, telefone, client_id, nome_contato, instancia_id FROM zapi_conversas WHERE id = ?");
$st->execute(array($CONV_ID));
$conv = $st->fetch();
if (!$conv) { echo "conv#$CONV_ID nao encontrada.\n"; exit; }

echo "conv#" . $conv['id'] . ": tel=" . $conv['telefone']
   . " client_id=" . ($conv['client_id'] ?: 'NULL')
   . " nome_contato=" . ($conv['nome_contato'] ?: '—')
   . " inst=" . $conv['instancia_id'] . "\n";

$stCli = $pdo->prepare("SELECT id, name, phone, is_internacional FROM clients WHERE id = ?");
$stCli->execute(array($CLIENT_ID));
$cli = $stCli->fetch();
if (!$cli) { echo "client#$CLIENT_ID nao encontrado.\n"; exit; }
echo "client#" . $cli['id'] . ": " . $cli['name'] . " phone=" . $cli['phone']
   . " is_internacional=" . $cli['is_internacional'] . "\n\n";

// Verifica colisao: ja existe outra conv com tel=34661457631 na mesma instancia?
$stColl = $pdo->prepare("SELECT id FROM zapi_conversas WHERE telefone = ? AND instancia_id = ? AND id != ?");
$stColl->execute(array($TEL_CORRETO, $conv['instancia_id'], $CONV_ID));
$collId = $stColl->fetchColumn();
if ($collId) {
    echo "[AVISO] COLISAO: ja existe conv#" . $collId . " com tel=$TEL_CORRETO na instancia " . $conv['instancia_id'] . "\n";
    echo "Provavel raiz do bug: voce talvez tenha CRIADO uma nova conv com tel correto quando enviou msg, e depois mergeou ela na antiga (errada).\n";
    echo "Resolucao manual: mesclar essa conv#$collId em conv#$CONV_ID antes de aplicar este fix.\n\n";
}

echo "=== ALTERACOES PROPOSTAS ===\n";
echo "1. UPDATE zapi_conversas SET client_id = $CLIENT_ID WHERE id = $CONV_ID  (vincula)\n";
echo "2. UPDATE zapi_conversas SET telefone = '$TEL_CORRETO' WHERE id = $CONV_ID  (corrige tel)\n";
echo "3. UPDATE clients SET is_internacional = 1 WHERE id = $CLIENT_ID  (marca intl)\n\n";

if (!$confirm) { echo "[PREVIEW] Pra aplicar, adicione &confirm=1\n"; exit; }
if ($collId) { echo "[ABORTADO] Resolva a colisao com conv#$collId antes.\n"; exit; }

try {
    $pdo->beginTransaction();

    $pdo->prepare("UPDATE zapi_conversas SET client_id = ? WHERE id = ?")
        ->execute(array($CLIENT_ID, $CONV_ID));
    echo "  [1] OK client_id\n";

    $pdo->prepare("UPDATE zapi_conversas SET telefone = ?, updated_at = NOW() WHERE id = ?")
        ->execute(array($TEL_CORRETO, $CONV_ID));
    echo "  [2] OK telefone\n";

    $pdo->prepare("UPDATE clients SET is_internacional = 1, updated_at = NOW() WHERE id = ?")
        ->execute(array($CLIENT_ID));
    echo "  [3] OK is_internacional\n";

    try {
        $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, created_at) VALUES (0, 'fix_renata_pos_merge', 'zapi_conversa', ?, ?, NOW())")
            ->execute(array($CONV_ID, "vinculou client=$CLIENT_ID tel: $TEL_ERRADO -> $TEL_CORRETO + cliente marcado intl=1"));
    } catch (Exception $eA) {}

    $pdo->commit();
    echo "\n=== CONCLUIDO ===\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "[ERRO] " . $e->getMessage() . "\n";
}
