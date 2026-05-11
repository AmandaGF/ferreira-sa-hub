<?php
/**
 * Diagnostico de msgs enviadas pelo Hub que nao chegam no celular do cliente.
 * Mostra, pra um contato (nome ou telefone): telefone armazenado, ultimas
 * 15 msgs enviadas com zapi_id + status real (PENDING/SENT/DELIVERED/READ).
 *
 * Uso: https://ferreiraesa.com.br/conecta/diag_envio_wa.php?key=fsa-hub-deploy-2026&q=ailanda
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/core/database.php';
$pdo = db();

$q = trim($_GET['q'] ?? '');

echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Diag envio WA</title>';
echo '<style>body{font-family:monospace;padding:1.5rem;background:#0a0a0a;color:#e5e7eb;line-height:1.5;max-width:1100px;margin:0 auto;} h1{color:#fbbf24;} h2{color:#60a5fa;border-bottom:1px solid #374151;padding-bottom:.3rem;margin-top:1.5rem;} table{border-collapse:collapse;margin-top:.5rem;width:100%;font-size:.85rem;} td,th{padding:.4rem .6rem;border-bottom:1px solid #374151;text-align:left;vertical-align:top;} th{background:#1f2937;color:#fbbf24;} .ok{color:#10b981;} .warn{color:#fbbf24;} .err{color:#ef4444;} .muted{color:#6b7280;} form{margin:.5rem 0 1.5rem;} input{padding:.5rem;background:#1f2937;border:1px solid #374151;color:#e5e7eb;border-radius:6px;font-family:inherit;width:300px;} button{padding:.5rem 1rem;background:#3b82f6;border:none;color:#fff;border-radius:6px;cursor:pointer;font-family:inherit;}</style></head><body>';

echo '<h1>🔍 Diagnostico de envio WhatsApp</h1>';
echo '<form method="GET"><input type="hidden" name="key" value="fsa-hub-deploy-2026">';
echo '<input type="text" name="q" placeholder="nome ou telefone" value="' . htmlspecialchars($q) . '"> <button>Buscar</button></form>';

if (!$q) { echo '<p class="muted">Informe um nome (parcial) ou telefone pra buscar.</p></body></html>'; exit; }

// Busca conversas
$digits = preg_replace('/\D/', '', $q);
if (strlen($digits) >= 4) {
    $st = $pdo->prepare("SELECT * FROM zapi_conversas
                         WHERE REPLACE(telefone,'@lid','') LIKE ? OR nome_contato LIKE ?
                         ORDER BY ultima_msg_em DESC LIMIT 10");
    $st->execute(array('%' . $digits . '%', '%' . $q . '%'));
} else {
    $st = $pdo->prepare("SELECT * FROM zapi_conversas WHERE nome_contato LIKE ? ORDER BY ultima_msg_em DESC LIMIT 10");
    $st->execute(array('%' . $q . '%'));
}
$convs = $st->fetchAll();

if (!$convs) { echo '<p class="err">Nenhuma conversa encontrada.</p></body></html>'; exit; }

echo '<h2>1. Conversas encontradas (' . count($convs) . ')</h2>';
echo '<table><tr><th>ID</th><th>Canal</th><th>Nome</th><th>Telefone armazenado</th><th>Cliente</th><th>Ult. msg</th></tr>';
foreach ($convs as $c) {
    $tel = $c['telefone'];
    $telClass = '';
    if (stripos($tel, '@lid') !== false) $telClass = 'err';
    elseif (stripos($tel, '@s.whatsapp.net') !== false) $telClass = 'warn';
    elseif (strlen(preg_replace('/\D/', '', $tel)) < 12) $telClass = 'warn';
    else $telClass = 'ok';

    echo '<tr>';
    echo '<td>' . $c['id'] . '</td>';
    echo '<td>' . htmlspecialchars($c['canal']) . '</td>';
    echo '<td>' . htmlspecialchars($c['nome_contato'] ?? '') . '</td>';
    echo '<td class="' . $telClass . '">' . htmlspecialchars($tel) . '</td>';
    echo '<td>' . ($c['client_id'] ? '#' . $c['client_id'] : '<span class="muted">-</span>') . '</td>';
    echo '<td>' . htmlspecialchars($c['ultima_msg_em'] ?? '') . '</td>';
    echo '</tr>';
}
echo '</table>';

// Pra cada conversa, busca as ultimas msgs enviadas e mostra status real
foreach ($convs as $c) {
    echo '<h2>2. Msgs enviadas da conv #' . $c['id'] . ' (' . htmlspecialchars($c['nome_contato'] ?? '?') . ')</h2>';

    $st2 = $pdo->prepare("SELECT id, zapi_message_id, conteudo, status, entregue, lida, created_at, enviado_por_id
                          FROM zapi_mensagens
                          WHERE conversa_id = ? AND direcao = 'enviada'
                          ORDER BY id DESC LIMIT 15");
    $st2->execute(array($c['id']));
    $msgs = $st2->fetchAll();

    if (!$msgs) { echo '<p class="muted">Nenhuma msg enviada nessa conversa.</p>'; continue; }

    echo '<table><tr><th>#</th><th>Data</th><th>zapi_id</th><th>Prefixo</th><th>Status</th><th>Entr.</th><th>Lida</th><th>Conteudo</th></tr>';
    foreach ($msgs as $m) {
        $zid = $m['zapi_message_id'] ?? '';
        $prefix = substr($zid, 0, 4);
        $prefixClass = '';
        $prefixTip = '';
        if (!$zid) { $prefixClass = 'err'; $prefixTip = 'SEM ID — Z-API nao retornou'; }
        elseif (strtoupper($prefix) === '3EB0') { $prefixClass = 'ok'; $prefixTip = 'normal'; }
        elseif (strtoupper($prefix) === '1D15') { $prefixClass = 'err'; $prefixTip = '⚠ ANOMALIA — visto em msgs que nao chegam (Sarah 04/05/2026)'; }
        else { $prefixClass = 'warn'; $prefixTip = 'prefixo inusual'; }

        $statusReal = $m['status'] ?? '';
        $statusClass = '';
        if ($statusReal === 'enviada') $statusClass = 'warn';  // default do INSERT, webhook nunca confirmou
        elseif (in_array(strtoupper($statusReal), array('DELIVERED','RECEIVED','READ'))) $statusClass = 'ok';
        elseif (strtoupper($statusReal) === 'SENT' || strtoupper($statusReal) === 'PENDING') $statusClass = 'warn';
        elseif (strtoupper($statusReal) === 'PLAYED') $statusClass = 'ok';
        else $statusClass = 'err';

        echo '<tr>';
        echo '<td>' . $m['id'] . '</td>';
        echo '<td>' . htmlspecialchars($m['created_at']) . '</td>';
        echo '<td style="font-size:.7rem;">' . htmlspecialchars($zid ?: '(vazio)') . '</td>';
        echo '<td class="' . $prefixClass . '" title="' . htmlspecialchars($prefixTip) . '">' . htmlspecialchars($prefix ?: '?') . '</td>';
        echo '<td class="' . $statusClass . '">' . htmlspecialchars($statusReal ?: '(vazio)') . '</td>';
        echo '<td>' . ($m['entregue'] ? '✓' : '<span class="muted">-</span>') . '</td>';
        echo '<td>' . ($m['lida'] ? '✓' : '<span class="muted">-</span>') . '</td>';
        echo '<td style="max-width:380px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.75rem;" title="' . htmlspecialchars($m['conteudo'] ?? '') . '">' . htmlspecialchars(substr($m['conteudo'] ?? '', 0, 80)) . '</td>';
        echo '</tr>';
    }
    echo '</table>';

    // Resumo das ultimas 5
    $ultimas5 = array_slice($msgs, 0, 5);
    $semId = 0; $semStatus = 0; $entregues = 0;
    foreach ($ultimas5 as $m) {
        if (empty($m['zapi_message_id'])) $semId++;
        if (in_array(strtoupper($m['status'] ?? ''), array('enviada',''), true)) $semStatus++;
        if ($m['entregue']) $entregues++;
    }
    echo '<p style="margin-top:.5rem;font-size:.85rem;">';
    echo 'Das ultimas 5 enviadas: ';
    echo '<span class="' . ($semId == 0 ? 'ok' : 'err') . '">' . (5 - $semId) . '/5 com zapi_id</span> · ';
    echo '<span class="' . ($entregues >= 3 ? 'ok' : ($entregues > 0 ? 'warn' : 'err')) . '">' . $entregues . '/5 confirmadas pelo webhook como entregues</span>';
    echo '</p>';

    if ($entregues === 0 && count($ultimas5) > 0) {
        echo '<p class="err">⚠ NENHUMA das ultimas 5 msgs chegou a ser confirmada como entregue pelo webhook Z-API. ';
        echo 'Causas comuns: (a) telefone armazenado nao e numero real do cliente; (b) cliente bloqueou o nosso numero; ';
        echo '(c) cliente sem WhatsApp ativo; (d) instancia Z-API com problema (verifique em /modules/admin/zapi_saude.php).</p>';
    }
}

echo '<h2>3. Estado das instancias Z-API</h2>';
$insts = $pdo->query("SELECT ddd, conectado, ultima_verificacao FROM zapi_instancias")->fetchAll();
echo '<table><tr><th>DDD</th><th>Conectado</th><th>Ult. verificacao</th></tr>';
foreach ($insts as $i) {
    echo '<tr>';
    echo '<td>' . htmlspecialchars($i['ddd']) . '</td>';
    echo '<td class="' . ($i['conectado'] ? 'ok' : 'err') . '">' . ($i['conectado'] ? '✓ Sim' : '✗ Nao') . '</td>';
    echo '<td>' . htmlspecialchars($i['ultima_verificacao'] ?? '?') . '</td>';
    echo '</tr>';
}
echo '</table>';

echo '</body></html>';
