<?php
/**
 * Backfill de chat_lid em zapi_conversas (item 4 do plano de prevenção).
 *
 * Pra cada conversa SEM chat_lid mas com client_id, copia clients.whatsapp_lid
 * (que foi populado em 24/Abr/2026 via /phone-exists pra 1186 clientes).
 * Resultado: webhook passa a bater na Estratégia 0 (CHATLID) direto, em vez
 * de cair na 0a-bis (CLIENT-LID via JOIN).
 *
 * Resolve preventivamente o bug "msgs aparecem em conv duplicada" pra TODOS
 * os clientes que ainda não viraram bug — não só pros que já reportaram.
 *
 * Acesso: ?key=fsa-hub-deploy-2026 [&confirmar=1]
 */
ini_set('display_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/core/database.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
$pdo = db();

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Backfill chat_lid</title>';
echo '<style>body{font-family:system-ui;padding:20px;max-width:1100px;margin:0 auto}h1{color:#052228}.box{padding:.8rem 1rem;border-radius:8px;margin:.5rem 0}.ok{background:#d1fae5;color:#065f46}.no{background:#fee2e2;color:#991b1b}.warn{background:#fef3c7;color:#92400e}table{width:100%;border-collapse:collapse}td,th{padding:6px 8px;border-bottom:1px solid #ddd;font-size:12px;text-align:left}th{background:#052228;color:#fff}</style>';
echo '</head><body>';
echo '<h1>🔄 Backfill chat_lid em zapi_conversas</h1>';

// 1. Levantar candidatos
$st = $pdo->query("SELECT c.id, c.telefone, c.canal, c.client_id, cl.name AS client_name, cl.whatsapp_lid
                   FROM zapi_conversas c
                   JOIN clients cl ON cl.id = c.client_id
                   WHERE (c.chat_lid IS NULL OR c.chat_lid = '')
                     AND cl.whatsapp_lid IS NOT NULL
                     AND cl.whatsapp_lid != ''
                     AND COALESCE(c.eh_grupo, 0) = 0
                   ORDER BY c.id ASC");
$candidatos = $st->fetchAll();
$total = count($candidatos);

echo '<div class="box ' . ($total > 0 ? 'warn' : 'ok') . '">' . $total . ' conversa(s) candidata(s) ao backfill.</div>';

if ($total === 0) {
    echo '<p>Nenhum backfill necessário. Tudo já está mapeado.</p>';
    exit;
}

// Mostra preview dos primeiros 30
$preview = array_slice($candidatos, 0, 30);
echo '<h3>Preview (' . count($preview) . ' de ' . $total . '):</h3>';
echo '<table><thead><tr><th>conv_id</th><th>Telefone</th><th>Canal</th><th>client_id</th><th>Cliente</th><th>chat_lid a copiar</th></tr></thead><tbody>';
foreach ($preview as $c) {
    echo '<tr><td>' . $c['id'] . '</td><td>' . htmlspecialchars($c['telefone'] ?? '') . '</td><td>' . htmlspecialchars($c['canal'] ?? '') . '</td><td>' . $c['client_id'] . '</td><td>' . htmlspecialchars($c['client_name']) . '</td><td><code>' . htmlspecialchars($c['whatsapp_lid']) . '</code></td></tr>';
}
echo '</tbody></table>';

if (!isset($_GET['confirmar'])) {
    echo '<div class="box warn">⚠️ Pra executar, adicione <code>&confirmar=1</code>.</div>';
    exit;
}

// 2. Executar UPDATE em massa via UPDATE...JOIN
$updated = 0;
foreach ($candidatos as $c) {
    try {
        $pdo->prepare("UPDATE zapi_conversas SET chat_lid = ? WHERE id = ? AND (chat_lid IS NULL OR chat_lid = '')")
            ->execute(array($c['whatsapp_lid'], $c['id']));
        $updated++;
    } catch (Exception $e) { /* skip */ }
}
echo '<div class="box ok">✓ Backfill concluído: ' . $updated . ' conversa(s) atualizada(s) com chat_lid.</div>';

// 3. Detectar e mesclar duplicatas residuais (mesmo client_id no canal)
echo '<h3>Detectar e mesclar duplicatas (mesmo client_id no canal):</h3>';
$st2 = $pdo->query("SELECT client_id, canal, COUNT(*) as qtd, GROUP_CONCAT(id ORDER BY id ASC) AS conv_ids
                    FROM zapi_conversas
                    WHERE client_id IS NOT NULL AND client_id > 0
                      AND COALESCE(eh_grupo, 0) = 0
                    GROUP BY client_id, canal
                    HAVING qtd > 1");
$duplicatas = $st2->fetchAll();
if (empty($duplicatas)) {
    echo '<div class="box ok">✓ Nenhuma duplicata detectada.</div>';
} else {
    echo '<div class="box warn">⚠️ ' . count($duplicatas) . ' grupo(s) com conversas duplicadas:</div>';
    require_once __DIR__ . '/core/functions_zapi.php';
    $totalMerged = 0;
    echo '<table><thead><tr><th>client_id</th><th>Canal</th><th>conv_ids</th><th>Resultado</th></tr></thead><tbody>';
    foreach ($duplicatas as $d) {
        $ids = explode(',', $d['conv_ids']);
        $manter = (int)$ids[0]; // mantém a mais antiga
        $merged = zapi_auto_merge_por_client_id($pdo, $manter, (int)$d['client_id'], $d['canal']);
        $totalMerged += $merged;
        echo '<tr><td>' . $d['client_id'] . '</td><td>' . htmlspecialchars($d['canal']) . '</td><td>' . htmlspecialchars($d['conv_ids']) . '</td><td>✓ Mantida #' . $manter . ', mescladas ' . $merged . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<div class="box ok">✓ Total mesclado: ' . $totalMerged . ' conversa(s) absorvidas.</div>';
}

audit_log('zapi_chatlid_backfill', 'zapi_conversas', 0, "Backfill: {$updated} conv. Duplicatas mescladas: " . (isset($totalMerged) ? $totalMerged : 0));
echo '<div class="box ok">✅ Pronto.</div>';
echo '</body></html>';
