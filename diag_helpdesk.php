<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Diag helpdesk</title>';
echo '<style>body{font-family:monospace;padding:1.5rem;background:#0a0a0a;color:#e5e7eb;line-height:1.5;}h2{color:#60a5fa;}table{border-collapse:collapse;width:100%;font-size:.8rem;margin-top:.5rem;}td,th{padding:.35rem .6rem;border-bottom:1px solid #374151;text-align:left;}th{background:#1f2937;color:#fbbf24;}.warn{color:#fbbf24;}.err{color:#ef4444;}.muted{color:#6b7280;}</style></head><body>';

echo '<h2>1. Ultimos 20 tickets (por created_at DESC)</h2>';
try {
    $rows = $pdo->query("SELECT id, title, status, priority, origem, category, requester_id, created_at FROM tickets ORDER BY created_at DESC LIMIT 20")->fetchAll();
    echo '<table><tr><th>ID</th><th>Titulo</th><th>Status</th><th>Prio</th><th>Origem</th><th>Categoria</th><th>Req.</th><th>Criado</th></tr>';
    foreach ($rows as $r) {
        $statusClass = in_array($r['status'], array('aberto','em_andamento','aguardando')) ? '' : 'muted';
        echo '<tr><td>' . $r['id'] . '</td><td>' . htmlspecialchars($r['title']) . '</td>';
        echo '<td class="' . $statusClass . '">' . htmlspecialchars($r['status'] ?? '(NULL)') . '</td>';
        echo '<td>' . htmlspecialchars($r['priority'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($r['origem'] ?? '(NULL)') . '</td>';
        echo '<td>' . htmlspecialchars($r['category'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($r['requester_id'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($r['created_at'] ?? '') . '</td></tr>';
    }
    echo '</table>';
} catch (Exception $e) { echo '<p class="err">ERRO: ' . htmlspecialchars($e->getMessage()) . '</p>'; }

echo '<h2>2. Contagem por status</h2>';
try {
    $rows = $pdo->query("SELECT status, COUNT(*) AS n FROM tickets GROUP BY status ORDER BY n DESC")->fetchAll();
    echo '<table><tr><th>Status</th><th>N</th></tr>';
    foreach ($rows as $r) echo '<tr><td>' . htmlspecialchars($r['status'] ?? '(NULL)') . '</td><td>' . $r['n'] . '</td></tr>';
    echo '</table>';
} catch (Exception $e) { echo '<p class="err">ERRO: ' . htmlspecialchars($e->getMessage()) . '</p>'; }

echo '<h2>3. Query EXATA da listagem (aba equipe, sem filtro)</h2>';
try {
    $sql = "SELECT t.*, u.name as requester_name,
            GROUP_CONCAT(u2.name SEPARATOR ', ') as assignees,
            (SELECT COUNT(*) FROM ticket_messages WHERE ticket_id = t.id) as msg_count
            FROM tickets t
            LEFT JOIN users u ON u.id = t.requester_id
            LEFT JOIN ticket_assignees ta ON ta.ticket_id = t.id
            LEFT JOIN users u2 ON u2.id = ta.user_id
            WHERE (t.origem IS NULL OR t.origem != 'salavip') AND t.status NOT IN ('resolvido','cancelado')
            GROUP BY t.id
            ORDER BY COALESCE(t.pinned, 0) DESC, FIELD(t.status, 'aberto','em_andamento','aguardando','resolvido','cancelado'), t.created_at DESC
            LIMIT 100";
    $rows = $pdo->query($sql)->fetchAll();
    echo '<p>Resultou em <strong>' . count($rows) . '</strong> tickets.</p>';
    echo '<table><tr><th>ID</th><th>Titulo</th><th>Status</th><th>Origem</th><th>Criado</th></tr>';
    foreach (array_slice($rows, 0, 15) as $r) {
        echo '<tr><td>' . $r['id'] . '</td><td>' . htmlspecialchars($r['title']) . '</td><td>' . htmlspecialchars($r['status']) . '</td>';
        echo '<td>' . htmlspecialchars($r['origem'] ?? '(NULL)') . '</td><td>' . htmlspecialchars($r['created_at']) . '</td></tr>';
    }
    echo '</table>';
} catch (Exception $e) { echo '<p class="err">ERRO NA QUERY: ' . htmlspecialchars($e->getMessage()) . '</p>'; }

echo '<h2>4. Colunas da tabela tickets</h2>';
try {
    $cols = $pdo->query("SHOW COLUMNS FROM tickets")->fetchAll();
    echo '<table><tr><th>Campo</th><th>Tipo</th><th>Null</th><th>Default</th></tr>';
    foreach ($cols as $c) echo '<tr><td>' . htmlspecialchars($c['Field']) . '</td><td>' . htmlspecialchars($c['Type']) . '</td><td>' . htmlspecialchars($c['Null']) . '</td><td>' . htmlspecialchars($c['Default'] ?? '') . '</td></tr>';
    echo '</table>';
} catch (Exception $e) { echo '<p class="err">ERRO: ' . htmlspecialchars($e->getMessage()) . '</p>'; }

echo '</body></html>';
