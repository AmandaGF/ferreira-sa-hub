<?php
/**
 * Diag: investigar entrega de mensagens WhatsApp pra um número específico.
 *
 * - Lista as conversas que existem com esse número
 * - Lista as últimas mensagens enviadas (direção=enviada) com status, retorno
 *   da Z-API, message_id, e flags relevantes
 * - Lista mensagens recebidas pra confirmar se o número responde (descarta
 *   teoria de número inválido / bloqueado pelo dono)
 * - Confere se há fila pendente que ainda não saiu
 * - Confere clients.whatsapp_lid pra ver se o lid canônico está mapeado
 *
 * Acesso: ?key=fsa-hub-deploy-2026&numero=21967029991
 */
require_once __DIR__ . '/core/database.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
$pdo = db();

$numeroRaw = trim($_GET['numero'] ?? '');
if (!$numeroRaw) { exit('Use ?numero=21967029991'); }

// Normaliza: só dígitos, e gera variantes prováveis (com/sem 55, com/sem 9 inicial do DDD9)
$digitos = preg_replace('/\D/', '', $numeroRaw);
$variantes = array($digitos);
if (strpos($digitos, '55') !== 0) $variantes[] = '55' . $digitos;
$semNono = preg_replace('/^(\d{2})9(\d{8})$/', '$1$2', $digitos); // 21967029991 → 2167029991
if ($semNono !== $digitos) {
    $variantes[] = $semNono;
    $variantes[] = '55' . $semNono;
}
$variantes = array_unique($variantes);

$placeholders = implode(',', array_fill(0, count($variantes), '?'));

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Diag WA — ' . htmlspecialchars($numeroRaw) . '</title>';
echo '<style>body{font-family:system-ui,Arial;padding:20px;max-width:1200px;margin:0 auto;}h1,h2{color:#052228}table{width:100%;border-collapse:collapse;margin:.5rem 0 1rem}th,td{padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:left;font-size:12px;vertical-align:top}th{background:#052228;color:#fff}pre{background:#f8fafc;padding:8px;border-radius:6px;font-size:11px;max-height:200px;overflow:auto;white-space:pre-wrap;word-break:break-all}.box{background:#fef3c7;padding:.6rem 1rem;border-radius:8px;margin:.5rem 0;font-size:.85rem}.ok{color:#065f46;font-weight:700}.no{color:#991b1b;font-weight:700}</style>';
echo '</head><body><h1>Diag WhatsApp — ' . htmlspecialchars($numeroRaw) . '</h1>';
echo '<div class="box">Variantes testadas: <code>' . htmlspecialchars(implode(', ', $variantes)) . '</code></div>';

// 1. Conversas
echo '<h2>1. Conversas no banco</h2>';
$st = $pdo->prepare("SELECT id, telefone, chat_lid, client_id, canal, ultima_mensagem_em, status_atendimento, atendente_id
                     FROM zapi_conversas WHERE telefone IN ($placeholders) OR chat_lid IN ($placeholders)
                     ORDER BY ultima_mensagem_em DESC");
$params = array_merge($variantes, $variantes);
$st->execute($params);
$conversas = $st->fetchAll();
if (empty($conversas)) {
    echo '<p class="no">Nenhuma conversa encontrada com esse número.</p>';
} else {
    echo '<table><thead><tr><th>ID</th><th>Telefone</th><th>chat_lid</th><th>client_id</th><th>Canal</th><th>Última msg</th><th>Status</th><th>Atendente</th></tr></thead><tbody>';
    foreach ($conversas as $c) {
        echo '<tr><td>' . $c['id'] . '</td><td>' . htmlspecialchars($c['telefone']) . '</td><td>' . htmlspecialchars($c['chat_lid'] ?: '—') . '</td><td>' . ($c['client_id'] ?: '—') . '</td><td>' . htmlspecialchars($c['canal']) . '</td><td>' . $c['ultima_mensagem_em'] . '</td><td>' . htmlspecialchars($c['status_atendimento']) . '</td><td>' . ($c['atendente_id'] ?: '—') . '</td></tr>';
    }
    echo '</tbody></table>';
}

// 2. Cliente vinculado + whatsapp_lid
$st = $pdo->prepare("SELECT id, name, phone, whatsapp_lid FROM clients WHERE phone IN ($placeholders) OR whatsapp_lid LIKE ? OR REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')','') IN ($placeholders) LIMIT 10");
$paramsCli = array_merge($variantes, array('%' . $digitos . '%'), $variantes);
$st->execute($paramsCli);
$clientes = $st->fetchAll();
echo '<h2>2. Cliente(s) com esse número</h2>';
if (empty($clientes)) {
    echo '<p class="no">Nenhum cliente com esse telefone cadastrado.</p>';
} else {
    echo '<table><thead><tr><th>ID</th><th>Nome</th><th>phone</th><th>whatsapp_lid</th></tr></thead><tbody>';
    foreach ($clientes as $cl) {
        echo '<tr><td>' . $cl['id'] . '</td><td>' . htmlspecialchars($cl['name']) . '</td><td>' . htmlspecialchars($cl['phone']) . '</td><td>' . htmlspecialchars($cl['whatsapp_lid'] ?: '—') . '</td></tr>';
    }
    echo '</tbody></table>';
}

// 3. Últimas 30 mensagens da(s) conversa(s)
if (!empty($conversas)) {
    $convIds = array_column($conversas, 'id');
    $phMsg = implode(',', array_fill(0, count($convIds), '?'));
    $st = $pdo->prepare("SELECT id, conversa_id, direcao, tipo, conteudo, zapi_message_id, zapi_response, status,
                                enviado_por_id, criada_em, lida
                         FROM zapi_mensagens WHERE conversa_id IN ($phMsg)
                         ORDER BY criada_em DESC LIMIT 50");
    $st->execute($convIds);
    $msgs = $st->fetchAll();

    echo '<h2>3. Últimas 50 mensagens (conversa(s) acima)</h2>';
    if (empty($msgs)) {
        echo '<p class="no">Nenhuma mensagem registrada.</p>';
    } else {
        $enviadas = 0; $recebidas = 0; $semId = 0;
        foreach ($msgs as $m) {
            if ($m['direcao'] === 'enviada') $enviadas++;
            elseif ($m['direcao'] === 'recebida') $recebidas++;
            if ($m['direcao'] === 'enviada' && empty($m['zapi_message_id'])) $semId++;
        }
        echo '<div class="box">📊 ' . $enviadas . ' enviadas · ' . $recebidas . ' recebidas · ' . $semId . ' enviadas SEM zapi_message_id (provável falha de envio)</div>';

        echo '<table><thead><tr><th>ID</th><th>Direção</th><th>Tipo</th><th>Conteúdo</th><th>Status</th><th>zapi_message_id</th><th>Quando</th><th>Z-API response (resumo)</th></tr></thead><tbody>';
        foreach ($msgs as $m) {
            $resp = $m['zapi_response'];
            $respCurto = '';
            if ($resp) {
                $j = json_decode($resp, true);
                if ($j) {
                    $respCurto = isset($j['error']) ? '❌ ' . $j['error']
                              : (isset($j['message']) ? $j['message']
                              : (isset($j['zaapId']) ? 'OK zaapId=' . substr($j['zaapId'], 0, 16)
                              : substr($resp, 0, 80)));
                } else {
                    $respCurto = substr($resp, 0, 80);
                }
            }
            $cor = $m['direcao'] === 'enviada' ? '#ecfdf5' : '#fff';
            if ($m['direcao'] === 'enviada' && empty($m['zapi_message_id'])) $cor = '#fee2e2';
            echo '<tr style="background:' . $cor . ';">';
            echo '<td>' . $m['id'] . '</td>';
            echo '<td><strong>' . htmlspecialchars($m['direcao']) . '</strong></td>';
            echo '<td>' . htmlspecialchars($m['tipo'] ?: '-') . '</td>';
            echo '<td>' . htmlspecialchars(mb_substr($m['conteudo'] ?: '', 0, 60)) . '</td>';
            echo '<td>' . htmlspecialchars($m['status'] ?: '-') . '</td>';
            echo '<td><code style="font-size:10px;">' . htmlspecialchars($m['zapi_message_id'] ?: '<vazio>') . '</code></td>';
            echo '<td>' . htmlspecialchars($m['criada_em']) . '</td>';
            echo '<td>' . htmlspecialchars($respCurto) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // Mostra o último response completo de uma mensagem enviada com falha (se houver)
        $ultimaFalha = null;
        foreach ($msgs as $m) {
            if ($m['direcao'] === 'enviada' && (empty($m['zapi_message_id']) || $m['status'] === 'erro')) {
                $ultimaFalha = $m; break;
            }
        }
        if ($ultimaFalha) {
            echo '<h3>🔴 Última mensagem enviada com falha — payload completo da Z-API</h3>';
            echo '<pre>' . htmlspecialchars(json_encode(json_decode($ultimaFalha['zapi_response'], true) ?: $ultimaFalha['zapi_response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
        }
    }
}

// 4. Fila pendente
echo '<h2>4. Fila pendente (zapi_fila)</h2>';
try {
    $st = $pdo->prepare("SELECT id, telefone, mensagem, status, tentativas, ultima_tentativa_em, criada_em
                         FROM zapi_fila WHERE telefone IN ($placeholders) ORDER BY criada_em DESC LIMIT 20");
    $st->execute($variantes);
    $fila = $st->fetchAll();
    if (empty($fila)) {
        echo '<p class="ok">Nenhuma mensagem pendente na fila.</p>';
    } else {
        echo '<table><thead><tr><th>ID</th><th>Telefone</th><th>Mensagem</th><th>Status</th><th>Tentativas</th><th>Criada</th></tr></thead><tbody>';
        foreach ($fila as $f) {
            echo '<tr><td>' . $f['id'] . '</td><td>' . htmlspecialchars($f['telefone']) . '</td><td>' . htmlspecialchars(mb_substr($f['mensagem'] ?: '', 0, 80)) . '</td><td>' . htmlspecialchars($f['status']) . '</td><td>' . $f['tentativas'] . '</td><td>' . $f['criada_em'] . '</td></tr>';
        }
        echo '</tbody></table>';
    }
} catch (Exception $e) {
    echo '<p>Tabela zapi_fila: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

// 5. Verificar /phone-exists na Z-API (canal 21 — comercial)
echo '<h2>5. Z-API /phone-exists (canal 21 Comercial)</h2>';
try {
    $stIns = $pdo->query("SELECT instance_id, token, client_token FROM zapi_instancias WHERE numero = '21' OR canal = '21' LIMIT 1");
    $ins = $stIns ? $stIns->fetch() : null;
    if ($ins) {
        $url = 'https://api.z-api.io/instances/' . $ins['instance_id'] . '/token/' . $ins['token'] . '/phone-exists/' . $digitos;
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array('Client-Token: ' . $ins['client_token']),
            CURLOPT_TIMEOUT => 10,
        ));
        $r = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        echo '<p>HTTP ' . $http . ' — resposta:</p><pre>' . htmlspecialchars($r) . '</pre>';
        $j = json_decode($r, true);
        if (is_array($j)) {
            echo '<p>';
            echo isset($j['exists']) && $j['exists'] ? '<span class="ok">✓ Número EXISTE no WhatsApp</span>' : '<span class="no">✕ Número NÃO existe no WhatsApp ou está com problema</span>';
            if (!empty($j['lid'])) echo ' · LID canônico: <code>' . htmlspecialchars($j['lid']) . '</code>';
            echo '</p>';
        }
    } else {
        echo '<p class="no">Não achei instância 21 em zapi_instancias.</p>';
    }
} catch (Exception $e) {
    echo '<p>Erro: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

echo '</body></html>';
