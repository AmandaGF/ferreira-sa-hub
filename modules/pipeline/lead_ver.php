<?php
/**
 * Ferreira & Sá Hub — Detalhe do Lead (edição inline + formulários)
 */
require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

$pdo = db();
$leadId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT pl.*, u.name as assigned_name, cs.drive_folder_url FROM pipeline_leads pl LEFT JOIN users u ON u.id = pl.assigned_to LEFT JOIN cases cs ON cs.id = pl.linked_case_id WHERE pl.id = ?');
$stmt->execute(array($leadId));
$lead = $stmt->fetch();
if (!$lead) { flash_set('error', 'Lead não encontrado.'); redirect(module_url('pipeline')); }

// Salvar edição inline via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'inline_edit' && validate_csrf()) {
    $field = $_POST['field'] ?? '';
    $value = $_POST['value'] ?? '';
    $allowed = array('name','phone','email','case_type','notes','estimated_value_cents','assigned_to');
    if (in_array($field, $allowed)) {
        if ($field === 'estimated_value_cents') $value = (int)(floatval(str_replace(array('.', ','), array('', '.'), $value)) * 100);
        if ($field === 'assigned_to') $value = (int)$value ?: null;
        $pdo->prepare("UPDATE pipeline_leads SET $field = ?, updated_at = NOW() WHERE id = ?")->execute(array($value ?: null, $leadId));
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(array('ok' => true));
            exit;
        }
    }
    redirect(module_url('pipeline', 'lead_ver.php?id=' . $leadId));
}

$pageTitle = $lead['name'];

// Histórico
$history = $pdo->prepare('SELECT ph.*, u.name as user_name FROM pipeline_history ph LEFT JOIN users u ON u.id = ph.changed_by WHERE ph.lead_id = ? ORDER BY ph.created_at DESC');
$history->execute(array($leadId));
$history = $history->fetchAll();

// Respostas dos formulários (pelo nome ou client_id)
$formSubs = array();
try {
    if ($lead['client_id']) {
        $fs = $pdo->prepare("SELECT * FROM form_submissions WHERE linked_client_id = ? ORDER BY created_at DESC");
        $fs->execute(array($lead['client_id']));
        $formSubs = $fs->fetchAll();
    }
    if (empty($formSubs)) {
        $fs = $pdo->prepare("SELECT * FROM form_submissions WHERE client_name LIKE ? ORDER BY created_at DESC LIMIT 5");
        $fs->execute(array('%' . $lead['name'] . '%'));
        $formSubs = $fs->fetchAll();
    }
} catch (Exception $e) {}

$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

$stageLabels = array(
    'cadastro_preenchido'=>'📋 Cadastro','elaboracao_docs'=>'📝 Elaboração','link_enviados'=>'📨 Link Enviado',
    'contrato_assinado'=>'✅ Contrato','agendado_docs'=>'📅 Agendado','reuniao_cobranca'=>'🤝 Reunião',
    'doc_faltante'=>'⚠️ Doc Faltante','pasta_apta'=>'✔️ Pasta Apta','cancelado'=>'❌ Cancelado',
    'suspenso'=>'⏸️ Suspenso','finalizado'=>'🏁 Finalizado','perdido'=>'❌ Perdido',
);
$sourceLabels = array('calculadora'=>'Calculadora','landing'=>'Site','indicacao'=>'Indicação','instagram'=>'Instagram','google'=>'Google','whatsapp'=>'WhatsApp','outro'=>'Outro');

// Labels dos campos de formulário
$formFieldLabels = array(
    'nome'=>'Nome','name'=>'Nome','telefone'=>'Telefone','phone'=>'Telefone',
    'email'=>'E-mail','cpf'=>'CPF','rg'=>'RG','data_nascimento'=>'Data Nasc.',
    'endereco'=>'Endereço','address'=>'Endereço','cep'=>'CEP','cidade'=>'Cidade','estado'=>'Estado',
    'tipo_acao'=>'Tipo de Ação','case_type'=>'Tipo','profissao'=>'Profissão',
    'estado_civil'=>'Estado Civil','tem_filhos'=>'Tem filhos?','nomes_filhos'=>'Nomes dos filhos',
    'observacoes'=>'Observações','mensagem'=>'Mensagem','descricao'=>'Descrição',
    'renda_mensal'=>'Renda Mensal','valor_pensao'=>'Valor Pensão',
);

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.lead-detail { max-width:780px; }
.lead-detail .card { margin-bottom:1rem; }
.field-editable { cursor:pointer;padding:4px 8px;border-radius:6px;transition:background .15s;min-height:1.5em;display:inline-block; }
.field-editable:hover { background:rgba(215,171,144,.15);outline:1px dashed var(--rose); }
.field-editable:focus { background:#fff;outline:2px solid var(--rose);border-radius:6px; }
.field-editing { background:#fff;border:2px solid var(--rose);border-radius:6px;padding:4px 8px;font-size:inherit;font-family:inherit;width:100%;outline:none; }
.field-label { font-size:.7rem;text-transform:uppercase;color:var(--text-muted);font-weight:700;display:block;margin-bottom:2px; }
.form-response { background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;margin-top:.5rem; }
.form-response-field { display:flex;gap:.5rem;padding:.35rem 0;border-bottom:1px solid rgba(0,0,0,.05);font-size:.82rem; }
.form-response-field:last-child { border-bottom:none; }
.form-response-label { font-weight:700;color:var(--petrol-900);min-width:120px;flex-shrink:0; }
.form-response-value { color:var(--text); }
.save-indicator { font-size:.65rem;color:var(--success);font-weight:600;margin-left:.5rem;opacity:0;transition:opacity .3s; }
.save-indicator.show { opacity:1; }
</style>

<div class="lead-detail">
    <a href="<?= module_url('pipeline') ?>" class="btn btn-outline btn-sm mb-2">← Voltar ao Kanban</a>

    <div class="card">
        <div class="card-header">
            <h3 style="font-size:1.1rem;">
                <span class="field-editable" contenteditable="true" data-field="name" data-id="<?= $leadId ?>"><?= e($lead['name']) ?></span>
                <span class="save-indicator" id="save-name">Salvo!</span>
            </h3>
            <div style="display:flex;gap:.5rem;">
                <?php if (!empty($lead['drive_folder_url'])): ?>
                    <a href="<?= e($lead['drive_folder_url']) ?>" target="_blank" class="btn btn-outline btn-sm" style="color:#0ea5e9;border-color:#0ea5e9;">📂 Pasta Drive</a>
                <?php endif; ?>
                <?php if ($lead['phone']): ?>
                    <a href="https://wa.me/55<?= preg_replace('/\D/', '', $lead['phone']) ?>" target="_blank" class="btn btn-success btn-sm">💬 WhatsApp</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;">
                <div>
                    <span class="field-label">Etapa</span>
                    <span class="badge badge-info"><?= $stageLabels[$lead['stage']] ?? $lead['stage'] ?></span>
                </div>
                <div>
                    <span class="field-label">Telefone</span>
                    <span class="field-editable" contenteditable="true" data-field="phone" data-id="<?= $leadId ?>"><?= e($lead['phone'] ?: '—') ?></span>
                    <span class="save-indicator" id="save-phone">Salvo!</span>
                </div>
                <div>
                    <span class="field-label">E-mail</span>
                    <span class="field-editable" contenteditable="true" data-field="email" data-id="<?= $leadId ?>"><?= e($lead['email'] ?: '—') ?></span>
                    <span class="save-indicator" id="save-email">Salvo!</span>
                </div>
                <div>
                    <span class="field-label">Origem</span>
                    <span style="font-size:.88rem;"><?= $sourceLabels[$lead['source']] ?? $lead['source'] ?></span>
                </div>
                <div>
                    <span class="field-label">Tipo de Ação</span>
                    <span class="field-editable" contenteditable="true" data-field="case_type" data-id="<?= $leadId ?>"><?= e($lead['case_type'] ?: '—') ?></span>
                    <span class="save-indicator" id="save-case_type">Salvo!</span>
                </div>
                <div>
                    <span class="field-label">Valor Estimado</span>
                    <span class="field-editable" contenteditable="true" data-field="estimated_value_cents" data-id="<?= $leadId ?>"><?= $lead['estimated_value_cents'] ? number_format($lead['estimated_value_cents'] / 100, 2, ',', '.') : '—' ?></span>
                    <span class="save-indicator" id="save-estimated_value_cents">Salvo!</span>
                </div>
                <div>
                    <span class="field-label">Responsável</span>
                    <select onchange="saveField('assigned_to',this.value,<?= $leadId ?>)" style="font-size:.85rem;padding:3px 6px;border:1px solid var(--border);border-radius:6px;background:var(--bg-card);cursor:pointer;">
                        <option value="">— Nenhum —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $lead['assigned_to'] == $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="save-indicator" id="save-assigned_to">Salvo!</span>
                </div>
                <div>
                    <span class="field-label">Criado em</span>
                    <span style="font-size:.88rem;"><?= date('d/m/Y H:i', strtotime($lead['created_at'])) ?></span>
                </div>
            </div>

            <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);">
                <span class="field-label">Observações</span>
                <div class="field-editable" contenteditable="true" data-field="notes" data-id="<?= $leadId ?>" style="min-height:3em;white-space:pre-wrap;font-size:.88rem;"><?= e($lead['notes'] ?: 'Clique para adicionar...') ?></div>
                <span class="save-indicator" id="save-notes">Salvo!</span>
            </div>

            <?php if ($lead['stage'] === 'perdido' && $lead['lost_reason']): ?>
                <div style="margin-top:.75rem;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:.6rem .8rem;font-size:.82rem;color:#dc2626;">
                    <strong>❌ Motivo da perda:</strong> <?= e($lead['lost_reason']) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Respostas dos Formulários -->
    <?php if (!empty($formSubs)): ?>
    <div class="card">
        <div class="card-header"><h3>📋 Respostas dos Formulários</h3></div>
        <div class="card-body">
            <?php foreach ($formSubs as $fs):
                $payload = json_decode($fs['payload_json'], true);
                if (!$payload) continue;
                $typeLabels = array('cadastro_cliente'=>'Cadastro','calculadora_lead'=>'Calculadora','convivencia'=>'Convivência','gastos_pensao'=>'Gastos Pensão','divorcio'=>'Divórcio','alimentos'=>'Alimentos');
            ?>
            <div class="form-response">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;">
                    <strong style="font-size:.85rem;color:var(--petrol-900);">
                        <?= $typeLabels[$fs['form_type']] ?? $fs['form_type'] ?>
                        <span style="font-size:.7rem;color:var(--text-muted);font-weight:400;margin-left:.5rem;">Protocolo: <?= e($fs['protocol']) ?></span>
                    </strong>
                    <span style="font-size:.72rem;color:var(--text-muted);"><?= date('d/m/Y H:i', strtotime($fs['created_at'])) ?></span>
                </div>
                <?php foreach ($payload as $key => $val):
                    if (empty($val) || $key === 'csrf_token' || $key === 'form_type') continue;
                    $label = isset($formFieldLabels[$key]) ? $formFieldLabels[$key] : ucfirst(str_replace('_', ' ', $key));
                    if (is_array($val)) $val = implode(', ', $val);
                ?>
                <div class="form-response-field">
                    <span class="form-response-label"><?= e($label) ?></span>
                    <span class="form-response-value"><?= e($val) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Mover estágio -->
    <div class="card">
        <div class="card-header"><h3>Mover para</h3></div>
        <div class="card-body">
            <form method="POST" action="<?= module_url('pipeline', 'api.php') ?>" data-lead-name="<?= e($lead['name']) ?>" data-case-type="<?= e($lead['case_type'] ?: '') ?>" style="display:flex;gap:.5rem;flex-wrap:wrap;">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="move">
                <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
                <input type="hidden" name="folder_name" value="">
                <?php foreach ($stageLabels as $sk => $sl): ?>
                    <?php if ($sk !== $lead['stage'] && $sk !== 'doc_faltante'): ?>
                        <button type="submit" name="to_stage" value="<?= $sk ?>" class="btn btn-outline btn-sm"
                            onclick="<?= $sk === 'contrato_assinado' ? 'event.preventDefault();handleStageButton(this.form,\'' . $sk . '\')' : '' ?>"
                            <?= $sk === 'perdido' ? 'data-confirm="Marcar como perdido?"' : '' ?>><?= $sl ?></button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </form>
        </div>
    </div>

    <!-- Histórico -->
    <div class="card">
        <div class="card-header"><h3>Histórico</h3></div>
        <div class="card-body">
            <?php if (empty($history)): ?>
                <p style="color:var(--text-muted);font-size:.82rem;">Nenhuma movimentação registrada.</p>
            <?php else: ?>
                <?php foreach ($history as $h): ?>
                <div style="padding:.5rem 0;border-bottom:1px solid var(--border);font-size:.82rem;">
                    <?= $stageLabels[$h['from_stage']] ?? $h['from_stage'] ?> → <?= $stageLabels[$h['to_stage']] ?? $h['to_stage'] ?>
                    <span style="color:var(--text-muted);"> — <?= e($h['user_name'] ?? '') ?>, <?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></span>
                    <?php if ($h['notes']): ?><br><span style="color:var(--text-muted);"><?= e($h['notes']) ?></span><?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
var csrfToken = '<?= generate_csrf_token() ?>';
var apiUrl = '<?= module_url("pipeline", "lead_ver.php?id=" . $leadId) ?>';

// Edição inline — salvar ao sair do campo
document.querySelectorAll('.field-editable[contenteditable]').forEach(function(el) {
    var original = el.textContent.trim();

    el.addEventListener('blur', function() {
        var newVal = this.textContent.trim();
        if (newVal === original || (newVal === 'Clique para adicionar...' && !original)) return;
        if (newVal === '—') newVal = '';
        saveField(this.dataset.field, newVal, this.dataset.id);
        original = newVal;
    });

    el.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && this.dataset.field !== 'notes') {
            e.preventDefault();
            this.blur();
        }
        if (e.key === 'Escape') {
            this.textContent = original;
            this.blur();
        }
    });
});

function saveField(field, value, id) {
    var formData = new FormData();
    formData.append('action', 'inline_edit');
    formData.append('field', field);
    formData.append('value', value);
    formData.append('csrf_token', csrfToken);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', apiUrl);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onload = function() {
        var indicator = document.getElementById('save-' + field);
        if (indicator) {
            indicator.classList.add('show');
            setTimeout(function() { indicator.classList.remove('show'); }, 1500);
        }
    };
    xhr.send(formData);
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
