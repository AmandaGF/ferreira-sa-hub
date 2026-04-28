<?php
/**
 * Diag completo da saúde do WhatsApp do Hub:
 * 1. Status das instâncias 21 e 24 (Z-API /device + /status)
 * 2. Últimas 50 mensagens enviadas em geral — flag erro / sem message_id
 * 3. Últimas 30 mensagens de áudio especificamente — Content-Type, URL acessível, message_id
 * 4. Fila de envio pendente (zapi_fila_envio)
 * 5. .htaccess de /files/whatsapp/ — Content-Type override pra .webm/.ogg
 * 6. Buscar mensagens recentes pra um número específico (sufixo) — passe ?numero=
 *
 * Acesso admin: ?key=fsa-hub-deploy-2026 [&numero=SUFIXO]
 */
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
$pdo = db();
$sufixoBusca = preg_replace('/\D/', '', $_GET['numero'] ?? '');

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Saúde WhatsApp Hub</title>';
echo '<style>body{font-family:system-ui,Arial;padding:20px;max-width:1400px;margin:0 auto;}h1,h2{color:#052228;border-bottom:2px solid #B87333;padding-bottom:6px;margin-top:2rem}h2{font-size:1.05rem}table{width:100%;border-collapse:collapse;margin:.5rem 0}th,td{padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:left;font-size:12px;vertical-align:top}th{background:#052228;color:#fff}pre{background:#f8fafc;padding:8px;border-radius:6px;font-size:11px;max-height:240px;overflow:auto;white-space:pre-wrap;word-break:break-all}.box{background:#fef3c7;padding:.6rem 1rem;border-radius:8px;margin:.5rem 0;font-size:.85rem}.ok{color:#065f46;font-weight:700}.no{color:#991b1b;font-weight:700}.warn{color:#b45309;font-weight:700}tr.fail{background:#fee2e2}tr.ok{background:#ecfdf5}.tag{display:inline-block;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700}</style>';
echo '</head><body>';
echo '<h1>🩺 Saúde WhatsApp Hub — ' . date('d/m/Y H:i') . '</h1>';

// ─── 1. Status das instâncias 21 e 24 ────────────────────────────────
echo '<h2>1. Status das instâncias Z-API</h2>';
$cfg = zapi_get_config();
foreach (array('21', '24') as $ddd) {
    echo '<h3 style="margin-top:1rem;">DDD ' . $ddd . '</h3>';
    $inst = zapi_get_instancia($ddd);
    if (!$inst) { echo '<p class="no">Instância DDD ' . $ddd . ' não configurada.</p>'; continue; }
    if (empty($inst['instancia_id']) || empty($inst['token'])) {
        echo '<p class="no">Instância sem ID/token.</p>'; continue;
    }
    $url = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'] . '/status';
    $headers = array('Content-Type: application/json');
    if (!empty($cfg['client_token'])) $headers[] = 'Client-Token: ' . $cfg['client_token'];
    $ch = curl_init($url);
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_HTTPHEADER=>$headers, CURLOPT_SSL_VERIFYPEER=>false));
    $r = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($r, true);
    $conectado = is_array($j) && !empty($j['connected']);
    echo '<p>HTTP ' . $http . ' · ' . ($conectado ? '<span class="ok">✓ CONECTADO</span>' : '<span class="no">✕ DESCONECTADO</span>') . '</p>';
    echo '<pre>' . htmlspecialchars($r ?: '(sem resposta)') . '</pre>';
}

// ─── 2. Últimas 50 mensagens enviadas em geral ────────────────────────
echo '<h2>2. Últimas 50 mensagens enviadas (todas as conversas)</h2>';
try {
    $st = $pdo->query("SELECT m.*, c.telefone, c.nome_contato, c.canal
                       FROM zapi_mensagens m
                       LEFT JOIN zapi_conversas c ON c.id = m.conversa_id
                       WHERE m.direcao = 'enviada'
                       ORDER BY m.id DESC LIMIT 50");
    $msgs = $st ? $st->fetchAll() : array();
    $semId = 0; $erros = 0;
    foreach ($msgs as $m) {
        $msgId = $m['zapi_message_id'] ?? '';
        $status = $m['status'] ?? '';
        if (empty($msgId)) $semId++;
        if ($status === 'erro' || $status === 'falhou') $erros++;
    }
    echo '<div class="box">📊 ' . count($msgs) . ' enviadas analisadas · '
       . '<strong>' . $semId . '</strong> SEM zapi_message_id (provável falha de envio) · '
       . '<strong>' . $erros . '</strong> com status erro/falhou</div>';

    if (!empty($msgs)) {
        echo '<table><thead><tr><th>ID</th><th>Conv</th><th>Telefone</th><th>Canal</th><th>Tipo</th><th>Conteúdo</th><th>Status</th><th>zapi_message_id</th><th>Quando</th><th>Resposta Z-API</th></tr></thead><tbody>';
        foreach ($msgs as $m) {
            $msgId = $m['zapi_message_id'] ?? '';
            $cls = empty($msgId) ? 'fail' : 'ok';
            $resp = $m['zapi_response'] ?? '';
            $respCurto = '';
            if ($resp) {
                $j = json_decode($resp, true);
                if ($j) {
                    if (isset($j['error'])) $respCurto = '❌ ' . (is_string($j['error']) ? $j['error'] : json_encode($j['error']));
                    elseif (isset($j['message'])) $respCurto = (is_string($j['message']) ? $j['message'] : json_encode($j['message']));
                    elseif (isset($j['zaapId'])) $respCurto = 'OK zaapId=' . substr($j['zaapId'], 0, 12);
                    elseif (isset($j['id'])) $respCurto = 'OK id=' . substr($j['id'], 0, 12);
                    else $respCurto = substr($resp, 0, 80);
                } else $respCurto = substr($resp, 0, 80);
            }
            echo '<tr class="' . $cls . '">';
            echo '<td>' . $m['id'] . '</td>';
            echo '<td>' . ($m['conversa_id'] ?? '-') . '</td>';
            echo '<td>' . htmlspecialchars($m['telefone'] ?? '-') . '</td>';
            echo '<td>' . htmlspecialchars($m['canal'] ?? '-') . '</td>';
            echo '<td>' . htmlspecialchars($m['tipo'] ?? '-') . '</td>';
            echo '<td>' . htmlspecialchars(mb_substr($m['conteudo'] ?? '', 0, 60)) . '</td>';
            echo '<td>' . htmlspecialchars($m['status'] ?? '-') . '</td>';
            echo '<td><code style="font-size:10px">' . htmlspecialchars($msgId ?: '<vazio>') . '</code></td>';
            echo '<td>' . htmlspecialchars($m['criada_em'] ?? '-') . '</td>';
            echo '<td>' . htmlspecialchars($respCurto) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
} catch (Exception $e) { echo '<p class="no">Erro: ' . htmlspecialchars($e->getMessage()) . '</p>'; }

// ─── 3. Últimas mensagens de áudio especificamente ────────────────────
echo '<h2>3. Últimas 30 mensagens de áudio enviadas</h2>';
try {
    $st = $pdo->query("SELECT m.*, c.telefone, c.canal FROM zapi_mensagens m
                       LEFT JOIN zapi_conversas c ON c.id = m.conversa_id
                       WHERE m.direcao='enviada' AND (m.tipo='audio' OR m.tipo='ptt' OR m.conteudo LIKE '%.webm' OR m.conteudo LIKE '%.ogg' OR m.conteudo LIKE '%.mp3')
                       ORDER BY m.id DESC LIMIT 30");
    $audios = $st ? $st->fetchAll() : array();
    if (empty($audios)) {
        echo '<p>Nenhum áudio enviado encontrado nas mensagens recentes.</p>';
    } else {
        echo '<table><thead><tr><th>ID</th><th>Telefone</th><th>Canal</th><th>Tipo</th><th>URL/Conteúdo</th><th>Status</th><th>zapi_message_id</th><th>Resposta</th><th>URL acessível?</th></tr></thead><tbody>';
        foreach ($audios as $a) {
            $msgId = $a['zapi_message_id'] ?? '';
            $cls = empty($msgId) ? 'fail' : 'ok';
            $url = $a['conteudo'] ?? '';
            $urlOk = '?';
            if ($url && (strpos($url, 'http') === 0 || strpos($url, '/') === 0)) {
                $urlChk = strpos($url, 'http') === 0 ? $url : 'https://ferreiraesa.com.br' . $url;
                $ch = curl_init($urlChk);
                curl_setopt_array($ch, array(CURLOPT_NOBODY=>true, CURLOPT_TIMEOUT=>5, CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_SSL_VERIFYPEER=>false));
                curl_exec($ch);
                $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                curl_close($ch);
                $urlOk = $http . ' · ' . ($ct ?: '?');
            }
            $resp = $a['zapi_response'] ?? '';
            $respCurto = $resp ? mb_substr($resp, 0, 100) : '';
            echo '<tr class="' . $cls . '">';
            echo '<td>' . $a['id'] . '</td>';
            echo '<td>' . htmlspecialchars($a['telefone'] ?? '-') . '</td>';
            echo '<td>' . htmlspecialchars($a['canal'] ?? '-') . '</td>';
            echo '<td><strong>' . htmlspecialchars($a['tipo'] ?? '-') . '</strong></td>';
            echo '<td><code style="font-size:10px">' . htmlspecialchars(mb_substr($url, 0, 70)) . '</code></td>';
            echo '<td>' . htmlspecialchars($a['status'] ?? '-') . '</td>';
            echo '<td><code style="font-size:10px">' . htmlspecialchars($msgId ?: '<vazio>') . '</code></td>';
            echo '<td>' . htmlspecialchars($respCurto) . '</td>';
            echo '<td>' . htmlspecialchars($urlOk) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
} catch (Exception $e) { echo '<p class="no">Erro: ' . htmlspecialchars($e->getMessage()) . '</p>'; }

// ─── 4. Fila de envio pendente ───────────────────────────────────────
echo '<h2>4. Fila de envio pendente</h2>';
try {
    $st = $pdo->query("SELECT * FROM zapi_fila_envio WHERE status IN ('pendente','retry','aguardando') ORDER BY id DESC LIMIT 30");
    $fila = $st ? $st->fetchAll() : array();
    if (empty($fila)) {
        echo '<p class="ok">Sem mensagens pendentes na fila.</p>';
    } else {
        echo '<div class="box warn">⚠️ ' . count($fila) . ' mensagens aguardando envio</div>';
        echo '<table><thead><tr><th>ID</th><th>Telefone</th><th>Status</th><th>Tentativas</th><th>Último erro</th><th>Mensagem (resumo)</th><th>Criada</th></tr></thead><tbody>';
        foreach ($fila as $f) {
            echo '<tr>';
            echo '<td>' . $f['id'] . '</td>';
            echo '<td>' . htmlspecialchars($f['telefone'] ?? '-') . '</td>';
            echo '<td>' . htmlspecialchars($f['status'] ?? '-') . '</td>';
            echo '<td>' . htmlspecialchars($f['tentativas'] ?? '0') . '</td>';
            echo '<td>' . htmlspecialchars(mb_substr($f['erro'] ?? '', 0, 100)) . '</td>';
            echo '<td>' . htmlspecialchars(mb_substr($f['mensagem'] ?? '', 0, 80)) . '</td>';
            echo '<td>' . htmlspecialchars($f['criada_em'] ?? $f['created_at'] ?? '-') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
} catch (Exception $e) { echo '<p class="no">Erro: ' . htmlspecialchars($e->getMessage()) . '</p>'; }

// ─── 5. Verificar Content-Type de áudios ─────────────────────────────
echo '<h2>5. Content-Type de arquivos /files/whatsapp/</h2>';
$htPath = __DIR__ . '/files/whatsapp/.htaccess';
if (file_exists($htPath)) {
    echo '<p class="ok">✓ .htaccess existe em /files/whatsapp/</p>';
    echo '<pre>' . htmlspecialchars(file_get_contents($htPath)) . '</pre>';
} else {
    echo '<p class="no">✕ .htaccess NÃO existe em /files/whatsapp/. Áudios .webm podem ser servidos como video/webm e a Z-API rejeita.</p>';
}

// ─── 6. Busca por número específico (opcional) ───────────────────────
if ($sufixoBusca) {
    echo '<h2>6. Busca por sufixo "' . htmlspecialchars($sufixoBusca) . '"</h2>';
    // Procura em conversas
    $st = $pdo->prepare("SELECT id, telefone, chat_lid, client_id, canal, status FROM zapi_conversas WHERE telefone LIKE ? OR chat_lid LIKE ?");
    $st->execute(array('%' . $sufixoBusca . '%', '%' . $sufixoBusca . '%'));
    $convs = $st->fetchAll();
    if (empty($convs)) {
        echo '<p class="no">Nenhuma conversa contendo "' . $sufixoBusca . '".</p>';
    } else {
        echo '<h3>Conversas:</h3><table><thead><tr><th>ID</th><th>Telefone</th><th>chat_lid</th><th>client_id</th><th>Canal</th><th>Status</th></tr></thead><tbody>';
        foreach ($convs as $c) echo '<tr><td>' . $c['id'] . '</td><td>' . htmlspecialchars($c['telefone'] ?? '') . '</td><td>' . htmlspecialchars($c['chat_lid'] ?? '-') . '</td><td>' . ($c['client_id'] ?? '-') . '</td><td>' . htmlspecialchars($c['canal'] ?? '') . '</td><td>' . htmlspecialchars($c['status'] ?? '-') . '</td></tr>';
        echo '</tbody></table>';

        // Mensagens das conversas encontradas
        $convIds = array_column($convs, 'id');
        $ph = implode(',', array_fill(0, count($convIds), '?'));
        $st = $pdo->prepare("SELECT * FROM zapi_mensagens WHERE conversa_id IN ($ph) ORDER BY id DESC LIMIT 30");
        $st->execute($convIds);
        $msgs2 = $st->fetchAll();
        if ($msgs2) {
            echo '<h3>Últimas 30 mensagens dessas conversas:</h3>';
            echo '<table><thead><tr><th>ID</th><th>Conv</th><th>Direção</th><th>Tipo</th><th>Conteúdo</th><th>Status</th><th>message_id</th><th>Criada</th></tr></thead><tbody>';
            foreach ($msgs2 as $m) {
                $cls = (($m['direcao'] ?? '') === 'enviada' && empty($m['zapi_message_id'])) ? 'fail' : '';
                echo '<tr class="' . $cls . '"><td>' . $m['id'] . '</td><td>' . ($m['conversa_id'] ?? '-') . '</td><td><strong>' . htmlspecialchars($m['direcao'] ?? '-') . '</strong></td><td>' . htmlspecialchars($m['tipo'] ?? '-') . '</td><td>' . htmlspecialchars(mb_substr($m['conteudo'] ?? '', 0, 60)) . '</td><td>' . htmlspecialchars($m['status'] ?? '-') . '</td><td><code style="font-size:10px">' . htmlspecialchars($m['zapi_message_id'] ?? '') . '</code></td><td>' . htmlspecialchars($m['criada_em'] ?? '-') . '</td></tr>';
            }
            echo '</tbody></table>';
        }
    }

    // Cliente
    $st = $pdo->prepare("SELECT id, name, phone, whatsapp_lid FROM clients WHERE phone LIKE ? OR REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')','') LIKE ? OR whatsapp_lid LIKE ?");
    $st->execute(array('%' . $sufixoBusca . '%', '%' . $sufixoBusca . '%', '%' . $sufixoBusca . '%'));
    $cli = $st->fetchAll();
    if ($cli) {
        echo '<h3>Cliente(s) com "' . $sufixoBusca . '":</h3><table><thead><tr><th>ID</th><th>Nome</th><th>phone</th><th>whatsapp_lid</th></tr></thead><tbody>';
        foreach ($cli as $c) echo '<tr><td>' . $c['id'] . '</td><td>' . htmlspecialchars($c['name']) . '</td><td>' . htmlspecialchars($c['phone']) . '</td><td>' . htmlspecialchars($c['whatsapp_lid'] ?? '-') . '</td></tr>';
        echo '</tbody></table>';
    } else {
        echo '<p class="no">Nenhum cliente com sufixo "' . $sufixoBusca . '".</p>';
    }
}

echo '<hr><p style="font-size:.8rem;color:#94a3b8;">Diag rodado em ' . date('Y-m-d H:i:s') . '</p>';
echo '</body></html>';
