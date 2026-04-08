<?php
/**
 * Layout Start — inclui header + sidebar + abre main content
 *
 * Variáveis disponíveis (definir antes do require):
 *   $pageTitle  — título da página (obrigatório)
 *   $extraCss   — CSS inline adicional (opcional)
 */

require_once APP_ROOT . '/templates/header.php';
require_once APP_ROOT . '/templates/sidebar.php';
?>

<div class="app-layout">
    <main class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <button class="btn-sidebar-toggle" id="sidebarToggle">☰</button>
                <h1 class="topbar-title"><?= e($pageTitle ?? 'Painel') ?></h1>
            </div>
            <div class="topbar-right">
                <?php $unreadCount = count_unread_notifications(); ?>
                <div class="notif-wrapper" id="notifWrapper">
                    <button class="notif-bell" id="notifBell" title="Notificações">
                        🔔
                        <?php if ($unreadCount > 0): ?>
                            <span class="notif-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="notif-dropdown" id="notifDropdown">
                        <div class="notif-dropdown-header">
                            <strong>Notificações</strong>
                            <?php if ($unreadCount > 0): ?>
                                <a href="<?= url('modules/notificacoes/api.php?action=read_all') ?>" class="notif-mark-all">Marcar todas como lidas</a>
                            <?php endif; ?>
                        </div>
                        <div class="notif-dropdown-body">
                            <?php
                            $recentNotifs = get_notifications(8);
                            if (empty($recentNotifs)):
                            ?>
                                <div class="notif-empty">Nenhuma notificação</div>
                            <?php else: ?>
                                <?php foreach ($recentNotifs as $n):
                                    $typeIcons = array('info' => '💬', 'alerta' => '⚠️', 'sucesso' => '✅', 'pendencia' => '📋', 'urgencia' => '🔴');
                                    $nIcon = $n['icon'] ? $n['icon'] : (isset($typeIcons[$n['type']]) ? $typeIcons[$n['type']] : '💬');
                                    $nClass = $n['is_read'] ? 'notif-item read' : 'notif-item';
                                    $diff = time() - strtotime($n['created_at']);
                                    if ($diff < 60) $ago = 'agora';
                                    elseif ($diff < 3600) $ago = floor($diff/60) . 'min';
                                    elseif ($diff < 86400) $ago = floor($diff/3600) . 'h';
                                    else $ago = floor($diff/86400) . 'd';
                                ?>
                                <a href="<?= $n['link'] ? e($n['link']) . (strpos($n['link'],'?') !== false ? '&' : '?') . 'notif_id=' . $n['id'] : url('modules/notificacoes/?read=' . $n['id']) ?>" class="<?= $nClass ?>">
                                    <span class="notif-icon"><?= $nIcon ?></span>
                                    <div class="notif-content">
                                        <div class="notif-title"><?= e($n['title']) ?></div>
                                        <?php if ($n['message']): ?>
                                            <div class="notif-msg"><?= e(mb_substr($n['message'], 0, 60, 'UTF-8')) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <span class="notif-time"><?= $ago ?></span>
                                </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="notif-dropdown-footer">
                            <a href="<?= url('modules/notificacoes/') ?>">Ver todas</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="page-content">
            <?= flash_html() ?>
<?php
// Banner de prazos urgentes (próximos 3 dias) — visível em todas as páginas
try {
    $__userId = current_user_id();
    $__role = current_user_role();
    $__prazosUrgentes = array();
    if (in_array($__role, array('admin','gestao','operacional'))) {
        $__stmtPz = db()->prepare(
            "SELECT p.id, p.descricao_acao, p.prazo_fatal, p.numero_processo, p.case_id,
                    cs.title as case_title, cl.name as client_name
             FROM prazos_processuais p
             LEFT JOIN cases cs ON cs.id = p.case_id
             LEFT JOIN clients cl ON cl.id = p.client_id
             WHERE p.concluido = 0 AND p.prazo_fatal BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
             ORDER BY p.prazo_fatal ASC LIMIT 10"
        );
        $__stmtPz->execute();
        $__prazosUrgentes = $__stmtPz->fetchAll();
    }
} catch (Exception $e) { $__prazosUrgentes = array(); }
if (!empty($__prazosUrgentes)):
?>
<div style="background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;border-radius:10px;padding:.6rem 1rem;margin-bottom:.75rem;font-size:.78rem;">
    <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.3rem;">
        <span style="font-size:1rem;">🚨</span>
        <strong><?= count($__prazosUrgentes) ?> prazo(s) nos próximos 3 dias!</strong>
        <a href="<?= url('modules/prazos/') ?>" style="color:#fecaca;margin-left:auto;font-size:.7rem;text-decoration:underline;">Ver todos →</a>
    </div>
    <?php foreach ($__prazosUrgentes as $__pz):
        $__diasPz = (int)((strtotime($__pz['prazo_fatal']) - strtotime(date('Y-m-d'))) / 86400);
        $__urgLabel = $__diasPz <= 0 ? '🔴 HOJE' : ($__diasPz === 1 ? '🟡 AMANHÃ' : '⚠️ ' . $__diasPz . 'd');
    ?>
    <div style="display:flex;align-items:center;gap:.5rem;padding:.2rem 0;border-top:1px solid rgba(255,255,255,.15);">
        <span style="font-weight:700;min-width:70px;"><?= $__urgLabel ?></span>
        <span style="font-weight:600;"><?= e($__pz['descricao_acao']) ?></span>
        <?php if ($__pz['case_id']): ?><a href="<?= url('modules/operacional/caso_ver.php?id=' . $__pz['case_id']) ?>" style="color:#fecaca;text-decoration:none;">— <?= e($__pz['case_title'] ?: $__pz['numero_processo'] ?: '') ?></a><?php endif; ?>
        <?php if ($__pz['client_name']): ?><span style="opacity:.7;">(<?= e($__pz['client_name']) ?>)</span><?php endif; ?>
        <span style="margin-left:auto;font-family:monospace;font-size:.72rem;opacity:.8;"><?= date('d/m', strtotime($__pz['prazo_fatal'])) ?></span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
