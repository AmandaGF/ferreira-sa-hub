<?php
/**
 * Ferreira & Sá Hub — Revisar Financeiro dos Leads
 * Lista leads em estágios pós-contrato com campos financeiros pendentes
 * (honorários, forma de pagamento, vencimento, êxito, nome da pasta)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_once __DIR__ . '/../../core/functions_pipeline.php';
require_access('pipeline');

$pdo = db();
$pageTitle = 'Revisar Financeiro dos Leads';

// Estágios que DEVERIAM ter dados financeiros preenchidos
$stagesPost = array('contrato_assinado','agendado_docs','reuniao_cobranca','doc_faltante','pasta_apta','finalizado');
$inClause = "'" . implode("','", $stagesPost) . "'";

// Filtros
$fStage = $_GET['stage'] ?? '';
$fResp  = (int)($_GET['resp'] ?? 0);
$busca  = trim($_GET['q'] ?? '');
$soPendentes = !isset($_GET['todos']); // default: só os pendentes

$where = "l.stage IN ({$inClause}) AND l.arquivado_em IS NULL";
$params = array();
if ($fStage && in_array($fStage, $stagesPost)) {
    $where .= " AND l.stage = ?";
    $params[] = $fStage;
}
if ($fResp) {
    $where .= " AND l.assigned_to = ?";
    $params[] = $fResp;
}
if ($busca !== '') {
    $where .= " AND (l.name LIKE ? OR l.phone LIKE ?)";
    $params[] = "%{$busca}%";
    $params[] = "%{$busca}%";
}
if ($soPendentes) {
    $where .= " AND (
        (l.honorarios_cents IS NULL OR l.honorarios_cents = 0)
        AND (l.valor_acao IS NULL OR l.valor_acao = '' OR l.valor_acao = '0')
        OR (l.forma_pagamento IS NULL OR l.forma_pagamento = '')
        OR (l.vencimento_parcela IS NULL OR l.vencimento_parcela = '')
        OR (l.exito_percentual IS NULL OR l.exito_percentual = '' OR l.exito_percentual = '0')
    )";
}

$sql = "SELECT l.id, l.name, l.phone, l.stage, l.case_type, l.assigned_to,
               l.honorarios_cents, l.valor_acao, l.exito_percentual,
               l.vencimento_parcela, l.forma_pagamento, l.urgencia,
               l.nome_pasta, l.observacoes, l.updated_at,
               u.name AS resp_name
        FROM pipeline_leads l
        LEFT JOIN users u ON u.id = l.assigned_to
        WHERE {$where}
        ORDER BY l.updated_at DESC
        LIMIT 300";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$leads = $stmt->fetchAll();

// Totais
$totPost = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE stage IN ({$inClause}) AND arquivado_em IS NULL")->fetchColumn();
$totPendentes = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE stage IN ({$inClause}) AND arquivado_em IS NULL AND (
    (honorarios_cents IS NULL OR honorarios_cents = 0) AND (valor_acao IS NULL OR valor_acao = '' OR valor_acao = '0')
    OR (forma_pagamento IS NULL OR forma_pagamento = '')
    OR (vencimento_parcela IS NULL OR vencimento_parcela = '')
    OR (exito_percentual IS NULL OR exito_percentual = '' OR exito_percentual = '0')
)")->fetchColumn();
$totOk = $totPost - $totPendentes;
$pctOk = $totPost ? round($totOk / $totPost * 100) : 0;

$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

$stagesLabel = array(
    'contrato_assinado' => 'Contrato Assinado',
    'agendado_docs'     => 'Agendado Docs',
    'reuniao_cobranca'  => 'Reunião/Cobrança',
    'doc_faltante'      => 'Doc Faltante',
    'pasta_apta'        => 'Pasta Apta',
    'finalizado'        => 'Finalizado',
);
$stagesColor = array(
    'contrato_assinado' => '#3b82f6',
    'agendado_docs'     => '#f59e0b',
    'reuniao_cobranca'  => '#ef4444',
    'doc_faltante'      => '#dc2626',
    'pasta_apta'        => '#8b5cf6',
    'finalizado'        => '#22c55e',
);

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.rf-stats { display:grid;grid-template-columns:repeat(auto-fit, minmax(180px,1fr));gap:.7rem;margin-bottom:1rem; }
.rf-stat { background:#fff;border:1px solid var(--border);border-radius:10px;padding:.9rem 1rem; }
.rf-stat-num { font-size:1.8rem;font-weight:700;color:var(--petrol-900);line-height:1; }
.rf-stat-label { font-size:.75rem;color:var(--text-muted);margin-top:4px; }
.rf-progress-bar { height:6px;background:#f3f4f6;border-radius:3px;margin-top:8px;overflow:hidden; }
.rf-progress-fill { height:100%;background:linear-gradient(90deg, #22c55e, #16a34a);transition:width .3s; }

.rf-filters { background:#fff;border:1px solid var(--border);border-radius:10px;padding:.7rem 1rem;margin-bottom:.8rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center; }
.rf-filters select, .rf-filters input { font-size:.8rem; }

.rf-row { background:#fff;border:1px solid var(--border);border-radius:10px;padding:.8rem 1rem;margin-bottom:.5rem; }
.rf-row.pending { border-left:4px solid #f59e0b; }
.rf-row.complete { border-left:4px solid #22c55e;opacity:.75; }
.rf-row-head { display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;margin-bottom:.5rem; }
.rf-name { font-weight:700;font-size:.9rem;color:var(--petrol-900); }
.rf-stage { padding:2px 8px;border-radius:10px;font-size:.65rem;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.3px; }
.rf-meta { font-size:.72rem;color:var(--text-muted);margin-left:auto; }

.rf-fields { display:grid;grid-template-columns:1.2fr 1.5fr 0.8fr 1.2fr 1.5fr 2fr;gap:.5rem;align-items:end; }
.rf-field label { display:block;font-size:.68rem;font-weight:600;color:var(--text-muted);margin-bottom:2px;text-transform:uppercase;letter-spacing:.3px; }
.rf-field input, .rf-field select, .rf-field textarea { width:100%;font-size:.8rem;padding:6px 8px;border:1px solid var(--border);border-radius:6px;font-family:inherit; }
.rf-field .saved-flash { background:#dcfce7 !important;border-color:#22c55e !important; }
.rf-field .save-error { background:#fee2e2 !important;border-color:#ef4444 !important; }
.rf-field textarea { resize:vertical;min-height:32px; }

.rf-empty { text-align:center;padding:3rem 1rem;color:var(--text-muted); }
.rf-empty-big { font-size:3rem;margin-bottom:.5rem; }

@media (max-width:900px){ .rf-fields{grid-template-columns:1fr 1fr;} }
</style>

<a href="<?= module_url('pipeline') ?>" class="btn btn-outline btn-sm mb-2">&larr; Voltar ao Kanban Comercial</a>

<div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem;">
    <h1 style="margin:0;">💰 Revisar Financeiro dos Leads</h1>
</div>

<p class="text-sm text-muted" style="margin-bottom:1rem;">Leads em estágios pós-contrato com <strong>dados financeiros pendentes</strong> (honorários, forma de pagamento, vencimento, êxito %). Edite diretamente — salva sozinho ao sair do campo. Campos com <span style="color:#22c55e;">✓ verde</span> foram salvos. <span style="color:#ef4444;">⚠️ vermelho</span> = erro (recarregue F5).</p>

<div class="rf-stats">
    <div class="rf-stat">
        <div class="rf-stat-num"><?= $totPost ?></div>
        <div class="rf-stat-label">Total pós-contrato</div>
    </div>
    <div class="rf-stat" style="border-color:#f59e0b;">
        <div class="rf-stat-num" style="color:#f59e0b;"><?= $totPendentes ?></div>
        <div class="rf-stat-label">⚠️ Pendentes de revisão</div>
    </div>
    <div class="rf-stat" style="border-color:#22c55e;">
        <div class="rf-stat-num" style="color:#22c55e;"><?= $totOk ?></div>
        <div class="rf-stat-label">✅ Completos</div>
        <div class="rf-progress-bar"><div class="rf-progress-fill" style="width:<?= $pctOk ?>%;"></div></div>
    </div>
    <div class="rf-stat">
        <div class="rf-stat-num" style="color:#22c55e;"><?= $pctOk ?>%</div>
        <div class="rf-stat-label">Progresso de revisão</div>
    </div>
</div>

<form method="GET" class="rf-filters">
    <input type="text" name="q" value="<?= e($busca) ?>" placeholder="🔍 Buscar nome ou telefone..." class="form-control" style="max-width:240px;">
    <select name="stage" class="form-control" style="max-width:180px;">
        <option value="">Todos os estágios</option>
        <?php foreach ($stagesLabel as $k => $v): ?>
            <option value="<?= $k ?>" <?= $fStage === $k ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="resp" class="form-control" style="max-width:200px;">
        <option value="0">Todos responsáveis</option>
        <?php foreach ($users as $u): ?>
            <option value="<?= $u['id'] ?>" <?= $fResp === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <label style="display:flex;align-items:center;gap:4px;font-size:.8rem;">
        <input type="checkbox" name="todos" value="1" <?= !$soPendentes ? 'checked' : '' ?>>
        Mostrar completos também
    </label>
    <button type="submit" class="btn btn-primary btn-sm">Aplicar</button>
    <?php if ($fStage || $fResp || $busca || !$soPendentes): ?>
        <a href="<?= module_url('pipeline', 'revisar_financeiro.php') ?>" class="btn btn-outline btn-sm">Limpar</a>
    <?php endif; ?>
</form>

<?php if (empty($leads)): ?>
    <div class="rf-empty">
        <div class="rf-empty-big"><?= $soPendentes ? '🎉' : '📭' ?></div>
        <?php if ($soPendentes): ?>
            <strong>Parabéns!</strong> Nenhum lead pendente de revisão financeira nos filtros aplicados.
        <?php else: ?>
            <strong>Nenhum lead encontrado</strong> nos filtros aplicados.
        <?php endif; ?>
    </div>
<?php else: ?>

<?php foreach ($leads as $l):
    $isPending = (
        (empty($l['honorarios_cents']) && (empty($l['valor_acao']) || $l['valor_acao'] === '0'))
        || empty($l['forma_pagamento'])
        || empty($l['vencimento_parcela'])
        || empty($l['exito_percentual']) || $l['exito_percentual'] === '0'
    );
    $honorValue = $l['honorarios_cents'] ? number_format($l['honorarios_cents']/100, 2, ',', '.') : ($l['valor_acao'] ?? '');
?>
    <div class="rf-row <?= $isPending ? 'pending' : 'complete' ?>" data-id="<?= $l['id'] ?>">
        <div class="rf-row-head">
            <span class="rf-name"><?= e($l['name']) ?></span>
            <span class="rf-stage" style="background:<?= $stagesColor[$l['stage']] ?? '#6b7280' ?>;"><?= e($stagesLabel[$l['stage']] ?? $l['stage']) ?></span>
            <span style="font-size:.75rem;color:var(--text-muted);"><?= e($l['case_type']) ?: '—' ?></span>
            <span class="rf-meta">
                <?= e($l['phone']) ?> ·
                <?= $l['resp_name'] ? e($l['resp_name']) : 'sem resp.' ?> ·
                atualizado <?= date('d/m/Y H:i', strtotime($l['updated_at'])) ?>
            </span>
            <a href="<?= module_url('pipeline', 'lead_ver.php?id=' . $l['id']) ?>" target="_blank" style="font-size:.72rem;color:var(--rose);">ver lead →</a>
        </div>
        <div class="rf-fields">
            <div class="rf-field">
                <label>Honorários (R$)</label>
                <input type="text" value="<?= e($honorValue) ?>" data-id="<?= $l['id'] ?>" data-field="valor_acao" onblur="saveFieldHon(this)" placeholder="0,00">
            </div>
            <div class="rf-field">
                <label>Forma de Pagamento</label>
                <input type="text" value="<?= e($l['forma_pagamento'] ?? '') ?>" data-id="<?= $l['id'] ?>" data-field="forma_pagamento" onblur="saveField(this)" placeholder="Ex: 3x no cartão">
            </div>
            <div class="rf-field">
                <label>Êxito %</label>
                <input type="number" value="<?= e($l['exito_percentual'] ?? '') ?>" data-id="<?= $l['id'] ?>" data-field="exito_percentual" onblur="saveField(this)" placeholder="0" min="0" max="100">
            </div>
            <div class="rf-field">
                <label>Vencto 1ª parcela</label>
                <input type="text" value="<?= e($l['vencimento_parcela'] ?? '') ?>" data-id="<?= $l['id'] ?>" data-field="vencimento_parcela" onblur="saveField(this)" placeholder="Ex: 10/05">
            </div>
            <div class="rf-field">
                <label>Nome da Pasta</label>
                <input type="text" value="<?= e($l['nome_pasta'] ?? '') ?>" data-id="<?= $l['id'] ?>" data-field="nome_pasta" onblur="saveField(this)" placeholder="Nome do cliente + ação">
            </div>
            <div class="rf-field">
                <label>Observações</label>
                <textarea data-id="<?= $l['id'] ?>" data-field="observacoes" onblur="saveField(this)" rows="1"><?= e($l['observacoes'] ?? '') ?></textarea>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php endif; ?>

<script>
var CSRF = '<?= generate_csrf_token() ?>';
var API_URL = '<?= module_url("pipeline", "api.php") ?>';

function saveField(el) {
    var id = el.dataset.id;
    var field = el.dataset.field;
    var value = el.value;
    var fd = new FormData();
    fd.append('action', 'inline_edit');
    fd.append('lead_id', id);
    fd.append('field', field);
    fd.append('value', value);
    fd.append('csrf_token', CSRF);

    el.classList.remove('saved-flash', 'save-error');
    var xhr = new XMLHttpRequest();
    xhr.open('POST', API_URL);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onload = function() {
        var ok = false, msg = '';
        try {
            var r = JSON.parse(xhr.responseText);
            if (r && r.ok) ok = true;
            else msg = r.error || 'Falha desconhecida';
        } catch(e) {
            msg = 'Resposta inválida (sessão pode ter expirado — recarregue F5).';
        }
        if (ok) {
            el.classList.add('saved-flash');
            setTimeout(function(){ el.classList.remove('saved-flash'); }, 1500);
        } else {
            el.classList.add('save-error');
            el.title = '⚠️ ' + msg;
            if (!window._rfErrShown) { window._rfErrShown = true; alert('⚠️ Falha ao salvar: ' + msg); setTimeout(function(){ window._rfErrShown=false; }, 5000); }
        }
    };
    xhr.onerror = function() { el.classList.add('save-error'); };
    xhr.send(fd);
}

function saveFieldHon(el) {
    var raw = el.value.replace(/[^\d.,]/g, '');
    if (raw) {
        var num = parseFloat(raw.replace(/\./g, '').replace(',', '.'));
        if (!isNaN(num) && num > 0) {
            el.value = num.toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2});
        }
    }
    saveField(el);
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
