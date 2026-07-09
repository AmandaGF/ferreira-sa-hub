<?php
/**
 * Backfill: prazos_processuais com case_id NULL mas numero_processo preenchido
 * → tenta achar case pelo CNJ (dígitos normalizados) e faz UPDATE.
 * Idempotente. Roda uma vez.
 * URL: /conecta/backfill_prazos_orfaos.php?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors','1');
$pdo = db();

echo "=== BACKFILL prazos_processuais orfaos ===\n\n";

$st = $pdo->query(
    "SELECT id, numero_processo, descricao_acao, prazo_fatal
     FROM prazos_processuais
     WHERE case_id IS NULL
       AND numero_processo IS NOT NULL AND numero_processo != ''
     ORDER BY id"
);
$orfaos = $st->fetchAll(PDO::FETCH_ASSOC);
echo "Orfaos encontrados: " . count($orfaos) . "\n\n";

$vinculados = 0; $naoAchou = 0; $ambiguos = 0;
$upd = $pdo->prepare("UPDATE prazos_processuais SET case_id = ? WHERE id = ?");
$stFind = $pdo->prepare(
    "SELECT id, title, status FROM cases
     WHERE REPLACE(REPLACE(REPLACE(case_number,'.',''),'-',''),'/','') = ?
     ORDER BY CASE WHEN status IN ('arquivado','cancelado','concluido','finalizado') THEN 2 ELSE 1 END, id"
);
foreach ($orfaos as $p) {
    $digitos = preg_replace('/\D/', '', (string)$p['numero_processo']);
    if (strlen($digitos) < 15) {
        echo "  #{$p['id']} DIG_CURTO: '{$p['numero_processo']}' — pulado\n";
        $naoAchou++;
        continue;
    }
    $stFind->execute(array($digitos));
    $matches = $stFind->fetchAll(PDO::FETCH_ASSOC);
    if (!$matches) {
        echo "  #{$p['id']} NENHUM CASE achado pra '{$p['numero_processo']}'\n";
        $naoAchou++;
        continue;
    }
    // Se tem mais de um, ORDER BY prioriza NÃO-arquivados
    if (count($matches) > 1) {
        echo "  #{$p['id']} AMBIGUO ({" . count($matches) . " matches}) — usando ativo #{$matches[0]['id']} ({$matches[0]['status']})\n";
        $ambiguos++;
    }
    $caseId = (int)$matches[0]['id'];
    $upd->execute(array($caseId, $p['id']));
    echo "  #{$p['id']} → case #{$caseId} ({$matches[0]['title']}) [status={$matches[0]['status']}]\n";
    echo "     descricao: " . mb_substr($p['descricao_acao'], 0, 100) . "\n";
    $vinculados++;
}

echo "\n=== RESUMO ===\n";
echo "Total orfaos: " . count($orfaos) . "\n";
echo "Vinculados: $vinculados\n";
echo "Ambiguos (usou ativo): $ambiguos\n";
echo "Nao achou case: $naoAchou\n";
echo "\nAgora esses prazos devem aparecer na aba Prazos da pasta correspondente.\n";
