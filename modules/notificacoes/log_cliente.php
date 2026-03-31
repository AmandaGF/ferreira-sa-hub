<?php
/**
 * Ferreira & Sá Hub — Log de Notificações ao Cliente
 * Mostra histórico de mensagens geradas para clientes com links de envio
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('gestao')) { flash_set('error', 'Sem permissão.'); redirect(url('modules/dashboard/')); }

$pageTitle = 'Log de Notificações ao Cliente';
$pdo = db();

// Marcar como enviado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $notifId = (int)($_POST['notif_id'] ?? 0);
    if ($notifId) {
        $pdo->prepare("UPDATE notificacoes_cliente SET status='enviado', enviado_em=NOW(), enviado_por=? WHERE id=?")
            ->execute(array(current_user_id(), $notifId));
        flash_set('success', 'Marcado como enviado!');
    }
    // Redirecionar para aba "Enviados" para ver o registro
    redirect(module_url('notificacoes', 'log_cliente.php?status=enviado'));
}

// Filtros
$filtroStatus = isset($_GET['status']) ? $_GET['status'] : '';
$filtroTipo = isset($_GET['tipo']) ? $_GET['tipo'] : '';

$where = '1=1';
$params = array();
if ($filtroStatus && in_array($filtroStatus, array('pendente','enviado','falha'))) {
    $where .= ' AND nc.status = ?';
    $params[] = $filtroStatus;
}
if ($filtroTipo) {
    $where .= ' AND nc.tipo = ?';
    $params[] = $filtroTipo;
}

$stmt = $pdo->prepare(
    "SELECT nc.*, cl.name as client_name, cl.phone as client_phone
     FROM notificacoes_cliente nc
     LEFT JOIN clients cl ON cl.id = nc.client_id
     WHERE $where
     ORDER BY nc.created_at DESC
     LIMIT 100"
);
$stmt->execute($params);
$notificacoes = $stmt->fetchAll();

require_once __DIR__ . '/../../templates/layout_start.php';
?>

<div class="page-header">
    <h1>Log de Notificações ao Cliente</h1>
    <div style="display: flex; gap: 8px;">
        <a href="<?= e(module_url('notificacoes', 'config_cliente.php')) ?>" class="btn btn-secondary btn-sm">Configurar Templates</a>
    </div>
</div>

<div style="display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap;">
    <a href="?status=" class="btn btn-sm <?= !$filtroStatus ? 'btn-primary' : 'btn-secondary' ?>">Todos</a>
    <a href="?status=pendente" class="btn btn-sm <?= $filtroStatus === 'pendente' ? 'btn-primary' : 'btn-secondary' ?>">Pendentes</a>
    <a href="?status=enviado" class="btn btn-sm <?= $filtroStatus === 'enviado' ? 'btn-primary' : 'btn-secondary' ?>">Enviados</a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Cliente</th>
                    <th>Tipo</th>
                    <th>Canal</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($notificacoes)): ?>
                <tr><td colspan="6" style="text-align: center; color: var(--text-secondary); padding: 30px;">Nenhuma notificação encontrada.</td></tr>
                <?php else: ?>
                <?php foreach ($notificacoes as $n): ?>
                <tr>
                    <td style="white-space: nowrap; font-size: 13px;"><?= date('d/m/Y H:i', strtotime($n['created_at'])) ?></td>
                    <td>
                        <strong><?= e($n['client_name'] ?: 'Cliente #' . $n['client_id']) ?></strong><br>
                        <small style="color: var(--text-secondary);"><?= e($n['destinatario']) ?></small>
                    </td>
                    <td>
                        <?php
                        $tipoLabels = array('boas_vindas'=>'Boas-vindas','docs_recebidos'=>'Docs Recebidos','processo_distribuido'=>'Processo Distribuído','doc_faltante'=>'Doc Faltante');
                        echo e(isset($tipoLabels[$n['tipo']]) ? $tipoLabels[$n['tipo']] : $n['tipo']);
                        ?>
                    </td>
                    <td>
                        <?php if ($n['canal'] === 'whatsapp'): ?>
                            <span style="color: #25D366; font-weight: 600;">WhatsApp</span>
                        <?php else: ?>
                            <span style="color: #0078D4;">E-mail</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($n['status'] === 'pendente'): ?>
                            <span class="badge badge-warning">Pendente</span>
                        <?php elseif ($n['status'] === 'enviado'): ?>
                            <span class="badge badge-success">Enviado</span>
                            <?php if ($n['enviado_em']): ?>
                                <br><small><?= date('d/m H:i', strtotime($n['enviado_em'])) ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-danger">Falha</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space: nowrap;">
                        <?php if ($n['canal'] === 'whatsapp' && $n['destinatario']): ?>
                            <?php
                            $phone = preg_replace('/\D/', '', $n['destinatario']);
                            if (strlen($phone) <= 11) $phone = '55' . $phone;
                            $waUrl = 'https://wa.me/' . $phone . '?text=' . rawurlencode($n['mensagem']);
                            ?>
                            <a href="<?= e($waUrl) ?>" target="_blank" class="btn btn-sm" style="background: #25D366; color: #fff;" title="Enviar via WhatsApp">WhatsApp</a>
                        <?php endif; ?>

                        <?php if ($n['status'] === 'pendente'): ?>
                            <form method="post" style="display: inline;">
                                <?= csrf_input() ?>
                                <input type="hidden" name="notif_id" value="<?= (int)$n['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-secondary" title="Marcar como enviado">Enviado</button>
                            </form>
                        <?php endif; ?>

                        <button class="btn btn-sm btn-secondary" onclick="toggleMsg(<?= (int)$n['id'] ?>)" title="Ver mensagem">Ver</button>
                    </td>
                </tr>
                <tr id="msg-<?= (int)$n['id'] ?>" style="display: none;">
                    <td colspan="6" style="background: var(--bg-secondary); padding: 12px; white-space: pre-wrap; font-size: 13px; font-family: monospace;"><?= e($n['mensagem']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function toggleMsg(id) {
    var row = document.getElementById('msg-' + id);
    if (row) row.style.display = row.style.display === 'none' ? '' : 'none';
}
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
