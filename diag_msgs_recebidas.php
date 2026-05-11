<?php
/**
 * Diag pra investigar msgs que clientes mandaram mas nao apareceram no Hub.
 * Recebe nome (ou conv_id ou client_id) e mostra:
 *   - Todas as convs do mesmo client_id (potenciais duplicatas)
 *   - Timeline cronologica das ultimas 48h (enviadas + recebidas misturadas)
 *   - Convs orfas (mesmo nome em telefone diferente)
 *
 * Uso: https://ferreiraesa.com.br/conecta/diag_msgs_recebidas.php?key=fsa-hub-deploy-2026&q=aline+fernandes
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/core/database.php';
$pdo = db();

$q = trim($_GET['q'] ?? '');

echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Diag msgs recebidas</title>';
echo '<style>body{font-family:monospace;padding:1.5rem;background:#0a0a0a;color:#e5e7eb;line-height:1.5;max-width:1200px;margin:0 auto;} h1{color:#fbbf24;} h2{color:#60a5fa;border-bottom:1px solid #374151;padding-bottom:.3rem;margin-top:1.5rem;} h3{color:#a78bfa;margin-top:1rem;} table{border-collapse:collapse;margin-top:.5rem;width:100%;font-size:.85rem;} td,th{padding:.4rem .6rem;border-bottom:1px solid #374151;text-align:left;vertical-align:top;} th{background:#1f2937;color:#fbbf24;} .ok{color:#10b981;} .warn{color:#fbbf24;} .err{color:#ef4444;} .muted{color:#6b7280;} form{margin:.5rem 0 1.5rem;} input{padding:.5rem;background:#1f2937;border:1px solid #374151;color:#e5e7eb;border-radius:6px;font-family:inherit;width:300px;} button{padding:.5rem 1rem;background:#3b82f6;border:none;color:#fff;border-radius:6px;cursor:pointer;font-family:inherit;} .recebida{background:#1e3a8a30;} .enviada{background:#16653430;}</style></head><body>';

echo '<h1>📨 Diag de msgs RECEBIDAS (cliente -&gt; Hub)</h1>';
echo '<form method="GET"><input type="hidden" name="key" value="fsa-hub-deploy-2026">';
echo '<input type="text" name="q" placeholder="nome do contato" value="' . htmlspecialchars($q) . '"> <button>Buscar</button></form>';

if (!$q) { echo '<p class="muted">Informe um nome (parcial).</p></body></html>'; exit; }

// 1) Acha conversas pelo nome
$st = $pdo->prepare("SELECT id, canal, telefone, nome_contato, client_id, lead_id, status, ultima_msg_em
                     FROM zapi_conversas
                     WHERE nome_contato LIKE ?
                     ORDER BY ultima_msg_em DESC LIMIT 20");
$st->execute(array('%' . $q . '%'));
$convs = $st->fetchAll();

if (!$convs) { echo '<p class="err">Nenhuma conversa encontrada.</p></body></html>'; exit; }

echo '<h2>1. Convs encontradas (' . count($convs) . ')</h2>';
echo '<table><tr><th>ID</th><th>Canal</th><th>Nome</th><th>Telefone</th><th>Cliente</th><th>Status</th><th>Ult. msg</th></tr>';
$clientIds = array();
foreach ($convs as $c) {
    if ($c['client_id']) $clientIds[(int)$c['client_id']] = true;
    $telClass = (strlen(preg_replace('/\D/', '', $c['telefone'])) === 12 || strlen(preg_replace('/\D/', '', $c['telefone'])) === 13) ? 'ok' : 'warn';
    if (strpos($c['telefone'], '@lid') !== false) $telClass = 'err';
    echo '<tr>';
    echo '<td><strong>' . $c['id'] . '</strong></td>';
    echo '<td>' . $c['canal'] . '</td>';
    echo '<td>' . htmlspecialchars($c['nome_contato']) . '</td>';
    echo '<td class="' . $telClass . '">' . htmlspecialchars($c['telefone']) . '</td>';
    echo '<td>' . ($c['client_id'] ? '#' . $c['client_id'] : '<span class="muted">-</span>') . '</td>';
    echo '<td>' . htmlspecialchars($c['status']) . '</td>';
    echo '<td>' . htmlspecialchars($c['ultima_msg_em'] ?? '?') . '</td>';
    echo '</tr>';
}
echo '</table>';

// 2) Outras convs do(s) mesmo(s) client_id — potenciais duplicatas que escondem msgs
if ($clientIds) {
    $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
    $st2 = $pdo->prepare("SELECT id, canal, telefone, nome_contato, client_id, status, ultima_msg_em
                          FROM zapi_conversas
                          WHERE client_id IN ($placeholders)
                          ORDER BY client_id, ultima_msg_em DESC");
    $st2->execute(array_keys($clientIds));
    $outras = $st2->fetchAll();
    if (count($outras) > count($clientIds)) {
        echo '<h2>2. ⚠ POTENCIAL DUPLICATA: convs do mesmo client_id</h2>';
        echo '<p class="warn">Cada client_id deveria ter no maximo 2 convs (uma por canal). Se tem mais, ha duplicata — msgs novas podem cair na "errada".</p>';
        echo '<table><tr><th>ID</th><th>Canal</th><th>Nome</th><th>Telefone</th><th>Cliente</th><th>Ult. msg</th></tr>';
        foreach ($outras as $c) {
            echo '<tr>';
            echo '<td><strong>' . $c['id'] . '</strong></td>';
            echo '<td>' . $c['canal'] . '</td>';
            echo '<td>' . htmlspecialchars($c['nome_contato']) . '</td>';
            echo '<td>' . htmlspecialchars($c['telefone']) . '</td>';
            echo '<td>#' . $c['client_id'] . '</td>';
            echo '<td>' . htmlspecialchars($c['ultima_msg_em'] ?? '?') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
}

// 3) Timeline cronologica das ultimas 48h de cada conv encontrada
foreach ($convs as $c) {
    echo '<h2>3. Timeline conv #' . $c['id'] . ' (' . htmlspecialchars($c['nome_contato']) . ') — últimas 48h</h2>';
    $st3 = $pdo->prepare("SELECT id, zapi_message_id, direcao, tipo, conteudo, status, entregue, lida, created_at, enviado_por_id, enviado_por_bot
                          FROM zapi_mensagens
                          WHERE conversa_id = ?
                            AND created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
                          ORDER BY id ASC");
    $st3->execute(array($c['id']));
    $msgs = $st3->fetchAll();
    if (!$msgs) { echo '<p class="muted">Nenhuma msg nas ultimas 48h.</p>'; continue; }

    echo '<table><tr><th>#</th><th>Hora</th><th>Direcao</th><th>Tipo</th><th>Status</th><th>zapi_id</th><th>Conteudo</th></tr>';
    foreach ($msgs as $m) {
        $cls = $m['direcao'] === 'recebida' ? 'recebida' : 'enviada';
        $direcaoIco = $m['direcao'] === 'recebida' ? '⬅️ recebida' : '➡️ enviada';
        echo '<tr class="' . $cls . '">';
        echo '<td>' . $m['id'] . '</td>';
        echo '<td>' . htmlspecialchars(substr($m['created_at'], 11, 8)) . '</td>';
        echo '<td>' . $direcaoIco . '</td>';
        echo '<td>' . htmlspecialchars($m['tipo']) . '</td>';
        echo '<td>' . htmlspecialchars($m['status'] ?: '(vazio)') . '</td>';
        echo '<td style="font-size:.7rem;">' . htmlspecialchars(substr($m['zapi_message_id'] ?? '', 0, 16)) . '</td>';
        echo '<td style="max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' . htmlspecialchars($m['conteudo'] ?? '') . '">' . htmlspecialchars(substr($m['conteudo'] ?? '', 0, 100)) . '</td>';
        echo '</tr>';
    }
    echo '</table>';

    $recebidas = 0; $enviadas = 0;
    foreach ($msgs as $m) {
        if ($m['direcao'] === 'recebida') $recebidas++;
        else $enviadas++;
    }
    echo '<p style="margin-top:.5rem;">Total 48h: <strong>' . $recebidas . ' recebida(s)</strong> · <strong>' . $enviadas . ' enviada(s)</strong></p>';
    if ($enviadas > 0 && $recebidas === 0) {
        echo '<p class="err">⚠ ZERO msgs recebidas em 48h apesar de envios — webhook pode ter falhado em entregar OU cliente nao respondeu.</p>';
    }
}

// 4) Logs do webhook (se a tabela existir)
echo '<h2>4. Webhook log (ultimas 30, qualquer mensagem)</h2>';
try {
    $logs = $pdo->query("SELECT id, criado_em, callback_type, numero_instancia, error_msg, msg_id
                         FROM zapi_webhook_log
                         ORDER BY id DESC LIMIT 30")->fetchAll();
    if ($logs) {
        echo '<table><tr><th>ID</th><th>Hora</th><th>Tipo</th><th>Inst</th><th>msg_id</th><th>Erro</th></tr>';
        foreach ($logs as $l) {
            $errClass = $l['error_msg'] ? 'err' : 'muted';
            echo '<tr>';
            echo '<td>' . $l['id'] . '</td>';
            echo '<td>' . htmlspecialchars($l['criado_em']) . '</td>';
            echo '<td>' . htmlspecialchars($l['callback_type']) . '</td>';
            echo '<td>' . htmlspecialchars($l['numero_instancia']) . '</td>';
            echo '<td style="font-size:.7rem;">' . htmlspecialchars(substr($l['msg_id'] ?? '', 0, 16)) . '</td>';
            echo '<td class="' . $errClass . '">' . htmlspecialchars($l['error_msg'] ?: '(ok)') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo '<p class="muted">Sem logs.</p>';
    }
} catch (Exception $e) {
    echo '<p class="muted">Tabela zapi_webhook_log nao existe ainda (foi prevista mas pode nao estar criada).</p>';
}

echo '</body></html>';
