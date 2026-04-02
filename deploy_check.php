<?php
/**
 * Ferreira & Sá Hub — Pre-Deploy Health Check
 *
 * Deve ser chamado ANTES de cada deploy para garantir que o sistema está saudável.
 * Se qualquer teste falhar, o deploy NÃO deve prosseguir.
 *
 * Uso via CLI:   php deploy_check.php
 * Uso via HTTP:  https://ferreiraesa.com.br/conecta/deploy_check.php?key=fsa-hub-deploy-2026
 *
 * Retorna JSON: { "deploy_allowed": true/false, "results": [...] }
 */

// Segurança: requer chave ou CLI
$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    $key = isset($_GET['key']) ? $_GET['key'] : '';
    if ($key !== 'fsa-hub-deploy-2026') {
        http_response_code(403);
        echo json_encode(array('ok' => false, 'message' => 'Chave inválida'));
        exit;
    }
}

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';

header('Content-Type: application/json; charset=utf-8');

$results = array();
$allOk = true;

// ─── Teste 1: Banco de dados ────────────────────────────
try {
    $pdo = db();
    $pdo->query("SELECT 1");
    $tables = array('users', 'clients', 'cases', 'pipeline_leads', 'pipeline_history',
                    'form_submissions', 'case_tasks', 'audit_log', 'notifications',
                    'portal_links', 'agenda_eventos', 'documentos_pendentes');
    $missing = array();
    foreach ($tables as $t) {
        try { $pdo->query("SELECT 1 FROM $t LIMIT 1"); }
        catch (Exception $e) { $missing[] = $t; }
    }
    if ($missing) {
        $results[] = array('test' => 'banco_dados', 'ok' => false, 'msg' => 'Tabelas faltando: ' . implode(', ', $missing));
        $allOk = false;
    } else {
        $results[] = array('test' => 'banco_dados', 'ok' => true, 'msg' => 'OK');
    }
} catch (Exception $e) {
    $results[] = array('test' => 'banco_dados', 'ok' => false, 'msg' => $e->getMessage());
    $allOk = false;
}

// ─── Teste 2: Arquivos críticos existem ─────────────────
$criticalFiles = array(
    'core/config.php', 'core/database.php', 'core/auth.php',
    'core/functions.php', 'core/functions_utils.php', 'core/functions_auth.php',
    'core/functions_notify.php', 'core/functions_cases.php',
    'core/middleware.php', 'core/form_handler.php',
    'templates/header.php', 'templates/sidebar.php', 'templates/footer.php',
    'assets/js/drawer.js', 'assets/js/helpers.js', 'assets/js/conecta.js',
    'assets/css/conecta.css',
    'modules/pipeline/api.php', 'modules/operacional/api.php',
    'modules/shared/card_api.php', 'modules/shared/card_actions.php',
    'publico/api_form.php',
);
$missingFiles = array();
foreach ($criticalFiles as $f) {
    if (!file_exists(__DIR__ . '/' . $f)) {
        $missingFiles[] = $f;
    }
}
if ($missingFiles) {
    $results[] = array('test' => 'arquivos_criticos', 'ok' => false, 'msg' => 'Faltando: ' . implode(', ', $missingFiles));
    $allOk = false;
} else {
    $results[] = array('test' => 'arquivos_criticos', 'ok' => true, 'msg' => 'OK — ' . count($criticalFiles) . ' arquivos verificados');
}

// ─── Teste 3: Funções essenciais existem ────────────────
$essentialFunctions = array(
    'e', 'redirect', 'flash_set', 'generate_csrf_token', 'validate_csrf',
    'clean_str', 'can_access', '_permission_defaults', 'role_label', 'role_level',
    'notify', 'notify_gestao', 'notificar_cliente',
    'find_or_create_client', 'get_checklist_template', 'generate_case_checklist',
    'brl', 'data_br', 'audit_log', 'url', 'encrypt_value', 'decrypt_value',
);
$missingFns = array();
foreach ($essentialFunctions as $fn) {
    if (!function_exists($fn)) {
        $missingFns[] = $fn;
    }
}
if ($missingFns) {
    $results[] = array('test' => 'funcoes_essenciais', 'ok' => false, 'msg' => 'Faltando: ' . implode(', ', $missingFns));
    $allOk = false;
} else {
    $results[] = array('test' => 'funcoes_essenciais', 'ok' => true, 'msg' => 'OK — ' . count($essentialFunctions) . ' funções verificadas');
}

// ─── Teste 4: Gatilho contrato_assinado no pipeline ─────
$pipelineCode = file_get_contents(__DIR__ . '/modules/pipeline/api.php');
$checks = array(
    'contrato_assinado' => strpos($pipelineCode, 'contrato_assinado') !== false,
    'INSERT INTO cases' => strpos($pipelineCode, 'INSERT INTO cases') !== false,
    'generate_case_checklist' => strpos($pipelineCode, 'generate_case_checklist') !== false,
);
$failedChecks = array();
foreach ($checks as $label => $ok) {
    if (!$ok) $failedChecks[] = $label;
}
if ($failedChecks) {
    $results[] = array('test' => 'gatilho_contrato', 'ok' => false, 'msg' => 'Faltando em pipeline/api.php: ' . implode(', ', $failedChecks));
    $allOk = false;
} else {
    $results[] = array('test' => 'gatilho_contrato', 'ok' => true, 'msg' => 'OK');
}

// ─── Teste 5: Espelhamento bilateral doc_faltante ───────
$opCode = file_get_contents(__DIR__ . '/modules/operacional/api.php');
$mirrorOk = (strpos($opCode, 'doc_faltante') !== false && strpos($opCode, 'pipeline_leads') !== false);
$mirrorPipeOk = (strpos($pipelineCode, 'doc_faltante') !== false);
if (!$mirrorOk || !$mirrorPipeOk) {
    $results[] = array('test' => 'espelhamento_doc_faltante', 'ok' => false,
        'msg' => 'Espelhamento ausente — Op: ' . ($mirrorOk ? 'OK' : 'FAIL') . ', Pipeline: ' . ($mirrorPipeOk ? 'OK' : 'FAIL'));
    $allOk = false;
} else {
    $results[] = array('test' => 'espelhamento_doc_faltante', 'ok' => true, 'msg' => 'OK — bilateral presente');
}

// ─── Teste 6: Integridade de dados ──────────────────────
try {
    $pdo = db();
    // Leads sem client_id
    $orphanLeads = $pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE client_id IS NULL AND stage NOT IN ('perdido','cancelado')")->fetchColumn();
    // Casos sem client_id
    $orphanCases = $pdo->query("SELECT COUNT(*) FROM cases WHERE client_id IS NULL")->fetchColumn();

    $issues = array();
    if ($orphanLeads > 0) $issues[] = $orphanLeads . ' leads sem client_id';
    if ($orphanCases > 0) $issues[] = $orphanCases . ' casos sem client_id';

    if ($issues) {
        $results[] = array('test' => 'integridade_dados', 'ok' => false, 'msg' => implode('; ', $issues));
        $allOk = false;
    } else {
        $results[] = array('test' => 'integridade_dados', 'ok' => true, 'msg' => 'OK');
    }
} catch (Exception $e) {
    $results[] = array('test' => 'integridade_dados', 'ok' => false, 'msg' => $e->getMessage());
    $allOk = false;
}

// ─── Resultado final ────────────────────────────────────
$output = array(
    'deploy_allowed' => $allOk,
    'timestamp'      => date('Y-m-d H:i:s'),
    'passed'         => count(array_filter($results, function($r) { return $r['ok']; })),
    'failed'         => count(array_filter($results, function($r) { return !$r['ok']; })),
    'results'        => $results,
);

// Salvar resultado
file_put_contents(__DIR__ . '/health_last_result.json', json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

if ($isCli) {
    echo "\n=== F&S Hub — Pre-Deploy Check ===\n\n";
    foreach ($results as $r) {
        echo ($r['ok'] ? '[OK]  ' : '[FAIL]') . ' ' . $r['test'] . ' — ' . $r['msg'] . "\n";
    }
    echo "\n" . ($allOk ? 'DEPLOY PERMITIDO' : 'DEPLOY BLOQUEADO — corrija os erros acima') . "\n\n";
    exit($allOk ? 0 : 1);
}

echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
