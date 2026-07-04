<?php
/** Diag: valida as queries/helpers do combo de processos. Apagar depois. */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/asaas_helper.php';
$pdo = db();
$ok = 0; $fail = 0;
function t($label, $fn) { global $ok, $fail;
    try { $fn(); echo "OK   $label\n"; $GLOBALS['ok']++; }
    catch (Throwable $e) { echo "FAIL $label -> " . $e->getMessage() . "\n"; $GLOBALS['fail']++; }
}

t('tabela asaas_cobranca_cases existe', function() use ($pdo) {
    $pdo->query("SELECT COUNT(*) FROM asaas_cobranca_cases")->fetchColumn();
});

t('subquery pipeline (nested EXISTS)', function() use ($pdo) {
    $pdo->query("SELECT
        (SELECT COUNT(*) FROM asaas_cobrancas ac
          WHERE ac.case_id = pl.linked_case_id
             OR EXISTS(SELECT 1 FROM asaas_cobranca_cases jc WHERE jc.cobranca_id = ac.id AND jc.case_id = pl.linked_case_id)) AS n
        FROM pipeline_leads pl LIMIT 1")->fetchAll();
});

t('filtro cobrancas.php (case OR juncao)', function() use ($pdo) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM asaas_cobrancas ac
        WHERE (ac.case_id = ? OR EXISTS(SELECT 1 FROM asaas_cobranca_cases jc WHERE jc.cobranca_id = ac.id AND jc.case_id = ?))");
    $st->execute(array(0, 0)); $st->fetchColumn();
});

t('cliente.php cobrancas (alias ac + juncao)', function() use ($pdo) {
    $st = $pdo->prepare("SELECT * FROM asaas_cobrancas ac WHERE ac.client_id = ?
        AND (ac.case_id = ? OR EXISTS(SELECT 1 FROM asaas_cobranca_cases jc WHERE jc.cobranca_id = ac.id AND jc.case_id = ?))
        ORDER BY ac.vencimento DESC LIMIT 1");
    $st->execute(array(0, 0, 0)); $st->fetchAll();
});

t('helper cobranca_processos_extras', function() {
    $r = cobranca_processos_extras(0);
    if (!is_array($r)) throw new Exception('nao retornou array');
});

echo "\n== $ok OK, $fail FAIL ==\n";
