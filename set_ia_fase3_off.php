<?php
/**
 * Garante que as features da Fase 3 estejam DESLIGADAS no banco.
 * Pedido explicito da Amanda (24/05/2026): manter revisao_peticao e
 * traducao_leiga off. Sentiment WA ja estava off por default.
 *
 * Acesse: ferreiraesa.com.br/conecta/set_ia_fase3_off.php?key=fsa-hub-deploy-2026
 *
 * Idempotente: rodar 2x nao causa problema.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

$pdo = db();

echo "=== Killswitches Fase 3 ===\n\n";

$alvo = array(
    'ia_feature_traducao_leiga_enabled'  => 'Traducao juridico-leigo (Central VIP)',
    'ia_feature_revisao_peticao_enabled' => 'Revisao de peticao por IA (Sonnet)',
    'ia_feature_sentiment_wa_enabled'    => 'Sentiment WhatsApp (cliente irritado)',
);

// Estado antes
echo "ESTADO ANTES:\n";
foreach ($alvo as $chave => $nome) {
    $st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
    $st->execute(array($chave));
    $v = $st->fetchColumn();
    $vStr = ($v === false) ? '(nao existe — default OFF)' : ($v === '1' ? 'LIGADA' : 'DESLIGADA');
    echo "  $nome\n     [$vStr]\n";
}

// Aplica: forca todas pra '0'
$stIns = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, '0') ON DUPLICATE KEY UPDATE valor = '0'");
foreach (array_keys($alvo) as $chave) {
    $stIns->execute(array($chave));
}

// Estado depois
echo "\nESTADO DEPOIS (forcado para '0'):\n";
foreach ($alvo as $chave => $nome) {
    $st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
    $st->execute(array($chave));
    $v = $st->fetchColumn();
    echo "  $nome\n     [" . ($v === '1' ? 'LIGADA' : 'DESLIGADA') . "]\n";
}

echo "\nPronto. Amanda pode reativar quando quiser em /modules/admin/ia_custo.php.\n";
