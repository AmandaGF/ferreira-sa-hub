<?php
// Diagnostico READ-ONLY dos contratos da Nativania.
// Mostra status, snapshot, dados_admin_json e valores do cadastro.
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
echo "<pre style='font-family:Consolas,monospace;font-size:12px;'>";

$col = $pdo->query("SELECT id, nome_completo, valor_remuneracao, perfil_cargo, status FROM colaboradores_onboarding WHERE nome_completo LIKE '%Nativ%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$col) { echo "Nativania nao encontrada"; exit; }

echo "=== CADASTRO DO COLABORADOR ===\n";
foreach ($col as $k => $v) echo "  $k = " . var_export($v, true) . "\n";

$docs = $pdo->prepare("SELECT id, tipo, status, dados_admin_json, LENGTH(pdf_html_snapshot) AS snap_len, assinatura_estagiario_em FROM colaboradores_documentos WHERE colaborador_id = ?");
$docs->execute(array($col['id']));
echo "\n=== DOCUMENTOS VINCULADOS ===\n";
foreach ($docs->fetchAll(PDO::FETCH_ASSOC) as $d) {
    echo "\n--- DOC id={$d['id']} tipo={$d['tipo']} status={$d['status']} ---\n";
    echo "  snapshot_html_len: " . (int)$d['snap_len'] . " bytes\n";
    echo "  assinado_em: " . ($d['assinatura_estagiario_em'] ?: '(nao assinado)') . "\n";
    echo "  dados_admin_json:\n";
    $da = json_decode($d['dados_admin_json'] ?? '[]', true);
    if (is_array($da)) foreach ($da as $k => $v) echo "    $k = " . var_export($v, true) . "\n";
    else echo "    (vazio)\n";
}
echo "</pre>";
