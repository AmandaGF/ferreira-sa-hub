<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$cur = (string)$pdo->query("SELECT valor FROM configuracoes WHERE chave='comemoracao_contrato_grupo_id'")->fetchColumn();
echo "Antes: '$cur'\n";

if ($cur !== '' && strpos($cur, '@') === false) {
    $novo = $cur . '@g.us';
    $pdo->prepare("UPDATE configuracoes SET valor = ? WHERE chave='comemoracao_contrato_grupo_id'")->execute(array($novo));
    echo "Depois: '$novo'\n";
    echo "✓ Atualizado.\n";
} else {
    echo "Ja esta com @g.us ou similar, nao mexi.\n";
}

// Re-testa o envio agora
echo "\n=== Teste apos fix ===\n";
require_once __DIR__ . '/core/functions_zapi.php';
$canal = (string)$pdo->query("SELECT valor FROM configuracoes WHERE chave='comemoracao_contrato_canal'")->fetchColumn();
$grupo = (string)$pdo->query("SELECT valor FROM configuracoes WHERE chave='comemoracao_contrato_grupo_id'")->fetchColumn();
echo "canal: '$canal' | grupo: '$grupo'\n";
$r = zapi_send_text($canal, $grupo, "🔧 *Teste pos-fix* — se voce ler isso no grupo, o sufixo @g.us resolveu o bug! 🎉");
echo "ok = " . (!empty($r['ok']) ? 'SIM' : 'NAO') . "\n";
if (!empty($r['data'])) echo "data = " . json_encode($r['data']) . "\n";
if (!empty($r['erro'])) echo "erro = " . $r['erro'] . "\n";
