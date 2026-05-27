<?php
/**
 * Diag: cadastro da Nativania no onboarding + documentos vinculados.
 * URL: /conecta/diag_nativania_onboarding.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/onboarding_docs_schema.php';
require_once __DIR__ . '/core/onboarding_docs_templates.php';

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

set_exception_handler(function($e) {
    echo "\n=== EXCEPTION ===\n" . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
});

echo "=== COLABORADORES_ONBOARDING (TODOS) ===\n";
$st = $pdo->prepare("SELECT * FROM colaboradores_onboarding ORDER BY id DESC LIMIT 10");
$st->execute();
$regs = $st->fetchAll();
if (!$regs) { echo "(nenhum cadastro encontrado)\n"; exit; }

foreach ($regs as $r) {
    echo "\nID #{$r['id']} — {$r['nome_completo']}\n";
    foreach ($r as $k => $v) {
        if ($v === null || $v === '') continue;
        echo "  $k: " . (mb_strlen($v) > 120 ? mb_substr($v, 0, 117) . '...' : $v) . "\n";
    }
}

echo "\n=== COLABORADORES_DOCUMENTOS (vinculados) ===\n";
foreach ($regs as $r) {
    echo "\nColaborador #{$r['id']} ({$r['nome_completo']}):\n";
    $st2 = $pdo->prepare("SELECT * FROM colaboradores_documentos WHERE colaborador_id = ?");
    $st2->execute(array($r['id']));
    $docs = $st2->fetchAll();
    if (!$docs) { echo "  (nenhum documento vinculado)\n"; continue; }
    foreach ($docs as $d) {
        echo "  - doc #{$d['id']} tipo={$d['tipo']} status={$d['status']}\n";
        echo "    schema existe? " . (onboarding_doc_schema($d['tipo']) ? 'SIM' : 'NÃO') . "\n";
        echo "    dados_admin_json: " . (!empty($d['dados_admin_json']) ? $d['dados_admin_json'] : '(vazio)') . "\n";
        echo "    assinatura: " . (!empty($d['assinatura_estagiario_em']) ? $d['assinatura_estagiario_em'] : '(não assinado)') . "\n";
        echo "    pdf_html_snapshot len: " . (isset($d['pdf_html_snapshot']) ? strlen($d['pdf_html_snapshot']) : 'col não existe') . "\n";
    }
}

echo "\n=== TESTE FUNCOES RENDER ===\n";
$todasRender = array(
    'render_termo_compromisso_estagio',
    'render_termo_confidencialidade_lgpd',
    'render_pop_estagiario',
    'render_checklist_admissional_estagiario',
    'render_contrato_prestacao_marketing',
    'render_contrato_prestacao_comercial',
);
foreach ($todasRender as $f) {
    echo "  $f: " . (function_exists($f) ? 'OK' : 'MISSING') . "\n";
}

echo "\n=== FILE INFO ===\n";
$file = __DIR__ . '/core/onboarding_docs_templates.php';
echo "  arquivo: $file\n";
echo "  existe?: " . (file_exists($file) ? 'SIM' : 'NÃO') . "\n";
echo "  tamanho: " . filesize($file) . " bytes\n";
echo "  mtime: " . date('Y-m-d H:i:s', filemtime($file)) . "\n";
$lines = file($file);
echo "  total linhas: " . count($lines) . "\n";
echo "  ultimas 5 linhas:\n";
foreach (array_slice($lines, -5) as $i => $line) {
    echo "    " . (count($lines) - 5 + $i + 1) . ": " . rtrim($line) . "\n";
}
echo "\n=== DOCUMENTOS DISPONÍVEIS PARA perfil=prestador_pj ===\n";
foreach (onboarding_docs_por_perfil('prestador_pj') as $tipo => $sch) {
    echo "  - $tipo : {$sch['icon']} {$sch['label']}\n";
    echo "      render_function: {$sch['render_function']} " . (function_exists($sch['render_function']) ? '(EXISTE)' : '(NÃO EXISTE)') . "\n";
    echo "      campos_admin: " . (empty($sch['campos_admin']) ? 'vazio' : count($sch['campos_admin']) . ' campos') . "\n";
}

echo "\nFIM.\n";
