<?php
/**
 * Ferreira & Sá Hub — Detalhe do Lead
 */

require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

$pdo = db();
$leadId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT pl.*, u.name as assigned_name FROM pipeline_leads pl LEFT JOIN users u ON u.id = pl.assigned_to WHERE pl.id = ?');
$stmt->execute([$leadId]);
$lead = $stmt->fetch();

if (!$lead) { flash_set('error', 'Lead não encontrado.'); redirect(module_url('pipeline')); }

$pageTitle = $lead['name'];

// Histórico de movimentações
$history = $pdo->prepare(
    'SELECT ph.*, u.name as user_name FROM pipeline_history ph
     LEFT JOIN users u ON u.id = ph.changed_by
     WHERE ph.lead_id = ? ORDER BY ph.created_at DESC'
);
$history->execute([$leadId]);
$history = $history->fetchAll();

$stageLabels = [
    'novo' => '🆕 Novo', 'contato_inicial' => '📞 Contato Inicial',
    'agendado' => '📅 Agendado', 'proposta' => '📄 Proposta',
    'elaboracao' => '📝 Elaboração Contrato', 'contrato' => '✅ Contrato Assinado',
    'perdido' => '❌ Perdido',
];

$sourceLabels = [
    'calculadora' => 'Calculadora', 'landing' => 'Site/Landing', 'indicacao' => 'Indicação',
    'instagram' => 'Instagram', 'google' => 'Google', 'whatsapp' => 'WhatsApp', 'outro' => 'Outro',
];

require_once APP_ROOT . '/templates/layout_start.php';
?>

<div style="max-width:720px;">
    <a href="<?= module_url('pipeline') ?>" class="btn btn-outline btn-sm mb-2">← Voltar ao Pipeline</a>

    <div class="card mb-2">
        <div class="card-header">
            <h3><?= e($lead['name']) ?></h3>
            <div class="flex gap-1">
                <?php if ($lead['phone']): ?>
                    <a href="https://wa.me/55<?= preg_replace('/\D/', '', $lead['phone']) ?>" target="_blank" class="btn btn-success btn-sm">💬 WhatsApp</a>
                <?php endif; ?>
                <a href="<?= module_url('pipeline', 'lead_form.php?id=' . $lead['id']) ?>" class="btn btn-outline btn-sm">✏️ Editar</a>
            </div>
        </div>
        <div class="card-body">
            <div class="info-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;">
                <div><label style="font-size:.7rem;text-transform:uppercase;color:var(--text-muted);font-weight:700;">Status</label><br><span class="badge badge-info"><?= $stageLabels[$lead['stage']] ?? $lead['stage'] ?></span></div>
                <div><label style="font-size:.7rem;text-transform:uppercase;color:var(--text-muted);font-weight:700;">Telefone</label><br><span style="font-size:.9rem;"><?= e($lead['phone'] ?: '—') ?></span></div>
                <div><label style="font-size:.7rem;text-transform:uppercase;color:var(--text-muted);font-weight:700;">E-mail</label><br><span style="font-size:.9rem;"><?= e($lead['email'] ?: '—') ?></span></div>
                <div><label style="font-size:.7rem;text-transform:uppercase;color:var(--text-muted);font-weight:700;">Origem</label><br><span style="font-size:.9rem;"><?= $sourceLabels[$lead['source']] ?? $lead['source'] ?></span></div>
                <div><label style="font-size:.7rem;text-transform:uppercase;color:var(--text-muted);font-weight:700;">Tipo de caso</label><br><span style="font-size:.9rem;"><?= e($lead['case_type'] ?: '—') ?></span></div>
                <div><label style="font-size:.7rem;text-transform:uppercase;color:var(--text-muted);font-weight:700;">Valor estimado</label><br><span style="font-size:.9rem;"><?= $lead['estimated_value_cents'] ? brl($lead['estimated_value_cents']) : '—' ?></span></div>
                <div><label style="font-size:.7rem;text-transform:uppercase;color:var(--text-muted);font-weight:700;">Responsável</label><br><span style="font-size:.9rem;"><?= e($lead['assigned_name'] ?: '—') ?></span></div>
                <div><label style="font-size:.7rem;text-transform:uppercase;color:var(--text-muted);font-weight:700;">Criado em</label><br><span style="font-size:.9rem;"><?= data_hora_br($lead['created_at']) ?></span></div>
            </div>
            <?php if ($lead['notes']): ?>
                <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);">
                    <label style="font-size:.7rem;text-transform:uppercase;color:var(--text-muted);font-weight:700;">Observações</label>
                    <p style="font-size:.88rem;margin-top:.25rem;white-space:pre-wrap;"><?= e($lead['notes']) ?></p>
                </div>
            <?php endif; ?>

            <?php if ($lead['stage'] === 'perdido' && $lead['lost_reason']): ?>
                <div class="alert alert-error mt-2">
                    <span class="alert-icon">❌</span> Motivo da perda: <?= e($lead['lost_reason']) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Mover estágio -->
    <div class="card mb-2">
        <div class="card-header"><h3>Mover para</h3></div>
        <div class="card-body">
            <form method="POST" action="<?= module_url('pipeline', 'api.php') ?>" style="display:flex;gap:.5rem;flex-wrap:wrap;">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="move">
                <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
                <?php foreach ($stageLabels as $sk => $sl): ?>
                    <?php if ($sk !== $lead['stage']): ?>
                        <button type="submit" name="to_stage" value="<?= $sk ?>" class="btn btn-outline btn-sm"
                            <?= $sk === 'perdido' ? 'data-confirm="Marcar como perdido?"' : '' ?>><?= $sl ?></button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </form>
        </div>
    </div>

    <!-- Converter em cliente -->
    <?php if ($lead['stage'] === 'contrato' && !$lead['client_id']): ?>
    <div class="card mb-2" style="border-color:var(--success);">
        <div class="card-header" style="background:var(--success-bg);"><h3 style="color:var(--success);">🎉 Converter em Cliente</h3></div>
        <div class="card-body">
            <p class="text-sm mb-2">Este lead fechou contrato! Crie o registro de cliente no CRM.</p>
            <form method="POST" action="<?= module_url('pipeline', 'api.php') ?>">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="convert">
                <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
                <button type="submit" class="btn btn-success">Criar Cliente no CRM →</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Histórico -->
    <div class="card">
        <div class="card-header"><h3>Histórico</h3></div>
        <div class="card-body">
            <?php if (empty($history)): ?>
                <p class="text-muted text-sm">Nenhuma movimentação registrada.</p>
            <?php else: ?>
                <?php foreach ($history as $h): ?>
                <div style="padding:.5rem 0;border-bottom:1px solid var(--border);font-size:.82rem;">
                    <?php if ($h['from_stage']): ?>
                        <?= $stageLabels[$h['from_stage']] ?? $h['from_stage'] ?> → <?= $stageLabels[$h['to_stage']] ?? $h['to_stage'] ?>
                    <?php else: ?>
                        Criado como <?= $stageLabels[$h['to_stage']] ?? $h['to_stage'] ?>
                    <?php endif; ?>
                    <span class="text-muted"> — <?= e($h['user_name'] ?? '') ?>, <?= data_hora_br($h['created_at']) ?></span>
                    <?php if ($h['notes']): ?><br><span class="text-muted"><?= e($h['notes']) ?></span><?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
