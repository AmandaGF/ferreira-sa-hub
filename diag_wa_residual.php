<?php
/**
 * Varredura única de todos os tipos de conversas problemáticas residuais
 * que tendem a virar bug de "msg não chegou":
 *
 * 1. Conv com telefone em formato @lid bruto (sem número real)
 * 2. Conv com telefone tamanho anormal (>15 dígitos = quase certeza @lid)
 * 3. Conv sem client_id vinculado (cliente que escreveu mas não tá cadastrado)
 * 4. Conv duplicadas residuais (mesmo client_id no canal)
 * 5. Mensagens enviadas últimas 7 dias com status vazio (falha silenciosa)
 *
 * Acesso admin: ?key=fsa-hub-deploy-2026
 */
ini_set('display_errors','1'); error_reporting(E_ALL);
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
$pdo = db();

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Varredura WA residual</title>';
echo '<style>body{font-family:system-ui;padding:20px;max-width:1300px;margin:0 auto}h1,h2{color:#052228;border-bottom:2px solid #B87333;padding-bottom:6px}h2{font-size:1rem;margin-top:2rem}table{width:100%;border-collapse:collapse;margin:.5rem 0}td,th{padding:6px 8px;border-bottom:1px solid #ddd;font-size:12px;text-align:left;vertical-align:top}th{background:#052228;color:#fff}.box{padding:.6rem 1rem;border-radius:8px;margin:.5rem 0}.warn{background:#fef3c7;color:#92400e}.no{background:#fee2e2;color:#991b1b}.ok{background:#d1fae5;color:#065f46}code{font-size:11px;color:#6b7280}</style>';
echo '</head><body><h1>🔬 Varredura WhatsApp — casos residuais</h1>';

// 1. Telefone formato @lid bruto (>15 dígitos numéricos, sem hífen, sem 55 prefix válido)
echo '<h2>1. Conversas com telefone em formato @lid bruto</h2>';
$st = $pdo->query("SELECT c.id, c.telefone, c.chat_lid, c.client_id, c.canal, c.nome_contato, c.status,
                          (SELECT COUNT(*) FROM zapi_mensagens m WHERE m.conversa_id = c.id) as msgs,
                          (SELECT MAX(created_at) FROM zapi_mensagens m WHERE m.conversa_id = c.id) as ultima
                   FROM zapi_conversas c
                   WHERE COALESCE(c.eh_grupo,0)=0
                     AND (LENGTH(REGEXP_REPLACE(c.telefone, '[^0-9]', '')) > 14
                          OR c.telefone LIKE '%@lid%'
                          OR c.telefone NOT REGEXP '^[+0-9 ()-]+$')
                   ORDER BY ultima DESC LIMIT 100");
$casos1 = $st->fetchAll();
echo '<div class="box ' . (count($casos1) > 0 ? 'warn' : 'ok') . '">' . count($casos1) . ' conversas com telefone em formato anômalo (provável @lid bruto)</div>';
if (!empty($casos1)) {
    echo '<table><thead><tr><th>Conv</th><th>Telefone</th><th>chat_lid</th><th>Nome contato</th><th>client_id</th><th>Canal</th><th>Msgs</th><th>Última</th></tr></thead><tbody>';
    foreach ($casos1 as $c) {
        echo '<tr><td>' . $c['id'] . '</td><td><code>' . htmlspecialchars($c['telefone']) . '</code></td><td><code>' . htmlspecialchars($c['chat_lid'] ?: '-') . '</code></td><td>' . htmlspecialchars($c['nome_contato'] ?: '-') . '</td><td>' . ($c['client_id'] ?: '-') . '</td><td>' . htmlspecialchars($c['canal']) . '</td><td>' . $c['msgs'] . '</td><td>' . ($c['ultima'] ?: '-') . '</td></tr>';
    }
    echo '</tbody></table>';
}

// 2. Conv sem client_id (não vinculada)
echo '<h2>2. Conversas SEM client_id (não vinculadas a cliente cadastrado)</h2>';
$st = $pdo->query("SELECT c.id, c.telefone, c.nome_contato, c.canal, c.status,
                          (SELECT COUNT(*) FROM zapi_mensagens m WHERE m.conversa_id = c.id) as msgs,
                          (SELECT MAX(created_at) FROM zapi_mensagens m WHERE m.conversa_id = c.id) as ultima
                   FROM zapi_conversas c
                   WHERE c.client_id IS NULL
                     AND COALESCE(c.eh_grupo,0)=0
                     AND (SELECT COUNT(*) FROM zapi_mensagens m WHERE m.conversa_id = c.id) >= 2
                   ORDER BY ultima DESC LIMIT 50");
$casos2 = $st->fetchAll();
echo '<div class="box ' . (count($casos2) > 0 ? 'warn' : 'ok') . '">' . count($casos2) . ' conversas com 2+ msgs e sem cliente vinculado (top 50 mais recentes)</div>';
if (!empty($casos2)) {
    echo '<table><thead><tr><th>Conv</th><th>Telefone</th><th>Nome contato</th><th>Canal</th><th>Status</th><th>Msgs</th><th>Última</th></tr></thead><tbody>';
    foreach ($casos2 as $c) {
        echo '<tr><td>' . $c['id'] . '</td><td><code>' . htmlspecialchars($c['telefone']) . '</code></td><td>' . htmlspecialchars($c['nome_contato'] ?: '-') . '</td><td>' . htmlspecialchars($c['canal']) . '</td><td>' . htmlspecialchars($c['status'] ?: '-') . '</td><td>' . $c['msgs'] . '</td><td>' . ($c['ultima'] ?: '-') . '</td></tr>';
    }
    echo '</tbody></table>';
}

// 3. Conv duplicadas residuais
echo '<h2>3. Conversas duplicadas residuais (mesmo client_id + canal)</h2>';
$st = $pdo->query("SELECT client_id, canal, COUNT(*) as qtd, GROUP_CONCAT(id ORDER BY id) as ids
                   FROM zapi_conversas WHERE client_id IS NOT NULL AND COALESCE(eh_grupo,0)=0
                   GROUP BY client_id, canal HAVING qtd > 1");
$casos3 = $st->fetchAll();
echo '<div class="box ' . (count($casos3) > 0 ? 'warn' : 'ok') . '">' . count($casos3) . ' grupos de duplicatas residuais</div>';
if (!empty($casos3)) {
    echo '<table><thead><tr><th>client_id</th><th>Canal</th><th>conv_ids</th><th>Qtd</th></tr></thead><tbody>';
    foreach ($casos3 as $c) echo '<tr><td>' . $c['client_id'] . '</td><td>' . $c['canal'] . '</td><td><code>' . $c['ids'] . '</code></td><td>' . $c['qtd'] . '</td></tr>';
    echo '</tbody></table>';
}

// 4. Mensagens enviadas últimos 7 dias com status vazio
echo '<h2>4. Mensagens enviadas últimos 7 dias COM status vazio (possível falha silenciosa)</h2>';
$st = $pdo->query("SELECT m.id, m.conversa_id, c.telefone, c.nome_contato,
                          LEFT(m.conteudo, 60) as txt, m.zapi_message_id, m.created_at
                   FROM zapi_mensagens m
                   JOIN zapi_conversas c ON c.id = m.conversa_id
                   WHERE m.direcao='enviada'
                     AND (m.status IS NULL OR m.status = '' OR m.status = 'erro')
                     AND m.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                   ORDER BY m.created_at DESC LIMIT 50");
$casos4 = $st->fetchAll();
echo '<div class="box ' . (count($casos4) > 0 ? 'warn' : 'ok') . '">' . count($casos4) . ' mensagens com status vazio nos últimos 7 dias</div>';
if (!empty($casos4)) {
    echo '<table><thead><tr><th>Msg</th><th>Conv</th><th>Telefone</th><th>Nome</th><th>Conteúdo</th><th>msg_id</th><th>Quando</th></tr></thead><tbody>';
    foreach ($casos4 as $m) {
        $msgIdShort = $m['zapi_message_id'] ? mb_substr($m['zapi_message_id'], 0, 12) : '<vazio>';
        echo '<tr><td>' . $m['id'] . '</td><td>' . $m['conversa_id'] . '</td><td><code>' . htmlspecialchars($m['telefone']) . '</code></td><td>' . htmlspecialchars($m['nome_contato'] ?: '-') . '</td><td>' . htmlspecialchars($m['txt']) . '</td><td><code>' . $msgIdShort . '</code></td><td>' . $m['created_at'] . '</td></tr>';
    }
    echo '</tbody></table>';
}

echo '<hr><h2>📊 Resumo</h2>';
echo '<ul>';
echo '<li><strong>' . count($casos1) . '</strong> conversas com telefone formato @lid bruto (próximas a virar bug)</li>';
echo '<li><strong>' . count($casos2) . '</strong> conversas órfãs (sem client_id, mas com 2+ msgs)</li>';
echo '<li><strong>' . count($casos3) . '</strong> grupos de duplicatas residuais</li>';
echo '<li><strong>' . count($casos4) . '</strong> mensagens enviadas com status vazio nos últimos 7 dias</li>';
echo '</ul>';
echo '</body></html>';
