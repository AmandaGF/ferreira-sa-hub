<?php
/**
 * Ferreira & Sá Hub — Detalhe do Lead (edição inline + formulários + campos comerciais)
 */
require_once __DIR__ . '/../../core/middleware.php';
require_access('pipeline');

$pdo = db();
$leadId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT pl.*, u.name as assigned_name, cs.drive_folder_url
     FROM pipeline_leads pl
     LEFT JOIN users u ON u.id = pl.assigned_to
     LEFT JOIN cases cs ON cs.id = pl.linked_case_id
     WHERE pl.id = ?'
);
$stmt->execute(array($leadId));
$lead = $stmt->fetch();
if (!$lead) { flash_set('error', 'Lead não encontrado.'); redirect(module_url('pipeline')); }

// Salvar edição inline via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'inline_edit' && validate_csrf()) {
    $field = $_POST['field'] ?? '';
    $value = $_POST['value'] ?? '';
    $allowed = array('name','phone','email','case_type','notes','estimated_value_cents','assigned_to',
        'valor_acao','exito_percentual','vencimento_parcela','forma_pagamento','urgencia','cadastro_asaas','observacoes',
        'nome_pasta','pendencias');
    if (in_array($field, $allowed)) {
        if ($field === 'assigned_to') $value = (int)$value ?: null;
        $pdo->prepare("UPDATE pipeline_leads SET $field = ?, updated_at = NOW() WHERE id = ?")->execute(array($value ?: null, $leadId));
        // Sincronizar valor_acao → estimated_value_cents
        if ($field === 'valor_acao') { sync_estimated_value($pdo, $leadId, $value ?: null); }
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

// Respostas dos formulários
$formSubs = array();
try {
    if ($lead['client_id']) {
        $fs = $pdo->prepare("SELECT * FROM form_submissions WHERE linked_client_id = ? ORDER BY created_at DESC");
        $fs->execute(array($lead['client_id']));
        $formSubs = $fs->fetchAll();
    }
    if (empty($formSubs)) {
        $searchName = $lead['name'];
        if (strpos($searchName, ' x ') !== false) $searchName = trim(explode(' x ', $searchName)[0]);
        $fs = $pdo->prepare("SELECT * FROM form_submissions WHERE client_name LIKE ? ORDER BY created_at DESC LIMIT 5");
        $fs->execute(array('%' . $searchName . '%'));
        $formSubs = $fs->fetchAll();
    }
} catch (Exception $e) {}

$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();
$isOperacional = has_role('operacional');
$isComercial = has_role('comercial') || has_role('cx') || has_role('admin') || has_role('gestao');

$stageLabels = array(
    'cadastro_preenchido'=>'📋 Cadastro','elaboracao_docs'=>'📝 Elaboração','link_enviados'=>'📨 Link Enviado',
    'contrato_assinado'=>'✅ Contrato','agendado_docs'=>'📅 Agendado','reuniao_cobranca'=>'🤝 Reunião',
    'doc_faltante'=>'⚠️ Doc Faltante','pasta_apta'=>'✔️ Pasta Apta','cancelado'=>'❌ Cancelado',
    'suspenso'=>'⏸️ Suspenso','finalizado'=>'🏁 Finalizado','perdido'=>'❌ Perdido',
);
$sourceLabels = array('calculadora'=>'Calculadora','landing'=>'Site','indicacao'=>'Indicação','instagram'=>'Instagram','google'=>'Google','whatsapp'=>'WhatsApp','outro'=>'Outro');

$formFieldLabels = array(
    'nome'=>'Nome','name'=>'Nome','telefone'=>'Telefone','phone'=>'Telefone',
    'email'=>'E-mail','cpf'=>'CPF','rg'=>'RG','data_nascimento'=>'Data Nasc.',
    'endereco'=>'Endereço','cep'=>'CEP','cidade'=>'Cidade','estado'=>'Estado',
    'tipo_acao'=>'Tipo de Ação','profissao'=>'Profissão','estado_civil'=>'Estado Civil',
    'tem_filhos'=>'Tem filhos?','nomes_filhos'=>'Nomes dos filhos',
    'observacoes'=>'Observações','mensagem'=>'Mensagem','descricao'=>'Descrição',
    'renda_mensal'=>'Renda Mensal','valor_pensao'=>'Valor Pensão',
);

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.lead-detail { max-width:820px; }
.lead-detail .card { margin-bottom:1rem; }
.fe { cursor:pointer;padding:4px 8px;border-radius:6px;transition:background .15s;min-height:1.4em;display:inline-block; }
.fe:hover { background:rgba(215,171,144,.15);outline:1px dashed var(--rose); }
.fe:focus { background:#fff;outline:2px solid var(--rose); }
.fl { font-size:.68rem;text-transform:uppercase;color:var(--text-muted);font-weight:700;display:block;margin-bottom:2px; }
.sv { font-size:.6rem;color:var(--success);font-weight:600;margin-left:.35rem;opacity:0;transition:opacity .3s; }
.sv.show { opacity:1; }
.info-grid { display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.85rem; }
.info-val { font-size:.88rem; }
.section-title { font-size:.85rem;font-weight:700;color:var(--petrol-900);margin-bottom:.5rem;display:flex;align-items:center;gap:.5rem; }
.badge-inadimplente { background:#dc2626;color:#fff;padding:3px 10px;border-radius:12px;font-size:.7rem;font-weight:700;animation:pulse 2s infinite; }
.badge-adimplente { background:#059669;color:#fff;padding:3px 10px;border-radius:12px;font-size:.7rem;font-weight:700; }
.badge-asaas { background:#7c3aed;color:#fff;padding:3px 10px;border-radius:12px;font-size:.7rem;font-weight:700; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.7} }
.fin-card { display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem;padding:.75rem;background:linear-gradient(135deg,#f8f9fa,#fff);border-radius:var(--radius);border:1px solid var(--border); }
.fin-item { text-align:center; }
.fin-item .fl { text-align:center; }
.form-response { background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;margin-top:.5rem; }
.form-response-field { display:flex;gap:.5rem;padding:.3rem 0;border-bottom:1px solid rgba(0,0,0,.05);font-size:.82rem; }
.form-response-field:last-child { border-bottom:none; }
.form-response-label { font-weight:700;color:var(--petrol-900);min-width:120px;flex-shrink:0; }
</style>

<div class="lead-detail">
    <a href="<?= module_url('pipeline') ?>" class="btn btn-outline btn-sm" style="margin-bottom:.75rem;">← Voltar ao Kanban</a>

    <!-- Header -->
    <div class="card">
        <div class="card-header">
            <h3 style="font-size:1.1rem;">
                <span class="fe" contenteditable="true" data-field="name" data-id="<?= $leadId ?>"><?= e($lead['name']) ?></span>
                <span class="sv" id="save-name">Salvo!</span>
            </h3>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                <?php if (!empty($lead['drive_folder_url'])): ?>
                    <a href="<?= e($lead['drive_folder_url']) ?>" target="_blank" class="btn btn-outline btn-sm" style="color:#0ea5e9;border-color:#0ea5e9;">📂 Drive</a>
                <?php endif; ?>
                <?php if ($lead['phone']): ?>
                    <a href="https://wa.me/55<?= preg_replace('/\D/', '', $lead['phone']) ?>" target="_blank" class="btn btn-success btn-sm">💬 WhatsApp</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <!-- Dados básicos -->
            <div class="info-grid">
                <div>
                    <span class="fl">Etapa</span>
                    <span class="badge badge-info"><?= $stageLabels[$lead['stage']] ?? $lead['stage'] ?></span>
                </div>
                <div>
                    <span class="fl">Telefone</span>
                    <span class="fe info-val" contenteditable="true" data-field="phone" data-id="<?= $leadId ?>"><?= e($lead['phone'] ?: '—') ?></span>
                    <span class="sv" id="save-phone">Salvo!</span>
                </div>
                <div>
                    <span class="fl">E-mail</span>
                    <span class="fe info-val" contenteditable="true" data-field="email" data-id="<?= $leadId ?>"><?= e($lead['email'] ?: '—') ?></span>
                    <span class="sv" id="save-email">Salvo!</span>
                </div>
                <div>
                    <span class="fl">Origem</span>
                    <span class="info-val"><?= $sourceLabels[$lead['source']] ?? $lead['source'] ?></span>
                </div>
                <div>
                    <span class="fl">Tipo de Ação</span>
                    <span class="fe info-val" contenteditable="true" data-field="case_type" data-id="<?= $leadId ?>"><?= e($lead['case_type'] ?: '—') ?></span>
                    <span class="sv" id="save-case_type">Salvo!</span>
                </div>
                <div>
                    <span class="fl">Responsável</span>
                    <select onchange="saveField('assigned_to',this.value,<?= $leadId ?>)" style="font-size:.82rem;padding:3px 6px;border:1px solid var(--border);border-radius:6px;background:var(--bg-card);cursor:pointer;width:100%;max-width:200px;">
                        <option value="">—</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $lead['assigned_to'] == $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="sv" id="save-assigned_to">Salvo!</span>
                </div>
                <div>
                    <span class="fl">Nome da Pasta</span>
                    <span class="fe info-val" contenteditable="true" data-field="nome_pasta" data-id="<?= $leadId ?>"><?= e($lead['nome_pasta'] ?: '—') ?></span>
                    <span class="sv" id="save-nome_pasta">Salvo!</span>
                </div>
                <div>
                    <span class="fl">Criado em</span>
                    <span class="info-val"><?= date('d/m/Y H:i', strtotime($lead['created_at'])) ?></span>
                </div>
            </div>

            <!-- Financeiro (visível para comercial/gestão/admin) -->
            <?php if ($isComercial): ?>
            <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--border);">
                <div class="section-title">💰 Financeiro</div>
                <div class="fin-card">
                    <div class="fin-item">
                        <span class="fl">Honorários (R$)</span>
                        <span class="fe" contenteditable="true" data-field="valor_acao" data-id="<?= $leadId ?>" style="font-weight:700;color:var(--petrol-900);font-size:.95rem;"><?= $lead['honorarios_cents'] ? 'R$ ' . number_format($lead['honorarios_cents']/100, 2, ',', '.') : e($lead['valor_acao'] ?: '—') ?></span>
                        <span class="sv" id="save-valor_acao">Salvo!</span>
                    </div>
                    <div class="fin-item">
                        <span class="fl">Êxito (%)</span>
                        <span class="fe" contenteditable="true" data-field="exito_percentual" data-id="<?= $leadId ?>"><?= $lead['exito_percentual'] ? e($lead['exito_percentual']) . '%' : '—' ?></span>
                        <span class="sv" id="save-exito_percentual">Salvo!</span>
                    </div>
                    <div class="fin-item">
                        <span class="fl">Vencto 1ª Parcela</span>
                        <span class="fe" contenteditable="true" data-field="vencimento_parcela" data-id="<?= $leadId ?>"><?= e($lead['vencimento_parcela'] ?: '—') ?></span>
                        <span class="sv" id="save-vencimento_parcela">Salvo!</span>
                    </div>
                    <div class="fin-item">
                        <span class="fl">Forma de Pagamento</span>
                        <span class="fe" contenteditable="true" data-field="forma_pagamento" data-id="<?= $leadId ?>"><?= e($lead['forma_pagamento'] ?: '—') ?></span>
                        <span class="sv" id="save-forma_pagamento">Salvo!</span>
                    </div>
                    <div class="fin-item">
                        <span class="fl">Cadastro Asaas</span>
                        <select onchange="saveField('cadastro_asaas',this.value,<?= $leadId ?>)" style="font-size:.82rem;padding:3px 6px;border:1px solid var(--border);border-radius:6px;background:var(--bg-card);cursor:pointer;">
                            <option value="" <?= !$lead['cadastro_asaas'] ? 'selected' : '' ?>>—</option>
                            <option value="Sim" <?= $lead['cadastro_asaas'] === 'Sim' ? 'selected' : '' ?>>✅ Sim</option>
                            <option value="Não" <?= $lead['cadastro_asaas'] === 'Não' ? 'selected' : '' ?>>❌ Não</option>
                        </select>
                        <span class="sv" id="save-cadastro_asaas">Salvo!</span>
                    </div>
                    <div class="fin-item">
                        <span class="fl">Urgência</span>
                        <span class="fe" contenteditable="true" data-field="urgencia" data-id="<?= $leadId ?>"><?= e($lead['urgencia'] ?: '—') ?></span>
                        <span class="sv" id="save-urgencia">Salvo!</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Inadimplência (visível para TODOS) -->
            <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
                <div class="section-title" style="margin:0;">📊 Status Financeiro</div>
                <?php
                $inadimplente = mb_strtolower(trim($lead['pendencias'] ?? ''));
                $isInadimplente = $inadimplente && (strpos($inadimplente, 'inadimplente') !== false || strpos($inadimplente, 'inadimpl') !== false);
                ?>
                <?php if ($lead['cadastro_asaas'] === 'Sim'): ?>
                    <span class="badge-asaas">Asaas ✓</span>
                <?php endif; ?>
                <?php if ($isInadimplente): ?>
                    <span class="badge-inadimplente">⚠️ INADIMPLENTE</span>
                <?php elseif ($lead['cadastro_asaas'] === 'Sim'): ?>
                    <span class="badge-adimplente">Adimplente ✓</span>
                <?php else: ?>
                    <span style="font-size:.78rem;color:var(--text-muted);">Sem informação financeira</span>
                <?php endif; ?>
            </div>

            <!-- Observações e Pendências -->
            <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div>
                    <span class="fl">Observações</span>
                    <div class="fe" contenteditable="true" data-field="observacoes" data-id="<?= $leadId ?>" style="min-height:2.5em;white-space:pre-wrap;font-size:.85rem;"><?= e($lead['observacoes'] ?: $lead['notes'] ?: 'Clique para adicionar...') ?></div>
                    <span class="sv" id="save-observacoes">Salvo!</span>
                </div>
                <div>
                    <span class="fl">Pendências</span>
                    <div class="fe" contenteditable="true" data-field="pendencias" data-id="<?= $leadId ?>" style="min-height:2.5em;white-space:pre-wrap;font-size:.85rem;<?= $isInadimplente ? 'color:#dc2626;font-weight:700;' : '' ?>"><?= e($lead['pendencias'] ?: 'Clique para adicionar...') ?></div>
                    <span class="sv" id="save-pendencias">Salvo!</span>
                </div>
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
                    <span><?= e($val) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Ações Rápidas -->
    <div class="card">
        <div class="card-header"><h3>Ações Rápidas</h3></div>
        <div class="card-body" style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <a href="<?= module_url('documentos', 'index.php?client_id=' . ($lead['client_id'] ?: 0)) ?>" class="btn btn-outline btn-sm">📜 Elaborar Documento</a>
            <?php if ($lead['linked_case_id']): ?>
                <a href="<?= module_url('peticoes', 'index.php?case_id=' . $lead['linked_case_id']) ?>" class="btn btn-outline btn-sm" style="color:#B87333;border-color:#B87333;">📝 Fábrica de Petições</a>
            <?php endif; ?>
            <form method="POST" action="<?= module_url('pipeline', 'api.php') ?>" style="display:inline;" data-confirm="Criar outra ação para este cliente?">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="duplicate">
                <input type="hidden" name="lead_id" value="<?= $leadId ?>">
                <button type="submit" class="btn btn-outline btn-sm">📋 + Nova Ação (duplicar)</button>
            </form>
            <form method="POST" action="<?= module_url('pipeline', 'api.php') ?>" style="display:inline;" data-confirm="Excluir este lead permanentemente?">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="lead_id" value="<?= $leadId ?>">
                <button type="submit" class="btn btn-outline btn-sm" style="color:#dc2626;border-color:#dc2626;">🗑️ Excluir Lead</button>
            </form>
        </div>
    </div>

    <!-- Agendamento e Onboard -->
    <div class="card">
        <div class="card-header"><h3>📅 Agendamento / Onboard</h3></div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem;align-items:end;">
                <div>
                    <span class="fl">Data do agendamento</span>
                    <input type="date" id="data_agendamento" value="<?= e($lead['data_agendamento'] ?? '') ?>" onchange="saveField('data_agendamento',this.value,<?= $leadId ?>)" style="width:100%;padding:5px 8px;font-size:.85rem;border:1.5px solid var(--border);border-radius:8px;">
                    <span class="sv" id="save-data_agendamento">Salvo!</span>
                </div>
                <div>
                    <span class="fl">Onboard realizado</span>
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.88rem;padding:5px 0;">
                        <input type="checkbox" <?= ($lead['onboard_realizado'] ?? 0) ? 'checked' : '' ?> onchange="saveField('onboard_realizado',this.checked?1:0,<?= $leadId ?>)" style="width:18px;height:18px;">
                        <?= ($lead['onboard_realizado'] ?? 0) ? '<span style="color:var(--success);font-weight:700;">Sim ✓</span>' : '<span style="color:var(--text-muted);">Não</span>' ?>
                    </label>
                    <span class="sv" id="save-onboard_realizado">Salvo!</span>
                </div>
                <div>
                    <span class="fl">Origem do lead</span>
                    <select onchange="saveField('origem_lead',this.value,<?= $leadId ?>)" style="width:100%;padding:5px 8px;font-size:.82rem;border:1.5px solid var(--border);border-radius:8px;background:var(--bg-card);">
                        <option value="">—</option>
                        <?php foreach (array('trafego_pago'=>'Tráfego Pago','indicacao'=>'Indicação','ltv'=>'LTV','instagram'=>'Instagram','whatsapp'=>'WhatsApp','google'=>'Google','formulario'=>'Formulário') as $k=>$v): ?>
                            <option value="<?= $k ?>" <?= ($lead['origem_lead'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="sv" id="save-origem_lead">Salvo!</span>
                </div>
            </div>
        </div>
    </div>

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

document.querySelectorAll('.fe[contenteditable]').forEach(function(el) {
    var original = el.textContent.trim();
    el.addEventListener('blur', function() {
        var newVal = this.textContent.trim();
        if (newVal === original || (newVal === 'Clique para adicionar...' && !original)) return;
        if (newVal === '—') newVal = '';
        saveField(this.dataset.field, newVal, this.dataset.id);
        original = newVal;
    });
    el.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && this.dataset.field !== 'observacoes' && this.dataset.field !== 'pendencias') { e.preventDefault(); this.blur(); }
        if (e.key === 'Escape') { this.textContent = original; this.blur(); }
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
        if (indicator) { indicator.classList.add('show'); setTimeout(function() { indicator.classList.remove('show'); }, 1500); }
    };
    xhr.send(formData);
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
