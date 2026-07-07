<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('nope');
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1'); error_reporting(E_ALL);
while (ob_get_level() > 0) { ob_end_clean(); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_comemoracao.php';
require_once __DIR__ . '/core/functions_jorjao.php';
$pdo = db();

echo "=== DIAG novidade_hub ===\n\n";

// 1) Killswitch
$ativo = (string)$pdo->query("SELECT valor FROM configuracoes WHERE chave='jorjao_novidade_hub_ativo'")->fetchColumn();
echo "1. Killswitch jorjao_novidade_hub_ativo = '{$ativo}' " . ($ativo === '1' ? '✓' : '✗ DESLIGADO') . "\n\n";

// 2) Grupo
$cfg = comemoracao_get_config();
echo "2. Grupo/canal do sino:\n";
echo "   canal='{$cfg['canal']}' · grupo_id='" . ($cfg['grupo_id'] ?: '(vazio)') . "'\n\n";

// 3) Templates ativos de novidade_hub
$tpls = $pdo->query("SELECT id, ativo, LEFT(template,60) AS preview FROM jorjao_templates WHERE tocada='novidade_hub' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$ativos = 0;
echo "3. Templates de novidade_hub: " . count($tpls) . " total\n";
foreach ($tpls as $t) {
    $mk = $t['ativo'] ? '✓' : '✗';
    if ($t['ativo']) $ativos++;
    echo "   {$mk} #{$t['id']} {$t['preview']}...\n";
}
echo "   ATIVOS: {$ativos}\n\n";

// 4) Log das ultimas tentativas
$log = json_decode((string)$pdo->query("SELECT valor FROM configuracoes WHERE chave='jorjao_log_novidade_hub'")->fetchColumn(), true) ?: array();
echo "4. Log das ultimas " . count($log) . " tentativas:\n";
if (!$log) { echo "   (vazio — nenhuma tentativa registrada)\n"; }
foreach (array_slice($log, 0, 5) as $l) {
    $status = !empty($l['ok']) ? 'OK' : 'FALHA';
    echo "   [{$l['em']}] {$status} tpl=#{$l['tpl']} erro=" . ($l['erro'] ?? '-') . "\n";
    if (!empty($l['ctx'])) echo "     ctx: " . json_encode($l['ctx'], JSON_UNESCAPED_UNICODE) . "\n";
}
echo "\n";

// 5) Teste dry-run: simula chamada com valores fake
echo "5. Teste dry-run (chamando jorjao_novidade_hub com valores fake):\n";
$r = jorjao_novidade_hub('Teste diag', 'Descricao de teste', 'https://ferreiraesa.com.br/conecta/');
echo "   ok=" . (!empty($r['ok']) ? 'SIM' : 'NAO') . "\n";
echo "   erro=" . ($r['erro'] ?? '(nenhum)') . "\n";
if (!empty($r['mensagem'])) echo "   mensagem final:\n---\n" . mb_substr($r['mensagem'], 0, 500) . "\n---\n";
