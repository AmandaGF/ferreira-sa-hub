<?php
/**
 * Ferreira & Sá Hub — Perfil do Cliente (CRM)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$clientId = (int)($_GET['id'] ?? 0);
$isReadOnly = has_role('colaborador');

$stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
$stmt->execute([$clientId]);
$client = $stmt->fetch();

if (!$client) {
    flash_set('error', 'Cliente não encontrado.');
    redirect(module_url('crm'));
}

$pageTitle = $client['name'];

// Casos do cliente
$cases = $pdo->prepare(
    'SELECT cs.*, u.name as responsible_name FROM cases cs
     LEFT JOIN users u ON u.id = cs.responsible_user_id
     WHERE cs.client_id = ? ORDER BY cs.created_at DESC'
);
$cases->execute([$clientId]);
$cases = $cases->fetchAll();

// Timeline de contatos
$contacts = $pdo->prepare(
    'SELECT ct.*, u.name as user_name FROM contacts ct
     LEFT JOIN users u ON u.id = ct.contacted_by
     WHERE ct.client_id = ? ORDER BY ct.contacted_at DESC LIMIT 50'
);
$contacts->execute([$clientId]);
$contacts = $contacts->fetchAll();

// Formulários vinculados
$forms = $pdo->prepare(
    'SELECT * FROM form_submissions WHERE linked_client_id = ? ORDER BY created_at DESC'
);
$forms->execute([$clientId]);
$forms = $forms->fetchAll();

// Status labels para casos
$statusLabels = [
    'aguardando_docs' => 'Aguardando docs',
    'em_elaboracao' => 'Em elaboração',
    'aguardando_prazo' => 'Aguardando prazo',
    'distribuido' => 'Distribuído',
    'em_andamento' => 'Em andamento',
    'concluido' => 'Concluído',
    'arquivado' => 'Arquivado',
    'suspenso' => 'Suspenso',
];

$statusBadge = [
    'aguardando_docs' => 'warning',
    'em_elaboracao' => 'info',
    'aguardando_prazo' => 'warning',
    'distribuido' => 'success',
    'em_andamento' => 'info',
    'concluido' => 'success',
    'arquivado' => 'gestao',
    'suspenso' => 'danger',
];

$contactIcons = [
    'whatsapp' => '💬', 'telefone' => '📞', 'email' => '📧',
    'presencial' => '🤝', 'reuniao' => '📅', 'nota' => '📝',
];

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.client-header { display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem; }
.client-name { font-size:1.3rem; font-weight:800; color:var(--petrol-900); }
.client-meta { font-size:.82rem; color:var(--text-muted); margin-top:.15rem; }
.client-actions { display:flex; gap:.5rem; }
.tabs { display:flex; gap:0; border-bottom:2px solid var(--border); margin-bottom:1.5rem; }
.tab { padding:.6rem 1.25rem; font-size:.88rem; font-weight:600; color:var(--text-muted); cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-2px; transition:all var(--transition); background:none; border-top:none; border-left:none; border-right:none; }
.tab:hover { color:var(--petrol-500); }
.tab.active { color:var(--petrol-900); border-bottom-color:var(--rose); }
.tab-content { display:none; }
.tab-content.active { display:block; }
.timeline-item { display:flex; gap:1rem; padding:1rem 0; border-bottom:1px solid var(--border); }
.timeline-item:last-child { border-bottom:none; }
.timeline-icon { width:36px; height:36px; border-radius:50%; background:var(--petrol-100); display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0; }
.timeline-body { flex:1; }
.timeline-date { font-size:.72rem; color:var(--text-muted); }
.timeline-text { font-size:.88rem; margin-top:.15rem; }
.timeline-user { font-size:.75rem; color:var(--rose-dark); font-weight:600; }
.info-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:1rem; }
.info-item label { font-size:.7rem; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); font-weight:700; display:block; margin-bottom:.15rem; }
.info-item span { font-size:.9rem; color:var(--text); }
</style>

<div class="client-header">
    <div>
        <a href="<?= module_url('crm') ?>" class="text-sm text-muted" style="display:inline-block;margin-bottom:.25rem;">← Voltar ao CRM</a>
        <div class="client-name"><?= e($client['name']) ?></div>
        <div class="client-meta">
            <?php if ($client['cpf']): ?>CPF: <?= e($client['cpf']) ?> · <?php endif; ?>
            Cadastrado em <?= data_br($client['created_at']) ?>
            · Origem: <?= e($client['source'] ?? '—') ?>
        </div>
    </div>
    <div class="client-actions">
        <?php if ($client['phone']): ?>
            <a href="https://wa.me/55<?= preg_replace('/\D/', '', $client['phone']) ?>" target="_blank" class="btn btn-success btn-sm">💬 WhatsApp</a>
        <?php endif; ?>
        <?php if (!$isReadOnly): ?>
            <a href="<?= module_url('crm', 'cliente_form.php?id=' . $client['id']) ?>" class="btn btn-outline btn-sm">✏️ Editar</a>
        <?php endif; ?>
    </div>
</div>

<!-- Tabs -->
<div class="tabs">
    <button class="tab active" onclick="showTab('dados')">Dados</button>
    <button class="tab" onclick="showTab('casos')">Casos (<?= count($cases) ?>)</button>
    <button class="tab" onclick="showTab('timeline')">Timeline (<?= count($contacts) ?>)</button>
    <button class="tab" onclick="showTab('formularios')">Formulários (<?= count($forms) ?>)</button>
</div>

<!-- Tab: Dados -->
<div class="tab-content active" id="tab-dados">
    <div class="card">
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>Nome</label><span><?= e($client['name']) ?></span></div>
                <div class="info-item"><label>CPF</label><span><?= e($client['cpf'] ?: '—') ?></span></div>
                <div class="info-item"><label>RG</label><span><?= e($client['rg'] ?: '—') ?></span></div>
                <div class="info-item"><label>Nascimento</label><span><?= data_br($client['birth_date']) ?></span></div>
                <div class="info-item"><label>Telefone</label><span><?= e($client['phone'] ?: '—') ?></span></div>
                <div class="info-item"><label>Telefone 2</label><span><?= e($client['phone2'] ?: '—') ?></span></div>
                <div class="info-item"><label>E-mail</label><span><?= e($client['email'] ?: '—') ?></span></div>
                <div class="info-item"><label>Profissão</label><span><?= e($client['profession'] ?: '—') ?></span></div>
                <div class="info-item"><label>Estado civil</label><span><?= e($client['marital_status'] ?: '—') ?></span></div>
                <div class="info-item"><label>Endereço</label><span><?= e($client['address_street'] ?: '—') ?></span></div>
                <div class="info-item"><label>Cidade/UF</label><span><?= e(($client['address_city'] ?: '') . ($client['address_state'] ? '/' . $client['address_state'] : '')) ?: '—' ?></span></div>
                <div class="info-item"><label>CEP</label><span><?= e($client['address_zip'] ?: '—') ?></span></div>
            </div>
            <?php if ($client['notes']): ?>
                <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--border);">
                    <label style="font-size:.7rem;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:700;">Observações</label>
                    <p style="font-size:.88rem;margin-top:.25rem;white-space:pre-wrap;"><?= e($client['notes']) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Tab: Casos -->
<div class="tab-content" id="tab-casos">
    <?php if (!$isReadOnly): ?>
        <a href="<?= module_url('crm', 'caso_form.php?client_id=' . $client['id']) ?>" class="btn btn-primary btn-sm mb-2">+ Novo Caso</a>
    <?php endif; ?>

    <?php if (empty($cases)): ?>
        <div class="card"><div class="card-body empty-state"><h3>Nenhum caso</h3><p>Este cliente ainda não tem processos cadastrados.</p></div></div>
    <?php else: ?>
        <div class="card">
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>Título</th><th>Tipo</th><th>Status</th><th>Prioridade</th><th>Responsável</th><th>Prazo</th></tr></thead>
                    <tbody>
                        <?php foreach ($cases as $cs): ?>
                        <tr>
                            <td class="font-bold"><?= e($cs['title']) ?></td>
                            <td class="text-sm"><?= e($cs['case_type']) ?></td>
                            <td><span class="badge badge-<?= $statusBadge[$cs['status']] ?? 'gestao' ?>"><?= $statusLabels[$cs['status']] ?? $cs['status'] ?></span></td>
                            <td><span class="badge badge-<?= $cs['priority'] === 'urgente' ? 'danger' : ($cs['priority'] === 'alta' ? 'warning' : 'gestao') ?>"><?= e($cs['priority']) ?></span></td>
                            <td class="text-sm"><?= e($cs['responsible_name'] ?: '—') ?></td>
                            <td class="text-sm"><?= data_br($cs['deadline']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Tab: Timeline -->
<div class="tab-content" id="tab-timeline">
    <?php if (!$isReadOnly): ?>
        <button class="btn btn-primary btn-sm mb-2" data-modal="modalContato">+ Registrar Contato</button>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <?php if (empty($contacts)): ?>
                <div class="empty-state"><h3>Nenhum contato registrado</h3><p>Registre ligações, reuniões e interações com o cliente.</p></div>
            <?php else: ?>
                <?php foreach ($contacts as $ct): ?>
                <div class="timeline-item">
                    <div class="timeline-icon"><?= $contactIcons[$ct['type']] ?? '📝' ?></div>
                    <div class="timeline-body">
                        <div class="timeline-date"><?= data_hora_br($ct['contacted_at']) ?></div>
                        <div class="timeline-text"><?= nl2br(e($ct['summary'])) ?></div>
                        <div class="timeline-user"><?= e($ct['user_name'] ?? '') ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Tab: Formulários -->
<div class="tab-content" id="tab-formularios">
    <div class="card">
        <div class="card-body">
            <?php if (empty($forms)): ?>
                <div class="empty-state"><h3>Nenhum formulário vinculado</h3><p>Formulários recebidos podem ser vinculados a este cliente.</p></div>
            <?php else: ?>
                <?php foreach ($forms as $fm): ?>
                <div style="padding:.75rem 0;border-bottom:1px solid var(--border);">
                    <span class="badge badge-info"><?= e($fm['form_type']) ?></span>
                    <strong style="font-size:.88rem;"><?= e($fm['protocol']) ?></strong>
                    <span class="text-sm text-muted"> — <?= data_hora_br($fm['created_at']) ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal: Novo Contato -->
<div class="modal-overlay" id="modalContato">
    <div class="modal">
        <div class="modal-header">
            <h3>Registrar Contato</h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="<?= module_url('crm', 'api.php') ?>">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="add_contact">
                <input type="hidden" name="client_id" value="<?= $client['id'] ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Tipo</label>
                        <select name="type" class="form-select" required>
                            <option value="whatsapp">💬 WhatsApp</option>
                            <option value="telefone">📞 Telefone</option>
                            <option value="email">📧 E-mail</option>
                            <option value="presencial">🤝 Presencial</option>
                            <option value="reuniao">📅 Reunião</option>
                            <option value="nota">📝 Nota interna</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Data/hora</label>
                        <input type="datetime-local" name="contacted_at" class="form-input" value="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Resumo *</label>
                    <textarea name="summary" class="form-textarea" rows="3" required placeholder="Descreva o que foi tratado..."></textarea>
                </div>

                <div class="modal-footer" style="border:none;padding:1rem 0 0;">
                    <button type="button" class="btn btn-outline" data-modal-close>Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showTab(name) {
    document.querySelectorAll('.tab').forEach(function(t) { t.classList.remove('active'); });
    document.querySelectorAll('.tab-content').forEach(function(c) { c.classList.remove('active'); });
    document.getElementById('tab-' + name).classList.add('active');
    event.target.classList.add('active');
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
