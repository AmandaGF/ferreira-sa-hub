<?php
/**
 * Mescla conv 795 → conv 660 (Enayle Garcia Fontes, client_id=674).
 *
 * Antes: conv 660 (telefone real 5524998396445) e conv 795 (chat_lid puro
 *        132508599484417@lid) — ambas com client_id=674 mas separadas.
 * Depois: conv 660 absorve as 10 msgs da 795. Conv 795 deletada.
 *
 * Sanity-checks:
 *   - garante que ambas conv têm client_id=674
 *   - copia chat_lid da 795 pra 660 (pra próximas msgs baterem na Estratégia 0)
 *   - mostra contagem antes/depois
 *
 * Acesso: ?key=fsa-hub-deploy-2026
 */
ini_set('display_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
$pdo = db();

$DESTINO = 660;
$ORIGEM  = 795;
$CLIENT  = 674;

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Mesclar Enayle</title>';
echo '<style>body{font-family:system-ui;padding:20px;max-width:900px;margin:0 auto}h1{color:#052228}.box{padding:.8rem 1rem;border-radius:8px;margin:.5rem 0}.ok{background:#d1fae5;color:#065f46}.no{background:#fee2e2;color:#991b1b}.warn{background:#fef3c7;color:#92400e}table{width:100%;border-collapse:collapse}td,th{padding:6px 8px;border-bottom:1px solid #ddd;font-size:12px;text-align:left}th{background:#052228;color:#fff}</style>';
echo '</head><body>';
echo '<h1>🔗 Mesclar Enayle Garcia Fontes — conv 795 → conv 660</h1>';

// 1. Sanity check: ambas conv existem e são da Enayle
$st = $pdo->prepare("SELECT id, telefone, chat_lid, client_id, canal, status FROM zapi_conversas WHERE id IN (?, ?) ORDER BY id ASC");
$st->execute(array($DESTINO, $ORIGEM));
$convs = $st->fetchAll();
if (count($convs) !== 2) {
    echo '<div class="box no">✕ Não encontrei as 2 conversas (660 e 795). Operação abortada.</div>';
    exit;
}
echo '<h3>Estado ANTES:</h3>';
echo '<table><thead><tr><th>ID</th><th>Telefone</th><th>chat_lid</th><th>client_id</th><th>Canal</th><th>Status</th><th>Msgs</th></tr></thead><tbody>';
foreach ($convs as $c) {
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = ?");
    $cnt->execute(array($c['id']));
    $n = (int)$cnt->fetchColumn();
    echo '<tr><td><strong>' . $c['id'] . '</strong></td><td>' . htmlspecialchars($c['telefone']) . '</td><td>' . htmlspecialchars($c['chat_lid'] ?? '—') . '</td><td>' . ($c['client_id'] ?? '—') . '</td><td>' . $c['canal'] . '</td><td>' . htmlspecialchars($c['status'] ?? '—') . '</td><td>' . $n . '</td></tr>';
}
echo '</tbody></table>';

// Confirmações
$dest = $convs[0]; $orig = $convs[1];
if ($dest['id'] != $DESTINO || $orig['id'] != $ORIGEM) {
    echo '<div class="box no">✕ Ordem das conversas inesperada. Abortando.</div>'; exit;
}
if ((int)$dest['client_id'] !== $CLIENT || (int)$orig['client_id'] !== $CLIENT) {
    echo '<div class="box no">✕ client_id divergente. Esperava ' . $CLIENT . ' nas duas conversas. Abortando pra não bagunçar dados.</div>';
    exit;
}
if ($dest['canal'] !== $orig['canal']) {
    echo '<div class="box no">✕ Canais diferentes (' . $dest['canal'] . ' vs ' . $orig['canal'] . '). Abortando.</div>';
    exit;
}

if (!isset($_GET['confirmar'])) {
    echo '<div class="box warn">⚠️ Pré-check OK. Pra executar a mesclagem, adicione <code>&confirmar=1</code> à URL.</div>';
    echo '<p>Vai migrar todas as mensagens da conv 795 pra conv 660, copiar chat_lid da 795 pra 660 (pra próximas msgs baterem direto), e DELETAR a conv 795. Operação irreversível.</p>';
    exit;
}

// 2. Antes de mesclar, copia chat_lid da origem pra destino (se destino não tem)
if (empty($dest['chat_lid']) && !empty($orig['chat_lid'])) {
    $pdo->prepare("UPDATE zapi_conversas SET chat_lid = ? WHERE id = ?")
        ->execute(array($orig['chat_lid'], $DESTINO));
    echo '<div class="box ok">✓ Copiei chat_lid="' . htmlspecialchars($orig['chat_lid']) . '" pra conv ' . $DESTINO . '</div>';
}

// 3. Roda merge via função existente
$merged = zapi_auto_merge_por_client_id($pdo, $DESTINO, $CLIENT, $dest['canal']);
echo '<div class="box ' . ($merged > 0 ? 'ok' : 'warn') . '">' . ($merged > 0 ? '✓' : '⚠️') . ' Mescladas: ' . $merged . ' conversa(s) → conv ' . $DESTINO . '</div>';

// 4. Estado DEPOIS
echo '<h3>Estado DEPOIS:</h3>';
$st = $pdo->prepare("SELECT id, telefone, chat_lid, client_id, canal, status FROM zapi_conversas WHERE id IN (?, ?)");
$st->execute(array($DESTINO, $ORIGEM));
$convsDepois = $st->fetchAll();
echo '<table><thead><tr><th>ID</th><th>Telefone</th><th>chat_lid</th><th>client_id</th><th>Msgs</th></tr></thead><tbody>';
foreach ($convsDepois as $c) {
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = ?");
    $cnt->execute(array($c['id']));
    $n = (int)$cnt->fetchColumn();
    echo '<tr><td><strong>' . $c['id'] . '</strong></td><td>' . htmlspecialchars($c['telefone']) . '</td><td>' . htmlspecialchars($c['chat_lid'] ?? '—') . '</td><td>' . ($c['client_id'] ?? '—') . '</td><td>' . $n . '</td></tr>';
}
if (empty($convsDepois) || count($convsDepois) === 1) {
    echo '<tr><td colspan="5" style="color:#065f46;font-weight:700;">✓ Conv 795 deletada. Tudo agora está em conv 660.</td></tr>';
}
echo '</tbody></table>';

audit_log('zapi_merge_manual', 'zapi_conversas', $DESTINO, "Mesclou conv {$ORIGEM} (Enayle Garcia Fontes id {$CLIENT})");
echo '<div class="box ok">✅ Pronto. <a href="' . url('modules/whatsapp/?conversa=' . $DESTINO) . '">Abrir conversa da Enayle no Hub →</a></div>';
echo '</body></html>';
