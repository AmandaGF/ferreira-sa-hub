<?php
if (($_GET['key']??'') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('no'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Buscar formulários de gastos com nome do Lorenzo
$st = $pdo->query("SELECT id, form_type, client_name, created_at, updated_at
                   FROM form_submissions
                   WHERE form_type IN ('despesas_mensais','gastos_pensao')
                     AND (client_name LIKE '%Lorenzo%' OR client_name LIKE '%Carvalho%')
                   ORDER BY id DESC LIMIT 10");
$forms = $st->fetchAll(PDO::FETCH_ASSOC);
foreach ($forms as $f) echo "  #{$f['id']} {$f['form_type']} {$f['client_name']} em={$f['created_at']} upd={$f['updated_at']}\n";

if (!$forms) { echo "Nenhum formulário achado.\n"; exit; }

// Detalhes do primeiro (o mais recente, provavelmente o que Amanda editou)
$f = $forms[0];
echo "\n=== Detalhe form #{$f['id']} ===\n";
$row = $pdo->prepare("SELECT payload_json FROM form_submissions WHERE id = ?");
$row->execute(array($f['id']));
$payload = json_decode($row->fetchColumn(), true);

echo "\n-- moradia_* no payload --\n";
foreach ($payload as $k => $v) {
    if (strpos($k, 'moradia_') === 0 || $k === 'total_moradia' || $k === 'moradia_total_cents' || $k === 'moradia_rateada_cents' || $k === 'moradores' || $k === 'renda_mensal_cents' || $k === 'total_geral' || $k === 'total_geral_cents') {
        $show = is_array($v) ? json_encode($v) : (string)$v;
        if (is_numeric($v) && $v > 100 && strpos($k, 'moradores') === false) $show .= "  (= R$ " . number_format($v / 100, 2, ',', '.') . ")";
        echo "  {$k} = {$show}\n";
    }
}

echo "\n-- _edit no payload --\n";
if (isset($payload['_edit'])) {
    print_r($payload['_edit']);
} else {
    echo "  (sem _edit — Amanda ainda não editou este)\n";
}

echo "\n-- _edit_meta --\n";
if (isset($payload['_edit_meta'])) print_r($payload['_edit_meta']);

echo "\n-- stored (só chaves relevantes) --\n";
$stored = isset($payload['stored']) ? $payload['stored'] : $payload;
if (is_string($stored)) $stored = json_decode($stored, true);
if (is_array($stored)) {
    foreach ($stored as $k => $v) {
        if (strpos($k, 'moradia_') === 0 && is_numeric($v) && (int)$v > 0) {
            echo "  stored[{$k}] = {$v} cents (R$ " . number_format($v / 100, 2, ',', '.') . ")\n";
        }
    }
}
