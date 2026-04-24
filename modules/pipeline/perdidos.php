<?php
/**
 * Ferreira & Sá Hub — Leads Perdidos
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!can_view_pipeline()) { redirect(url('modules/dashboard/')); }

$pageTitle = 'Leads Perdidos';
$pdo = db();

$leads = $pdo->query(
    "SELECT pl.*, u.name as assigned_name, DATEDIFF(NOW(), pl.created_at) as days
     FROM pipeline_leads pl
     LEFT JOIN users u ON u.id = pl.assigned_to
     WHERE pl.stage = 'perdido'
     ORDER BY pl.updated_at DESC"
)->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<a href="<?= module_url('pipeline') ?>" class="btn btn-outline btn-sm mb-2">← Voltar ao Pipeline</a>

<div class="card">
    <div class="card-header">
        <h3>Leads Perdidos (<?= count($leads) ?>)</h3>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Telefone</th>
                    <th>Tipo</th>
                    <th>Motivo</th>
                    <th>Dias no funil</th>
                    <th>Responsável</th>
                    <th style="width:120px;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($leads)): ?>
                    <tr><td colspan="7" class="text-center text-muted" style="padding:2rem;">Nenhum lead perdido.</td></tr>
                <?php else: ?>
                    <?php foreach ($leads as $l): ?>
                    <tr>
                        <td class="font-bold"><?= e($l['name']) ?></td>
                        <td class="text-sm"><?php if ($l['phone']): ?><a href="javascript:void(0)" onclick="waSenderOpen({telefone:'<?= preg_replace('/\D/', '', $l['phone']) ?>',nome:<?= e(json_encode($l['name'])) ?>,clientId:<?= (int)($l['client_id'] ?? 0) ?>,leadId:<?= (int)$l['id'] ?>,canal:'21'})" style="color:var(--success);"><?= e($l['phone']) ?></a><?php else: ?>—<?php endif; ?></td>
                        <td class="text-sm"><?= e($l['case_type'] ?: '—') ?></td>
                        <td class="text-sm text-muted"><?= e($l['lost_reason'] ?: '—') ?></td>
                        <td class="text-sm"><?= $l['days'] ?>d</td>
                        <td class="text-sm"><?= e($l['assigned_name'] ? explode(' ', $l['assigned_name'])[0] : '—') ?></td>
                        <td>
                            <form method="POST" action="<?= module_url('pipeline', 'api.php') ?>" style="display:inline;">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="move">
                                <input type="hidden" name="lead_id" value="<?= $l['id'] ?>">
                                <input type="hidden" name="to_stage" value="cadastro_preenchido">
                                <button type="submit" class="btn btn-outline btn-sm" style="font-size:.7rem;" data-confirm="Reativar <?= e($l['name']) ?>?">🔄 Reativar</button>
                            </form>
                            <form method="POST" action="<?= module_url('pipeline', 'api.php') ?>" style="display:inline;">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="lead_id" value="<?= $l['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm" style="font-size:.7rem;opacity:.5;" data-confirm="Excluir permanentemente?">🗑️</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
