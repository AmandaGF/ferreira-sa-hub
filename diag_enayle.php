<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
$pdo = db();

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Diag Enayle</title>';
echo '<style>body{font-family:system-ui;padding:20px;max-width:1300px;margin:0 auto}table{width:100%;border-collapse:collapse;margin:.5rem 0}th,td{padding:6px 8px;border-bottom:1px solid #ddd;font-size:12px;text-align:left;vertical-align:top}th{background:#052228;color:#fff}h2{color:#052228;border-bottom:2px solid #B87333;padding-bottom:6px;margin-top:2rem}pre{background:#f8fafc;padding:8px;border-radius:6px;font-size:11px;max-height:200px;overflow:auto}</style></head><body>';
echo '<h1>🔍 Caso Enayle Garcia Fontes — mensagens "perdidas"</h1>';

// 1. Todas as conversas que tenham 99839644, 132508599484417 ou client_id=674
echo '<h2>1. Todas as conversas relacionadas (telefone, lid, client_id=674)</h2>';
$st = $pdo->query("SELECT * FROM zapi_conversas
                   WHERE telefone LIKE '%99839644%'
                      OR chat_lid LIKE '%132508599484417%'
                      OR client_id = 674
                   ORDER BY id ASC");
$convs = $st->fetchAll();
echo '<table><thead><tr><th>ID</th><th>Telefone</th><th>chat_lid</th><th>client_id</th><th>Canal</th><th>Status</th></tr></thead><tbody>';
foreach ($convs as $c) echo '<tr><td>' . $c['id'] . '</td><td>' . htmlspecialchars($c['telefone'] ?? '') . '</td><td>' . htmlspecialchars($c['chat_lid'] ?? '-') . '</td><td>' . ($c['client_id'] ?? '-') . '</td><td>' . ($c['canal'] ?? '-') . '</td><td>' . htmlspecialchars($c['status'] ?? '-') . '</td></tr>';
echo '</tbody></table>';

// 2. Em audit_log: merges/limpezas envolvendo conv 660 ou client 674
echo '<h2>2. audit_log envolvendo conv 660 ou client 674</h2>';
try {
    $st = $pdo->prepare("SELECT * FROM audit_log WHERE
                         (entity_type IN ('zapi_conversas','conv','conversa') AND entity_id = 660)
                         OR (entity_type IN ('clients','client') AND entity_id = 674)
                         OR description LIKE '%99839644%'
                         OR description LIKE '%132508599484417%'
                         OR description LIKE '%conv%660%'
                         OR action LIKE '%merge%'
                         ORDER BY id DESC LIMIT 30");
    $st->execute();
    $logs = $st->fetchAll();
} catch (Exception $e) {
    echo '<p>Erro audit: ' . htmlspecialchars($e->getMessage()) . '</p>';
    $logs = array();
}
if (empty($logs)) {
    echo '<p>Nenhum registro de auditoria.</p>';
} else {
    echo '<table><thead><tr><th>ID</th><th>Quando</th><th>Usuário</th><th>Ação</th><th>Entidade</th><th>Detalhes</th></tr></thead><tbody>';
    foreach ($logs as $l) echo '<tr><td>' . $l['id'] . '</td><td>' . ($l['created_at'] ?? '-') . '</td><td>' . ($l['user_id'] ?? '-') . '</td><td>' . htmlspecialchars($l['action'] ?? $l['acao'] ?? '') . '</td><td>' . htmlspecialchars(($l['entity_type'] ?? $l['entidade_tipo'] ?? '') . ' #' . ($l['entity_id'] ?? $l['entidade_id'] ?? '')) . '</td><td>' . htmlspecialchars(mb_substr($l['description'] ?? $l['detalhes'] ?? '', 0, 200)) . '</td></tr>';
    echo '</tbody></table>';
}

// 3. TODAS as mensagens da conv 660 (cronológico) - pra ver onde acontece o gap
echo '<h2>3. Linha do tempo COMPLETA da conv 660</h2>';
try {
    $st = $pdo->prepare("SELECT * FROM zapi_mensagens WHERE conversa_id = 660 ORDER BY id ASC");
    $st->execute();
    $msgs = $st->fetchAll();
} catch (Exception $e) {
    echo '<p>Erro: ' . htmlspecialchars($e->getMessage()) . '</p>';
    $msgs = array();
}
echo '<p>Total: <strong>' . count($msgs) . '</strong> mensagens registradas. Atenção em GAPS de id (saltos grandes).</p>';
echo '<table><thead><tr><th>ID</th><th>Quando</th><th>Direção</th><th>Tipo</th><th>Conteúdo</th><th>Status</th></tr></thead><tbody>';
$prevId = 0;
foreach ($msgs as $m) {
    $gap = $prevId ? ($m['id'] - $prevId) : 0;
    $bg = '';
    if ($gap > 100) $bg = 'background:#fee2e2;';
    elseif ($gap > 20) $bg = 'background:#fef3c7;';
    $quando = $m['criada_em'] ?? $m['created_at'] ?? '?';
    $preview = mb_substr($m['conteudo'] ?? '', 0, 70);
    echo '<tr style="' . $bg . '"><td>' . $m['id'] . ($gap > 20 ? ' <em>(+' . $gap . ')</em>' : '') . '</td><td>' . htmlspecialchars($quando) . '</td><td><strong>' . htmlspecialchars($m['direcao'] ?? '-') . '</strong></td><td>' . htmlspecialchars($m['tipo'] ?? '-') . '</td><td>' . htmlspecialchars($preview) . '</td><td>' . htmlspecialchars($m['status'] ?? '-') . '</td></tr>';
    $prevId = $m['id'];
}
echo '</tbody></table>';

// 4. Mensagens em ALGUMA outra conversa que possa ter o lid dela
echo '<h2>4. Mensagens com remetente igual ao @lid dela em OUTRAS conversas</h2>';
try {
    $st = $pdo->prepare("SELECT m.*, c.telefone AS conv_tel, c.chat_lid AS conv_lid, c.client_id AS conv_cli
                         FROM zapi_mensagens m
                         JOIN zapi_conversas c ON c.id = m.conversa_id
                         WHERE c.id != 660
                           AND (c.chat_lid LIKE '%132508599484417%'
                                OR c.telefone LIKE '%99839644%'
                                OR (c.client_id = 674))
                         ORDER BY m.id ASC LIMIT 100");
    $st->execute();
    $outras = $st->fetchAll();
} catch (Exception $e) {
    echo '<p>Erro: ' . htmlspecialchars($e->getMessage()) . '</p>';
    $outras = array();
}
if (empty($outras)) {
    echo '<p>Nenhuma mensagem em outra conversa relacionada.</p>';
} else {
    echo '<p style="color:#991b1b;font-weight:700;">⚠️ ' . count($outras) . ' mensagens encontradas em outras conversas que podem ser dela!</p>';
    echo '<table><thead><tr><th>msg_id</th><th>conv_id</th><th>Tel conv</th><th>chat_lid conv</th><th>Direção</th><th>Tipo</th><th>Conteúdo</th><th>Quando</th></tr></thead><tbody>';
    foreach ($outras as $m) {
        $quando = $m['criada_em'] ?? $m['created_at'] ?? '?';
        $preview = mb_substr($m['conteudo'] ?? '', 0, 80);
        echo '<tr><td>' . $m['id'] . '</td><td><strong>' . $m['conversa_id'] . '</strong></td><td>' . htmlspecialchars($m['conv_tel'] ?? '') . '</td><td>' . htmlspecialchars($m['conv_lid'] ?? '-') . '</td><td><strong>' . htmlspecialchars($m['direcao'] ?? '-') . '</strong></td><td>' . htmlspecialchars($m['tipo'] ?? '-') . '</td><td>' . htmlspecialchars($preview) . '</td><td>' . htmlspecialchars($quando) . '</td></tr>';
    }
    echo '</tbody></table>';
}

// 5. Logs de webhook do Z-API: alguma rejeição / erro pra esse número/lid?
echo '<h2>5. Procurar em logs de erro recentes (zapi_webhook_log se houver)</h2>';
try {
    $st = $pdo->prepare("SELECT * FROM zapi_webhook_log WHERE payload LIKE '%99839644%' OR payload LIKE '%132508599484417%' ORDER BY id DESC LIMIT 30");
    $st->execute();
    $logs = $st->fetchAll();
    if (empty($logs)) echo '<p>Nada no webhook log.</p>';
    else {
        echo '<table><thead><tr><th>ID</th><th>Quando</th><th>OK?</th><th>Erro</th><th>Payload (resumo)</th></tr></thead><tbody>';
        foreach ($logs as $l) echo '<tr><td>' . $l['id'] . '</td><td>' . ($l['created_at'] ?? '-') . '</td><td>' . ($l['processado'] ?? '-') . '</td><td>' . htmlspecialchars($l['erro'] ?? '-') . '</td><td><code style="font-size:10px">' . htmlspecialchars(mb_substr($l['payload'] ?? '', 0, 200)) . '</code></td></tr>';
        echo '</tbody></table>';
    }
} catch (Exception $e) { echo '<p>Tabela zapi_webhook_log: ' . htmlspecialchars($e->getMessage()) . '</p>'; }

echo '</body></html>';
