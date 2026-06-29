<?php
/**
 * Liga (ou desliga) a feature de classificação estruturada DJEN.
 *
 * Uso:
 *   GET /ligar_djen_classif.php?key=fsa-hub-deploy-2026          → liga (valor=1)
 *   GET /ligar_djen_classif.php?key=fsa-hub-deploy-2026&off=1    → desliga (valor=0)
 *
 * Idempotente. Mostra estado antes/depois.
 */
require_once __DIR__ . '/core/database.php';

$key = $_GET['key'] ?? '';
if ($key !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$chave = 'ia_feature_djen_classif_estruturada_enabled';
$novoValor = empty($_GET['off']) ? '1' : '0';

$st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
$st->execute(array($chave));
$antes = $st->fetchColumn();
echo "Antes: {$chave} = " . ($antes === false ? '(não existe)' : "'{$antes}'") . "\n";

if ($antes === false) {
    $cols = $pdo->query("DESCRIBE configuracoes")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('descricao', $cols)) {
        $pdo->prepare("INSERT INTO configuracoes (chave, valor, descricao) VALUES (?,?,?)")
            ->execute(array($chave, $novoValor, 'Classificação estruturada DJEN via Haiku'));
    } else {
        $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?,?)")
            ->execute(array($chave, $novoValor));
    }
} else {
    $pdo->prepare("UPDATE configuracoes SET valor = ? WHERE chave = ?")
        ->execute(array($novoValor, $chave));
}

$st->execute(array($chave));
$depois = $st->fetchColumn();
echo "Depois: {$chave} = '{$depois}'\n";
echo "\n";
echo ($depois === '1') ? "✓ LIGADO — o cron das 9h vai começar a classificar.\n"
                       : "⊘ DESLIGADO — o cron vai continuar rodando mas sem fazer nada.\n";
