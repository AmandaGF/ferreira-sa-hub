<?php
if (($_GET['key']??'') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('no'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Buscar o form 733 e simular o cálculo do relatório
$formId = (int)($_GET['id'] ?? 733);
$row = $pdo->prepare("SELECT * FROM form_submissions WHERE id = ?");
$row->execute(array($formId));
$form = $row->fetch(PDO::FETCH_ASSOC);
if (!$form) exit("Form não encontrado.\n");

$payload = json_decode($form['payload_json'], true);

// Espelha lógica do relatorio_gastos.php
if (isset($payload['payload_json']) && is_string($payload['payload_json'])) {
    $inner = json_decode($payload['payload_json'], true);
    if (is_array($inner)) $payload = array_merge($payload, $inner);
}
$totais = isset($payload['totais']) ? $payload['totais'] : $payload;

$mapTotais = array(
    'total_moradia' => 'moradia_rateada_cents',
    'total_alim' => 'alimentacao_cents',
    'total_saude' => 'saude_cents',
    'total_educ' => 'educacao_cents',
    'total_transp' => 'transporte_cents',
    'total_vest' => 'vestuario_cents',
    'total_lazer' => 'lazer_cents',
    'total_tech' => 'tecnologia_cents',
    'total_cuid' => 'cuidados_cents',
    'total_eventual' => 'eventuais_cents',
    'total_outros' => 'outros_cents',
);
foreach ($mapTotais as $novo => $antigo) {
    if (isset($payload[$novo]) && !isset($totais[$antigo])) {
        $totais[$antigo] = (int)$payload[$novo];
    }
}

$categorias = array(
    'moradia' => 'moradia_rateada_cents',
    'alimentacao' => 'alimentacao_cents',
    'saude' => 'saude_cents',
    'educacao' => 'educacao_cents',
    'transporte' => 'transporte_cents',
    'vestuario' => 'vestuario_cents',
    'lazer' => 'lazer_cents',
    'tecnologia' => 'tecnologia_cents',
    'cuidados' => 'cuidados_cents',
    'outros' => 'outros_cents',
);

echo "\n-- Cálculo por categoria (o que o PHP mostraria) --\n";
$totalCalc = 0;
foreach ($categorias as $key => $campo) {
    $valor = isset($totais[$campo]) ? (int)$totais[$campo] : 0;
    $totalCalc += $valor;
    echo "  {$key} ({$campo}): {$valor} cents (R$ " . number_format($valor / 100, 2, ',', '.') . ")\n";
}
echo "  TOTAL calculado: R$ " . number_format($totalCalc / 100, 2, ',', '.') . "\n";

// Se _edit tem overrides
if (isset($payload['_edit']['cats']) && is_array($payload['_edit']['cats'])) {
    echo "\n-- Overrides _edit['cats'] --\n";
    foreach ($payload['_edit']['cats'] as $k => $v) echo "  {$k} = {$v} (R$ " . number_format($v / 100, 2, ',', '.') . ")\n";
}
if (isset($payload['_edit']['subs']) && is_array($payload['_edit']['subs'])) {
    echo "\n-- Overrides _edit['subs'] --\n";
    foreach ($payload['_edit']['subs'] as $k => $v) echo "  {$k} = {$v} (R$ " . number_format($v / 100, 2, ',', '.') . ")\n";
}
if (isset($payload['_edit']['total_geral_cents'])) {
    echo "\n_edit['total_geral_cents'] = " . $payload['_edit']['total_geral_cents'] . " (R$ " . number_format($payload['_edit']['total_geral_cents'] / 100, 2, ',', '.') . ")\n";
}
