<?php
/**
 * Fix Amanda 10/06/2026: clientes que ATIVARAM a conta (tem senha) e ja
 * LOGARAM ao menos uma vez (ultimo_acesso preenchido) mas foram setados
 * como ativo=0 pelo bug do reset_salavip / sv_renovar_via_wa.
 *
 * Roda 1x apos o deploy do fix. Lista os afetados e desbloqueia.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$dryRun = !isset($_GET['confirma']);

echo "=== Clientes bloqueados injustamente (com senha cadastrada + ja logaram) ===\n\n";
$rows = $pdo->query("SELECT su.id, su.cliente_id, su.ultimo_acesso, su.atualizado_em, c.name, c.email
                     FROM salavip_usuarios su
                     JOIN clients c ON c.id = su.cliente_id
                     WHERE su.ativo = 0
                       AND su.senha_hash IS NOT NULL AND su.senha_hash != ''
                       AND su.ultimo_acesso IS NOT NULL
                     ORDER BY su.ultimo_acesso DESC")->fetchAll();

echo "Total: " . count($rows) . "\n\n";
foreach ($rows as $r) {
    echo sprintf("  sv #%d | %s (%s) | ult_acesso: %s | atualizado: %s\n",
        $r['id'], $r['name'], $r['email'], $r['ultimo_acesso'], $r['atualizado_em']);
}

if ($dryRun) {
    echo "\n[DRY-RUN] Nada foi alterado.\n";
    echo "Pra desbloquear todos, rode novamente com &confirma=1\n";
    exit;
}

if (empty($rows)) { echo "\nNada a fazer.\n"; exit; }

$ids = array_map(function($r){ return (int)$r['id']; }, $rows);
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("UPDATE salavip_usuarios SET ativo = 1, atualizado_em = NOW() WHERE id IN ($placeholders)");
$stmt->execute($ids);
$n = $stmt->rowCount();
echo "\n✓ $n cliente(s) desbloqueado(s) (ativo=1).\n";

// Audit
require_once __DIR__ . '/core/middleware.php';
foreach ($ids as $svId) {
    try {
        audit_log('salavip_desbloqueio_em_massa_fix', 'salavip_usuarios', $svId, 'Fix bug reset_salavip 10/06/2026 — cliente ja tinha senha cadastrada');
    } catch (Throwable $e) {}
}
echo "Auditoria gravada.\n";
