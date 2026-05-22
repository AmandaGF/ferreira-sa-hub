<?php
/**
 * ver_email_diag.php — Estado final do Email Monitor + reconciliação dos
 * pendentes cujo processo JÁ está cadastrado (marca status='cadastrado').
 * Uso: https://ferreiraesa.com.br/conecta/ver_email_diag.php?key=fsa-hub-deploy-2026
 * Script de uso único — remover do repo depois.
 */
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Negado.'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== ESTADO FINAL EMAIL MONITOR — " . date('d/m/Y H:i:s') . " ===\n\n";

// 1. Andamentos email_pje importados hoje
$hoje = (int)$pdo->query("SELECT COUNT(*) FROM case_andamentos WHERE tipo_origem='email_pje' AND DATE(created_at)=CURDATE()")->fetchColumn();
$tot  = (int)$pdo->query("SELECT COUNT(*) FROM case_andamentos WHERE tipo_origem='email_pje'")->fetchColumn();
echo "Andamentos via e-mail importados HOJE: $hoje\n";
echo "Andamentos via e-mail no total:        $tot\n";
echo "UIDs registrados (email_monitor_uids): " . (int)$pdo->query("SELECT COUNT(*) FROM email_monitor_uids")->fetchColumn() . "\n\n";

// 2. Reconcilia pendentes cujo CNJ já existe em cases -> status='cadastrado'
echo "── RECONCILIAÇÃO DE PENDENTES ──\n";
$pend = $pdo->query("SELECT id, case_number FROM email_monitor_pendentes WHERE status='pendente'")->fetchAll(PDO::FETCH_ASSOC);
$stmtCase = $pdo->prepare("SELECT id FROM cases WHERE case_number = ? LIMIT 1");
$stmtUpd  = $pdo->prepare("UPDATE email_monitor_pendentes SET status='cadastrado' WHERE id = ?");
$reconc = 0;
foreach ($pend as $p) {
    $stmtCase->execute(array($p['case_number']));
    $caseId = $stmtCase->fetchColumn();
    $stmtCase->closeCursor();
    if ($caseId) {
        $stmtUpd->execute(array($p['id']));
        echo "  ✓ {$p['case_number']} -> case #{$caseId} (saiu de pendentes)\n";
        $reconc++;
    }
}
echo "  $reconc pendente(s) reconciliado(s) — processo já estava cadastrado.\n";

$restantes = (int)$pdo->query("SELECT COUNT(*) FROM email_monitor_pendentes WHERE status='pendente'")->fetchColumn();
echo "\nPendentes que REALMENTE precisam de cadastro manual: $restantes\n";
echo "(processos que não existem no Hub — Amanda cadastra pela aba Pendentes)\n";

echo "\n=== FIM ===\n";
