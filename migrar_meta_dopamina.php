<?php
/**
 * Migração: Meta Coletiva de Dopamina.
 * Chaves em configuracoes:
 *   meta_dopamina_alvo    → número (default 300)
 *   meta_dopamina_premio  → texto do prêmio (ex: "Almoço em restaurante japonês")
 *   meta_dopamina_periodo → 'mensal' | 'semanal' (default 'mensal')
 *   meta_dopamina_ativa   → '0' | '1' — killswitch (default 1 = mostrar)
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
echo "=== migrar_meta_dopamina ===\n\n";

$chaves = array(
    'meta_dopamina_alvo'    => '300',
    'meta_dopamina_premio'  => 'Almoço em restaurante da equipe',
    'meta_dopamina_periodo' => 'mensal',
    'meta_dopamina_ativa'   => '1',
);
foreach ($chaves as $k => $v) {
    $st = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE valor = valor"); // NAO sobrescreve se ja existe
    $st->execute(array($k, $v));
    $atual = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
    $atual->execute(array($k));
    echo "  $k = " . $atual->fetchColumn() . "\n";
}
echo "\n=== OK ===\n";
