<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
ini_set('display_errors','1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Configuração da comemoração ===\n";
$st = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'comemoracao_contrato_%' ORDER BY chave");
foreach ($st as $r) {
    $v = $r['valor'];
    if (strlen($v) > 150) $v = substr($v, 0, 150) . '... (truncado)';
    echo "  {$r['chave']} = $v\n";
}

echo "\n=== Instâncias Z-API ===\n";
$st = $pdo->query("SELECT ddd, conectado, instancia_id, token FROM zapi_instancias WHERE ativo = 1");
foreach ($st as $i) {
    $instOk = !empty($i['instancia_id']) && !empty($i['token']);
    $conOk = (int)$i['conectado'] === 1;
    echo "  DDD {$i['ddd']}: conectada=" . ($conOk?'SIM':'NAO') . " | credenciais=" . ($instOk?'OK':'FALTANDO') . "\n";
}

echo "\n=== Historico das ultimas tentativas (log) ===\n";
$hist = json_decode((string)$pdo->query("SELECT valor FROM configuracoes WHERE chave='comemoracao_contrato_log'")->fetchColumn(), true) ?: array();
if (!$hist) {
    echo "  Nenhuma tentativa registrada.\n";
} else {
    foreach ($hist as $h) {
        echo "  {$h['em']} | cliente='{$h['cliente']}' | ok=" . ($h['ok']?'SIM':'NAO') . ($h['erro'] ? " | erro='{$h['erro']}'" : '') . "\n";
    }
}

echo "\n=== Teste direto via zapi_send_text ===\n";
require_once __DIR__ . '/core/functions_zapi.php';
$cfgGrupo = $pdo->query("SELECT valor FROM configuracoes WHERE chave='comemoracao_contrato_grupo_id'")->fetchColumn();
$cfgCanal = $pdo->query("SELECT valor FROM configuracoes WHERE chave='comemoracao_contrato_canal'")->fetchColumn();
echo "  canal cfg: '$cfgCanal'\n";
echo "  grupo cfg: '$cfgGrupo'\n";
if ($cfgGrupo && $cfgCanal) {
    $r = zapi_send_text($cfgCanal, $cfgGrupo, "[diagnostico " . date('H:i:s') . "] Teste direto via diag — se voce ler isso, o envio pro grupo esta funcionando.");
    echo "  resultado:\n";
    echo "    ok = " . (!empty($r['ok']) ? 'SIM' : 'NAO') . "\n";
    if (!empty($r['erro'])) echo "    erro = " . $r['erro'] . "\n";
    if (!empty($r['http_code'])) echo "    http_code = " . $r['http_code'] . "\n";
    if (!empty($r['data'])) echo "    data = " . substr(json_encode($r['data']), 0, 300) . "\n";
} else {
    echo "  (canal ou grupo nao configurado — pule essa parte)\n";
}
