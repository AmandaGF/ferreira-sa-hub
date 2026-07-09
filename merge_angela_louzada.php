<?php
/**
 * MERGE 09/07/2026 — Angela de Oliveira Louzada
 * Principal: #480 (Sobral, tem CPF)
 * Secundario: #1543 (Costa, telefone diferente)
 *
 * Roda uma vez: /conecta/merge_angela_louzada.php?key=fsa-hub-deploy-2026
 * Segundo run: reporta que o secundario ja foi apagado.
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }

header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors', '1');
$pdo = db();

$PRINCIPAL_ID  = 480;
$SECUNDARIO_ID = 1543;

echo "=== MERGE Angela Louzada #$SECUNDARIO_ID -> #$PRINCIPAL_ID ===\n\n";

// Confirmar existencia
$stP = $pdo->prepare("SELECT id, name, phone, phone2 FROM clients WHERE id = ?");
$stP->execute(array($PRINCIPAL_ID));
$pRow = $stP->fetch(PDO::FETCH_ASSOC);
$stS = $pdo->prepare("SELECT id, name, phone, phone2 FROM clients WHERE id = ?");
$stS->execute(array($SECUNDARIO_ID));
$sRow = $stS->fetch(PDO::FETCH_ASSOC);

if (!$pRow) { echo "PRINCIPAL #$PRINCIPAL_ID nao existe. Abortando.\n"; exit; }
if (!$sRow) { echo "SECUNDARIO #$SECUNDARIO_ID ja foi apagado. Nada a fazer.\n"; exit; }

echo "Principal: {$pRow['name']} (phone={$pRow['phone']} / phone2=" . ($pRow['phone2'] ?: '-') . ")\n";
echo "Secundario: {$sRow['name']} (phone={$sRow['phone']} / phone2=" . ($sRow['phone2'] ?: '-') . ")\n\n";

$pdo->beginTransaction();
try {
    // 1) Preservar phone do secundario como phone2 do principal (se vazio)
    if (empty($pRow['phone2']) && !empty($sRow['phone'])) {
        // Normaliza (remove '55' inicial se houver + tira .0 do phone2 do secundario)
        $phSec = preg_replace('/\D/', '', (string)$sRow['phone']);
        if (strlen($phSec) === 13 && substr($phSec, 0, 2) === '55') {
            $phSec = substr($phSec, 2); // remove country code
        }
        $pdo->prepare("UPDATE clients SET phone2 = ?, updated_at = NOW() WHERE id = ?")
            ->execute(array($phSec, $PRINCIPAL_ID));
        echo "  ✓ phone2 do principal preenchido com $phSec (do secundario)\n";
    } else {
        echo "  · phone2 do principal ja preenchido (mantido): " . ($pRow['phone2'] ?: '-') . "\n";
    }

    // 2) Migrar FKs (cases, leads, conversas, notificacoes)
    $tabsFk = array(
        'cases'                => 'client_id',
        'pipeline_leads'       => 'client_id',
        'zapi_conversas'       => 'client_id',
        'notificacoes_cliente' => 'client_id',
    );
    foreach ($tabsFk as $tab => $col) {
        try {
            $st = $pdo->prepare("UPDATE `$tab` SET `$col` = ? WHERE `$col` = ?");
            $st->execute(array($PRINCIPAL_ID, $SECUNDARIO_ID));
            echo "  ✓ $tab.$col: {$st->rowCount()} linha(s) migrada(s)\n";
        } catch (Throwable $e) {
            echo "  ✗ $tab.$col: ERRO " . $e->getMessage() . "\n";
        }
    }

    // 3) Auditoria
    try {
        $pdo->prepare("INSERT INTO audit_log (user_id, acao, entidade, entidade_id, detalhes, created_at) VALUES (?,?,?,?,?,NOW())")
            ->execute(array(1, 'client_merge', 'client', $PRINCIPAL_ID,
                "merge cliente #$SECUNDARIO_ID ({$sRow['name']}) -> #$PRINCIPAL_ID ({$pRow['name']})"));
        echo "  ✓ audit_log registrado\n";
    } catch (Throwable $e) { echo "  · audit_log falhou (nao critico): " . $e->getMessage() . "\n"; }

    // 4) Notes: registrar no principal que veio de merge (rastreabilidade)
    try {
        $stN = $pdo->prepare("SELECT notes FROM clients WHERE id = ?");
        $stN->execute(array($PRINCIPAL_ID));
        $notesAtuais = (string)$stN->fetchColumn();
        $carimbo = "\n[merge 09/07/2026] Consolidado com cliente #$SECUNDARIO_ID ({$sRow['name']}) — telefone secundario preservado em phone2.";
        if (strpos($notesAtuais, "[merge 09/07/2026]") === false) {
            $novoNotes = trim($notesAtuais . $carimbo);
            $pdo->prepare("UPDATE clients SET notes = ?, updated_at = NOW() WHERE id = ?")
                ->execute(array($novoNotes, $PRINCIPAL_ID));
            echo "  ✓ notes do principal atualizado com carimbo de merge\n";
        }
    } catch (Throwable $e) {}

    // 5) DELETE do secundario
    $pdo->prepare("DELETE FROM clients WHERE id = ?")->execute(array($SECUNDARIO_ID));
    echo "  ✓ cliente #$SECUNDARIO_ID DELETADO\n";

    $pdo->commit();
    echo "\n=== MERGE CONCLUIDO ===\n";
    echo "Cliente consolidado: #$PRINCIPAL_ID (Angela de Oliveira Louzada Sobral)\n";
    echo "Ver: /conecta/modules/clientes/ver.php?id=$PRINCIPAL_ID\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "\n=== ERRO — TRANSACAO REVERTIDA ===\n" . $e->getMessage() . "\n";
    exit;
}
