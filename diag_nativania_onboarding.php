<?php
/**
 * Diag: cadastro da Nativania no onboarding + documentos vinculados.
 * URL: /conecta/diag_nativania_onboarding.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/onboarding_docs_schema.php';

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== COLABORADORES_ONBOARDING (TODOS) ===\n";
$st = $pdo->prepare("SELECT id, nome_completo, email_institucional, email_pessoal, cpf, cnpj, razao_social,
                            perfil_cargo, status, token, data_inicio_contrato, data_termino_contrato,
                            escopo_servicos, dados_bancarios, valor_remuneracao,
                            link_contrato_url, criado_em
                     FROM colaboradores_onboarding
                     ORDER BY id DESC LIMIT 10");
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
    $st2 = $pdo->prepare("SELECT id, tipo, status, dados_admin_json, dados_estagiario_json,
                                 assinatura_estagiario_em, criado_em
                          FROM colaboradores_documentos WHERE colaborador_id = ?");
    $st2->execute(array($r['id']));
    $docs = $st2->fetchAll();
    if (!$docs) { echo "  (nenhum documento vinculado)\n"; continue; }
    foreach ($docs as $d) {
        echo "  - doc #{$d['id']} tipo={$d['tipo']} status={$d['status']}\n";
        echo "    schema existe? " . (onboarding_doc_schema($d['tipo']) ? 'SIM' : 'NÃO') . "\n";
        echo "    dados_admin_json: " . ($d['dados_admin_json'] ?: '(vazio)') . "\n";
        echo "    assinatura: " . ($d['assinatura_estagiario_em'] ?: '(não assinado)') . "\n";
    }
}

echo "\n=== DOCUMENTOS DISPONÍVEIS PARA perfil=prestador_pj ===\n";
foreach (onboarding_docs_por_perfil('prestador_pj') as $tipo => $sch) {
    echo "  - $tipo : {$sch['icon']} {$sch['label']}\n";
    echo "      render_function: {$sch['render_function']} " . (function_exists($sch['render_function']) ? '(EXISTE)' : '(NÃO EXISTE)') . "\n";
    echo "      campos_admin: " . (empty($sch['campos_admin']) ? 'vazio' : count($sch['campos_admin']) . ' campos') . "\n";
}

echo "\nFIM.\n";
