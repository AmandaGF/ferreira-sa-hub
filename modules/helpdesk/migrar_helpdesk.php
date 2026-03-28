<?php
/**
 * Migrar dados do Helpdesk antigo para o Hub
 * Importa: usuários e chamados (com responsáveis e mensagens)
 */

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(300);
echo "=== Migracao Helpdesk ===\n\n";

// Banco novo (Hub)
$hub = new PDO('mysql:host=localhost;dbname=ferre3151357_conecta;charset=utf8mb4',
    'ferre3151357_admin', 'Ar192114@',
    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true));

// Banco antigo (Helpdesk)
$old = new PDO('mysql:host=localhost;dbname=ferre3151357_helpdesk;charset=utf8',
    'ferre3151357_admin_helpdesk', 'Ar192114@',
    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true));

// ═══════════════════════════════════════════════════════
// 1. Descobrir estrutura
// ═══════════════════════════════════════════════════════
echo "--- Tabelas no Helpdesk antigo ---\n";
$tables = $old->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo implode(', ', $tables) . "\n\n";

// Mostrar colunas de cada tabela
foreach ($tables as $t) {
    $cols = $old->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN);
    echo "$t: " . implode(', ', $cols) . "\n";
}
echo "\n";

// ═══════════════════════════════════════════════════════
// 2. Migrar usuários
// ═══════════════════════════════════════════════════════
echo "--- Migrando usuarios ---\n";
$usuarios = $old->query("SELECT * FROM usuarios")->fetchAll(PDO::FETCH_ASSOC);
echo "Encontrados: " . count($usuarios) . "\n";

$userMap = array(); // old_id => new_id
$importedUsers = 0;

foreach ($usuarios as $u) {
    $email = $u['login'] ?? $u['email'] ?? '';
    if (empty($email)) continue;

    // Verificar se já existe no Hub
    $check = $hub->prepare("SELECT id FROM users WHERE email = ?");
    $check->execute(array($email));
    $existing = $check->fetch();

    if ($existing) {
        $userMap[$u['id']] = $existing['id'];
        echo "  Existe: $email (Hub ID: {$existing['id']})\n";
    } else {
        // Mapear perfil
        $role = 'colaborador';
        $perfil = strtolower($u['perfil'] ?? '');
        if (strpos($perfil, 'admin') !== false || strpos($perfil, 'head') !== false) {
            $role = 'admin';
        } elseif (strpos($perfil, 'gest') !== false) {
            $role = 'gestao';
        }

        // Criar hash da senha (usar a mesma se for bcrypt, senão criar nova)
        $passHash = $u['senha'] ?? '';
        if (strpos($passHash, '$2y$') !== 0 && strpos($passHash, '$2a$') !== 0) {
            $passHash = password_hash('Hub@2026', PASSWORD_DEFAULT);
        }

        $ins = $hub->prepare(
            "INSERT INTO users (name, email, password_hash, role, is_active, setor) VALUES (?, ?, ?, ?, 1, ?)"
        );
        $ins->execute(array(
            $u['nome'] ?? $email,
            $email,
            $passHash,
            $role,
            $u['setor'] ?? null
        ));
        $newId = (int)$hub->lastInsertId();
        $userMap[$u['id']] = $newId;
        echo "  Criado: {$u['nome']} ($email) -> Hub ID: $newId (role: $role)\n";
        $importedUsers++;
    }
}
echo "Usuarios importados: $importedUsers\n\n";

// ═══════════════════════════════════════════════════════
// 3. Migrar chamados
// ═══════════════════════════════════════════════════════
echo "--- Migrando chamados ---\n";
$chamados = $old->query("SELECT * FROM chamados ORDER BY data_criacao ASC")->fetchAll(PDO::FETCH_ASSOC);
echo "Encontrados: " . count($chamados) . "\n";

$ticketMap = array(); // old_id => new_id
$importedTickets = 0;

foreach ($chamados as $ch) {
    // Verificar duplicata pelo título + data
    $check = $hub->prepare("SELECT id FROM tickets WHERE title = ? AND created_at = ?");
    $created = $ch['data_criacao'] ?? $ch['created_at'] ?? date('Y-m-d H:i:s');
    $check->execute(array($ch['titulo'] ?? $ch['title'] ?? 'Sem título', $created));
    if ($check->fetch()) {
        echo "  Skip: " . ($ch['titulo'] ?? $ch['title'] ?? '?') . " (ja existe)\n";
        continue;
    }

    // Mapear prioridade
    $priority = 'normal';
    $prio = strtolower($ch['prioridade'] ?? $ch['priority'] ?? '');
    if (strpos($prio, 'urgent') !== false || strpos($prio, 'alta') !== false) $priority = 'urgente';
    elseif (strpos($prio, 'baix') !== false) $priority = 'baixa';

    // Mapear status
    $status = 'aberto';
    $st = strtolower($ch['status'] ?? '');
    if (strpos($st, 'resolv') !== false || strpos($st, 'conclu') !== false) $status = 'resolvido';
    elseif (strpos($st, 'andamento') !== false || strpos($st, 'progress') !== false) $status = 'em_andamento';
    elseif (strpos($st, 'aguard') !== false || strpos($st, 'pend') !== false) $status = 'aguardando';
    elseif (strpos($st, 'cancel') !== false) $status = 'cancelado';

    $requesterId = isset($userMap[$ch['solicitante_id']]) ? $userMap[$ch['solicitante_id']] : (isset($userMap[$ch['usuario_id']]) ? $userMap[$ch['usuario_id']] : 1);

    $ins = $hub->prepare(
        "INSERT INTO tickets (title, description, category, priority, status, requester_id, client_name, case_number, due_date, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $ins->execute(array(
        $ch['titulo'] ?? $ch['title'] ?? 'Sem título',
        $ch['descricao'] ?? $ch['description'] ?? null,
        $ch['categoria'] ?? $ch['category'] ?? null,
        $priority,
        $status,
        $requesterId,
        $ch['nome_cliente'] ?? $ch['client_name'] ?? null,
        $ch['numero_processo'] ?? $ch['case_number'] ?? null,
        $ch['prazo'] ?? $ch['due_date'] ?? null,
        $created,
    ));

    $newTicketId = (int)$hub->lastInsertId();
    $ticketMap[$ch['id']] = $newTicketId;
    echo "  OK: #" . $ch['id'] . " -> Hub #$newTicketId (" . ($ch['titulo'] ?? $ch['title'] ?? '?') . ")\n";
    $importedTickets++;
}
echo "Chamados importados: $importedTickets\n\n";

// ═══════════════════════════════════════════════════════
// 4. Migrar responsáveis dos chamados
// ═══════════════════════════════════════════════════════
echo "--- Migrando responsaveis ---\n";
$importedAssignees = 0;
if (in_array('chamados_responsaveis', $tables)) {
    $resps = $old->query("SELECT * FROM chamados_responsaveis")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($resps as $r) {
        $chamadoId = $r['chamado_id'] ?? $r['ticket_id'] ?? null;
        $usuarioId = $r['usuario_id'] ?? $r['user_id'] ?? null;
        if (!$chamadoId || !$usuarioId) continue;
        if (!isset($ticketMap[$chamadoId]) || !isset($userMap[$usuarioId])) continue;

        $hub->prepare("INSERT IGNORE INTO ticket_assignees (ticket_id, user_id) VALUES (?, ?)")
            ->execute(array($ticketMap[$chamadoId], $userMap[$usuarioId]));
        $importedAssignees++;
    }
}
echo "Responsaveis importados: $importedAssignees\n\n";

// ═══════════════════════════════════════════════════════
// 5. Migrar mensagens
// ═══════════════════════════════════════════════════════
echo "--- Migrando mensagens ---\n";
$importedMsgs = 0;
$msgTables = array('mensagens', 'chamados_mensagens', 'ticket_messages', 'messages');
$msgTable = null;
foreach ($msgTables as $mt) {
    if (in_array($mt, $tables)) { $msgTable = $mt; break; }
}

if ($msgTable) {
    $msgs = $old->query("SELECT * FROM `$msgTable` ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    echo "Tabela: $msgTable | Encontradas: " . count($msgs) . "\n";
    foreach ($msgs as $m) {
        $chamadoId = $m['chamado_id'] ?? $m['ticket_id'] ?? null;
        $usuarioId = $m['usuario_id'] ?? $m['user_id'] ?? null;
        $mensagem = $m['mensagem'] ?? $m['message'] ?? $m['texto'] ?? null;
        if (!$chamadoId || !$mensagem) continue;
        if (!isset($ticketMap[$chamadoId])) continue;

        $msgUserId = isset($userMap[$usuarioId]) ? $userMap[$usuarioId] : 1;
        $msgDate = $m['created_at'] ?? $m['data_criacao'] ?? date('Y-m-d H:i:s');

        $hub->prepare("INSERT INTO ticket_messages (ticket_id, user_id, message, created_at) VALUES (?, ?, ?, ?)")
            ->execute(array($ticketMap[$chamadoId], $msgUserId, $mensagem, $msgDate));
        $importedMsgs++;
    }
} else {
    echo "Nenhuma tabela de mensagens encontrada\n";
}
echo "Mensagens importadas: $importedMsgs\n\n";

echo "=== MIGRACAO HELPDESK CONCLUIDA ===\n";
echo "Usuarios: $importedUsers\n";
echo "Chamados: $importedTickets\n";
echo "Responsaveis: $importedAssignees\n";
echo "Mensagens: $importedMsgs\n";
