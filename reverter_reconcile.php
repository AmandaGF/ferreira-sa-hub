<?php
ini_set('display_errors','1'); error_reporting(E_ALL);
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== REVERTER reconciliações erradas (audit_log com action=reconcile_lead/reconcile_case) ===\n\n";

// Buscar todas as entradas do audit_log da última hora
$rows = $pdo->query("
    SELECT id, action, entity_type, entity_id, details, created_at
    FROM audit_log
    WHERE action IN ('reconcile_lead','reconcile_case')
      AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ORDER BY id ASC
")->fetchAll();

echo "Encontradas " . count($rows) . " ações de reconciliação para reverter.\n\n";

$apply = isset($_GET['apply']);

foreach ($rows as $r) {
    // Parsear details: "fromState → toState (espelho do ...)"
    if (!preg_match('/^(\S+)\s*→\s*(\S+)/u', $r['details'], $m)) {
        echo "[SKIP] #{$r['id']} - não consegui parsear: {$r['details']}\n";
        continue;
    }
    $from = $m[1]; $to = $m[2];
    $entity = $r['entity_type']; $eid = $r['entity_id'];

    // Verificar estado atual
    if ($entity === 'lead') {
        $cur = $pdo->prepare("SELECT stage FROM pipeline_leads WHERE id=?");
        $cur->execute(array($eid));
        $now = $cur->fetchColumn();
        if ($now !== $to) {
            echo "[SKIP] Lead #$eid - estado mudou (atual=$now, esperado=$to)\n";
            continue;
        }
        echo "REVERT Lead #$eid: $to → $from\n";
        if ($apply) {
            $pdo->prepare("UPDATE pipeline_leads SET stage=?, updated_at=NOW() WHERE id=?")
                ->execute(array($from, $eid));
        }
    } else if ($entity === 'case') {
        $cur = $pdo->prepare("SELECT status FROM cases WHERE id=?");
        $cur->execute(array($eid));
        $now = $cur->fetchColumn();
        if ($now !== $to) {
            echo "[SKIP] Case #$eid - estado mudou (atual=$now, esperado=$to)\n";
            continue;
        }
        echo "REVERT Case #$eid: $to → $from\n";
        if ($apply) {
            $pdo->prepare("UPDATE cases SET status=?, updated_at=NOW() WHERE id=?")
                ->execute(array($from, $eid));
        }
    }
}

if (!$apply) {
    echo "\n>>> Modo simulação. Para aplicar, adicione &apply=1 na URL.\n";
} else {
    echo "\n>>> APLICADO.\n";
    // Marcar audit_log como revertido
    $pdo->exec("UPDATE audit_log SET details = CONCAT(details, ' [REVERTIDO]') WHERE action IN ('reconcile_lead','reconcile_case') AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) AND details NOT LIKE '%[REVERTIDO]%'");
}
