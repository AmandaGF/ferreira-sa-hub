<?php
/**
 * Aplica cores padrão pros atendentes do WhatsApp.
 * - Amanda → Lilás, Luiz Eduardo → Verde, Andressia → Vermelho, Carina → Azul
 * - Demais: distribui da paleta restante em ordem alfabética
 *
 * URL: /conecta/migrar_cores_wa.php?key=fsa-hub-deploy-2026
 * Force: &force=1 sobrescreve cores já definidas
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

try { $pdo->exec("ALTER TABLE users ADD COLUMN wa_color VARCHAR(20) DEFAULT NULL"); } catch (Exception $e) {}

$force = ($_GET['force'] ?? '') === '1';

// Mapeamentos nomeados (regex case-insensitive → cor)
$mapNomeados = array(
    '/amanda/i'        => array('cor' => '#8b5cf6', 'nome' => 'Lilás'),
    '/luiz\s*eduardo/i'=> array('cor' => '#059669', 'nome' => 'Verde'),
    '/andressia/i'     => array('cor' => '#dc2626', 'nome' => 'Vermelho'),
    '/carina/i'        => array('cor' => '#2563eb', 'nome' => 'Azul'),
);

// Paleta pra distribuir entre os demais (ordem = ordem de distribuição)
$paletaExtra = array(
    array('cor' => '#ec4899', 'nome' => 'Rosa'),
    array('cor' => '#f97316', 'nome' => 'Laranja'),
    array('cor' => '#eab308', 'nome' => 'Amarelo'),
    array('cor' => '#0891b2', 'nome' => 'Ciano'),
    array('cor' => '#84cc16', 'nome' => 'Verde-claro'),
    array('cor' => '#92400e', 'nome' => 'Marrom'),
    array('cor' => '#64748b', 'nome' => 'Cinza'),
    array('cor' => '#052228', 'nome' => 'Petróleo'),
);

$users = $pdo->query("SELECT id, name, role, wa_color FROM users WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

echo "=== Cores dos atendentes (WhatsApp) ===\n";
echo "Modo: " . ($force ? '⚠️ FORCE — sobrescreve existentes' : '✅ Só aplica onde ainda não tem cor') . "\n\n";
echo sprintf("%-40s  %-14s  %s\n", 'ATENDENTE', 'ROLE', 'COR APLICADA');
echo str_repeat('-', 80) . "\n";

$upd = $pdo->prepare("UPDATE users SET wa_color = ? WHERE id = ?");
$total = 0; $pulados = 0;

foreach ($users as $u) {
    if (!$force && !empty($u['wa_color'])) {
        echo sprintf("%-40s  %-14s  %s (mantida)\n", mb_strimwidth($u['name'], 0, 38, '…'), $u['role'], $u['wa_color']);
        $pulados++;
        continue;
    }

    $escolhida = null;
    foreach ($mapNomeados as $re => $opt) {
        if (preg_match($re, $u['name'])) { $escolhida = $opt; break; }
    }
    if (!$escolhida) {
        $escolhida = array_shift($paletaExtra);
        if (!$escolhida) $escolhida = array('cor' => '#94a3b8', 'nome' => 'Cinza claro');
    }

    $upd->execute(array($escolhida['cor'], $u['id']));
    echo sprintf("%-40s  %-14s  %s %s\n", mb_strimwidth($u['name'], 0, 38, '…'), $u['role'], $escolhida['cor'], $escolhida['nome']);
    $total++;
}

echo "\n✔ $total atualizados, $pulados preservados (já tinham cor)\n";
echo $force ? "" : "\nSe quiser redistribuir TUDO do zero, use &force=1\n";
