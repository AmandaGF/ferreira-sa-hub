<?php
/**
 * Ferreira & Sá Hub — Central de Notificações
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Notificações';
$pdo = db();
$userId = current_user_id();

// Marcar como lida se veio via ?read=ID
$readId = (int)($_GET['read'] ?? 0);
if ($readId) {
    $pdo->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?')
        ->execute(array($readId, $userId));
}

// Filtro
$filtro = isset($_GET['filtro']) ? $_GET['filtro'] : 'todas';
$where = 'WHERE user_id = ?';
if ($filtro === 'nao_lidas') $where .= ' AND is_read = 0';
if ($filtro === 'lidas') $where .= ' AND is_read = 1';

// Buscar notificações
$notifs = $pdo->prepare(
    "SELECT * FROM notifications $where ORDER BY created_at DESC LIMIT 50"
);
$notifs->execute(array($userId));
$notifs = $notifs->fetchAll();

// Contadores
$totalNaoLidas = (int)$pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0')->execute(array($userId));
$totalNaoLidas = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = $userId AND is_read = 0")->fetchColumn();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.notif-filters { display: flex; gap: .35rem; margin-bottom: 1.25rem; align-items: center; flex-wrap: wrap; }
.notif-filter-btn {
    padding: .4rem .85rem; font-size: .78rem; font-weight: 600;
    border: 1.5px solid var(--border); border-radius: 100px;
    background: var(--bg-card); color: var(--text-muted); cursor: pointer;
    text-decoration: none; transition: all var(--transition);
}
.notif-filter-btn:hover { border-color: var(--petrol-300); color: var(--petrol-500); }
.notif-filter-btn.active { background: var(--petrol-900); color: #fff; border-color: var(--petrol-900); }

.notif-list { display: flex; flex-direction: column; gap: .5rem; }
.notif-row {
    background: var(--bg-card); border-radius: var(--radius-lg);
    border: 1px solid var(--border); padding: 1rem 1.25rem;
    display: flex; align-items: flex-start; gap: 1rem;
    transition: all var(--transition);
    text-decoration: none; color: var(--text);
}
.notif-row.unread { border-left: 4px solid var(--rose); background: rgba(215,171,144,.03); }
.notif-row:hover { box-shadow: var(--shadow-sm); }
.notif-row-icon { font-size: 1.4rem; flex-shrink: 0; margin-top: .1rem; }
.notif-row-body { flex: 1; min-width: 0; }
.notif-row-title { font-size: .88rem; font-weight: 700; color: var(--petrol-900); margin-bottom: .15rem; }
.notif-row-msg { font-size: .78rem; color: var(--text-muted); line-height: 1.4; }
.notif-row-time { font-size: .7rem; color: var(--text-muted); margin-top: .35rem; }
.notif-row-actions { flex-shrink: 0; display: flex; gap: .35rem; align-items: center; }

.notif-empty {
    text-align: center; padding: 3rem 1rem; color: var(--text-muted);
}
.notif-empty .icon { font-size: 2.5rem; margin-bottom: .75rem; }
</style>

<!-- Filtros -->
<div class="notif-filters">
    <a href="?filtro=todas" class="notif-filter-btn <?= $filtro === 'todas' ? 'active' : '' ?>">Todas</a>
    <a href="?filtro=nao_lidas" class="notif-filter-btn <?= $filtro === 'nao_lidas' ? 'active' : '' ?>">
        Não lidas <?php if ($totalNaoLidas > 0): ?><span style="background:var(--rose);color:#fff;padding:.1rem .4rem;border-radius:100px;font-size:.65rem;margin-left:.25rem;"><?= $totalNaoLidas ?></span><?php endif; ?>
    </a>
    <a href="?filtro=lidas" class="notif-filter-btn <?= $filtro === 'lidas' ? 'active' : '' ?>">Lidas</a>

    <?php if ($totalNaoLidas > 0): ?>
    <a href="<?= module_url('notificacoes', 'api.php?action=read_all') ?>" class="notif-filter-btn" style="margin-left:auto;border-color:var(--rose);color:var(--rose);">
        Marcar todas como lidas
    </a>
    <?php endif; ?>
</div>

<!-- Lista -->
<div class="notif-list">
    <?php if (empty($notifs)): ?>
        <div class="notif-empty">
            <div class="icon">🔔</div>
            <h3>Nenhuma notificação</h3>
            <p><?= $filtro === 'nao_lidas' ? 'Todas as notificações foram lidas.' : 'Você ainda não recebeu notificações.' ?></p>
        </div>
    <?php else: ?>
        <?php foreach ($notifs as $n):
            $typeIcons = array('info' => '💬', 'alerta' => '⚠️', 'sucesso' => '✅', 'pendencia' => '📋', 'urgencia' => '🔴');
            $nIcon = $n['icon'] ? $n['icon'] : (isset($typeIcons[$n['type']]) ? $typeIcons[$n['type']] : '💬');
            $rowClass = $n['is_read'] ? 'notif-row' : 'notif-row unread';

            $diff = time() - strtotime($n['created_at']);
            if ($diff < 60) $ago = 'Agora mesmo';
            elseif ($diff < 3600) $ago = floor($diff/60) . ' min atrás';
            elseif ($diff < 86400) $ago = floor($diff/3600) . 'h atrás';
            elseif ($diff < 172800) $ago = 'Ontem';
            else $ago = date('d/m/Y H:i', strtotime($n['created_at']));
        ?>
        <div class="<?= $rowClass ?>">
            <div class="notif-row-icon"><?= $nIcon ?></div>
            <div class="notif-row-body">
                <div class="notif-row-title"><?= e($n['title']) ?></div>
                <?php if ($n['message']): ?>
                    <div class="notif-row-msg"><?= e($n['message']) ?></div>
                <?php endif; ?>
                <div class="notif-row-time"><?= $ago ?></div>
            </div>
            <div class="notif-row-actions">
                <?php if ($n['link']): ?>
                    <a href="<?= e($n['link']) ?>" class="btn btn-primary btn-sm" style="font-size:.72rem;">Ver</a>
                <?php endif; ?>
                <?php if (!$n['is_read']): ?>
                    <a href="?read=<?= $n['id'] ?>&filtro=<?= $filtro ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;">Lida</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
