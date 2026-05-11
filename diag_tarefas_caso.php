<?php
/**
 * Diag — investiga tarefas de um caso especifico que nao aparecem na pasta.
 * Mostra: dados do caso, tarefas vinculadas via case_id, e tarefas que MENCIONAM
 * o nome do caso/cliente no titulo (potenciais orfas vinculadas errado).
 *
 * Uso: https://ferreiraesa.com.br/conecta/diag_tarefas_caso.php?key=fsa-hub-deploy-2026&q=Luiz+Eduardo+Ifood
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/core/database.php';
$pdo = db();

$q = trim($_GET['q'] ?? '');

echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Diag tarefas</title>';
echo '<style>body{font-family:monospace;padding:1.5rem;background:#0a0a0a;color:#e5e7eb;line-height:1.5;max-width:1200px;margin:0 auto;}h1{color:#fbbf24;}h2{color:#60a5fa;border-bottom:1px solid #374151;padding-bottom:.3rem;margin-top:1.5rem;}table{border-collapse:collapse;margin-top:.5rem;width:100%;font-size:.82rem;}td,th{padding:.4rem .6rem;border-bottom:1px solid #374151;text-align:left;vertical-align:top;}th{background:#1f2937;color:#fbbf24;}.ok{color:#10b981;}.warn{color:#fbbf24;}.err{color:#ef4444;}.muted{color:#6b7280;}input{padding:.5rem;background:#1f2937;border:1px solid #374151;color:#e5e7eb;border-radius:6px;font-family:inherit;width:300px;}button{padding:.5rem 1rem;background:#3b82f6;border:none;color:#fff;border-radius:6px;cursor:pointer;font-family:inherit;}</style></head><body>';
echo '<h1>🔍 Diag de tarefas do caso</h1>';
echo '<form method="GET"><input type="hidden" name="key" value="fsa-hub-deploy-2026">';
echo '<input type="text" name="q" placeholder="nome cliente ou titulo do caso" value="' . htmlspecialchars($q) . '"> <button>Buscar</button></form>';
if (!$q) { echo '<p class="muted">Informe um pedaço do nome do cliente ou título.</p></body></html>'; exit; }

// Busca casos que batem
$st = $pdo->prepare("SELECT cs.id, cs.title, cs.client_id, cs.case_number, cs.responsible_user_id, cs.status, c.name AS client_name, u.name AS resp_name
                     FROM cases cs LEFT JOIN clients c ON c.id = cs.client_id LEFT JOIN users u ON u.id = cs.responsible_user_id
                     WHERE cs.title LIKE ? OR c.name LIKE ?
                     ORDER BY cs.updated_at DESC LIMIT 10");
$st->execute(array('%' . $q . '%', '%' . $q . '%'));
$casos = $st->fetchAll();

if (!$casos) { echo '<p class="err">Nenhum caso encontrado.</p></body></html>'; exit; }

echo '<h2>1. Casos encontrados (' . count($casos) . ')</h2>';
echo '<table><tr><th>ID</th><th>Título</th><th>Cliente</th><th>Status</th><th>Responsável</th><th>CNJ</th></tr>';
foreach ($casos as $cs) {
    echo '<tr>';
    echo '<td><strong>' . $cs['id'] . '</strong></td>';
    echo '<td>' . htmlspecialchars($cs['title']) . '</td>';
    echo '<td>' . htmlspecialchars($cs['client_name'] ?? '') . '</td>';
    echo '<td>' . htmlspecialchars($cs['status']) . '</td>';
    echo '<td>' . htmlspecialchars($cs['resp_name'] ?? '') . '</td>';
    echo '<td style="font-size:.72rem;">' . htmlspecialchars($cs['case_number'] ?? '—') . '</td>';
    echo '</tr>';
}
echo '</table>';

// Pra cada caso, mostra tarefas vinculadas
foreach ($casos as $cs) {
    echo '<h2>2. Tarefas vinculadas ao caso #' . $cs['id'] . '</h2>';
    $st2 = $pdo->prepare("SELECT t.*, u.name AS assigned_name
                          FROM case_tasks t LEFT JOIN users u ON u.id = t.assigned_to
                          WHERE t.case_id = ? ORDER BY t.created_at DESC");
    $st2->execute(array($cs['id']));
    $tasks = $st2->fetchAll();
    if (!$tasks) {
        echo '<p class="muted">Nenhuma tarefa vinculada via case_id.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Título</th><th>Tipo</th><th>Status</th><th>Resp.</th><th>Due</th><th>Criada</th></tr>';
        foreach ($tasks as $t) {
            $tipoClass = empty($t['tipo']) ? 'warn' : 'ok';
            echo '<tr>';
            echo '<td>' . $t['id'] . '</td>';
            echo '<td>' . htmlspecialchars($t['title']) . '</td>';
            echo '<td class="' . $tipoClass . '">' . htmlspecialchars($t['tipo'] ?: '(vazio)') . '</td>';
            echo '<td>' . htmlspecialchars($t['status']) . '</td>';
            echo '<td>' . htmlspecialchars($t['assigned_name'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($t['due_date'] ?? '—') . '</td>';
            echo '<td style="font-size:.72rem;">' . htmlspecialchars($t['created_at']) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
}

// Tarefas órfãs que mencionam o termo no título
echo '<h2>3. Tarefas que mencionam "' . htmlspecialchars($q) . '" no título (potenciais órfãs)</h2>';
$st3 = $pdo->prepare("SELECT t.id, t.title, t.tipo, t.status, t.case_id, t.assigned_to, t.due_date, t.created_at, u.name AS assigned_name
                      FROM case_tasks t LEFT JOIN users u ON u.id = t.assigned_to
                      WHERE t.title LIKE ?
                      ORDER BY t.created_at DESC LIMIT 30");
$st3->execute(array('%' . $q . '%'));
$orfas = $st3->fetchAll();
if (!$orfas) { echo '<p class="muted">Nenhuma tarefa com esse termo no título.</p>'; }
else {
    echo '<table><tr><th>ID</th><th>Título</th><th>Tipo</th><th>Status</th><th>case_id</th><th>Resp.</th><th>Due</th></tr>';
    foreach ($orfas as $t) {
        $caseClass = empty($t['case_id']) ? 'err' : 'ok';
        echo '<tr>';
        echo '<td>' . $t['id'] . '</td>';
        echo '<td>' . htmlspecialchars($t['title']) . '</td>';
        echo '<td>' . htmlspecialchars($t['tipo'] ?: '(vazio)') . '</td>';
        echo '<td>' . htmlspecialchars($t['status']) . '</td>';
        echo '<td class="' . $caseClass . '">' . ($t['case_id'] ? '#' . $t['case_id'] : '(VAZIO!)') . '</td>';
        echo '<td>' . htmlspecialchars($t['assigned_name'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($t['due_date'] ?? '—') . '</td>';
        echo '</tr>';
    }
    echo '</table>';
}

echo '</body></html>';
