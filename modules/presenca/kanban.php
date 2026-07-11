<?php
/**
 * Presença — Fila de Envios (Kanban).
 * 6 colunas: Sugerido → Aprovado → Em produção → Enviado → Entregue + Cancelado.
 * Drag-drop pra mudar status. Ao mover pra Enviado, abre modal pra registrar
 * fornecedor + custo real + rastreio (obrigatório).
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('presenca');

$pdo = db();
$pageTitle = 'Presença — Fila de Envios';

// Filtros
$filtroPerfil = isset($_GET['perfil']) ? (int)$_GET['perfil'] : 0;
$filtroFase   = isset($_GET['fase']) ? (int)$_GET['fase'] : 0;

$w = array("1=1"); $params = array();
if ($filtroPerfil > 0) { $w[] = "e.perfil_id = ?"; $params[] = $filtroPerfil; }
if ($filtroFase > 0)   { $w[] = "e.fase_id = ?";   $params[] = $filtroFase; }

$sql = "SELECT e.*, c.name AS cli_nome, p.nome AS perfil_nome, p.cor_hex AS perfil_cor,
               f.nome AS fase_nome, b.nome AS brinde_nome, b.eh_kit
        FROM presenca_envio e
        LEFT JOIN clients c ON c.id = e.cliente_id
        LEFT JOIN presenca_perfil p ON p.id = e.perfil_id
        LEFT JOIN presenca_fase f ON f.id = e.fase_id
        LEFT JOIN presenca_brinde b ON b.id = e.brinde_id
        WHERE " . implode(' AND ', $w) . "
        ORDER BY e.status, e.data_pedido_limite ASC, e.id ASC";
$st = $pdo->prepare($sql); $st->execute($params);
$envios = $st->fetchAll(PDO::FETCH_ASSOC);

$colunas = array(
    'sugerido'    => array('lbl'=>'💡 Sugerido',    'cor'=>'#f5ede3', 'txt'=>'#78350f'),
    'aprovado'    => array('lbl'=>'✅ Aprovado',    'cor'=>'#dcfce7', 'txt'=>'#15803d'),
    'em_producao' => array('lbl'=>'⚙️ Em produção', 'cor'=>'#e0f2fe', 'txt'=>'#0369a1'),
    'enviado'     => array('lbl'=>'📮 Enviado',     'cor'=>'#dbeafe', 'txt'=>'#1e40af'),
    'entregue'    => array('lbl'=>'🎁 Entregue',    'cor'=>'#f3e8ff', 'txt'=>'#7c3aed'),
    'cancelado'   => array('lbl'=>'✕ Cancelado',   'cor'=>'#fee2e2', 'txt'=>'#7f1d1d'),
);
$porStatus = array(); foreach (array_keys($colunas) as $s) $porStatus[$s] = array();
foreach ($envios as $e) if (isset($porStatus[$e['status']])) $porStatus[$e['status']][] = $e;

$perfisPresenca = $pdo->query("SELECT id, nome FROM presenca_perfil WHERE ativo=1 ORDER BY ordem")->fetchAll(PDO::FETCH_ASSOC);
$fases  = $pdo->query("SELECT id, nome FROM presenca_fase WHERE ativo=1 ORDER BY ordem")->fetchAll(PDO::FETCH_ASSOC);
$fornecs = $pdo->query("SELECT id, nome FROM presenca_fornecedor WHERE ativo=1 ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

$csrf = generate_csrf_token();
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.pk-hero { display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px; }
.pk-hero h1 { margin:0; font-family:'Cormorant Garamond',Georgia,serif; font-size:1.6rem; font-weight:600; color:#0E2E36; }
.pk-back { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; text-decoration:none; color:#0E2E36; font-size:.78rem; font-weight:600; }

.pk-filtros { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:10px 14px; margin-bottom:12px; display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
.pk-filtros label { font-size:.75rem; color:#6b7280; font-weight:700; text-transform:uppercase; letter-spacing:.03em; }
.pk-filtros select { border:1px solid #e5e7eb; border-radius:6px; padding:5px 10px; font-size:.85rem; font-family:inherit; }

.pk-board { display:grid; grid-template-columns:repeat(6, minmax(230px, 1fr)); gap:10px; overflow-x:auto; padding-bottom:8px; }
.pk-col { background:#fafafa; border:1.5px solid #e5e7eb; border-radius:10px; padding:8px; min-height:400px; }
.pk-col.drag-over { border-color:#B87333; background:#fff7ed; }
.pk-col h4 { margin:0 0 8px; padding:6px 10px; font-size:.78rem; font-weight:800; text-align:center; border-radius:6px; }

.pk-card { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:10px 12px; margin-bottom:8px; cursor:grab; box-shadow:0 1px 2px rgba(0,0,0,.04); transition:box-shadow .12s; }
.pk-card:hover { box-shadow:0 3px 8px rgba(0,0,0,.1); }
.pk-card.dragging { opacity:.4; cursor:grabbing; }
.pk-card.bloqueado { border-left:4px solid #dc2626; background:linear-gradient(90deg,#fef2f2,#fff); }
.pk-card .cli { font-weight:800; color:#0E2E36; font-size:.85rem; margin-bottom:3px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.pk-card .meta { display:flex; gap:4px; margin-bottom:4px; flex-wrap:wrap; }
.pk-card .perfil-tag { color:#fff; padding:1px 6px; border-radius:999px; font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:.02em; }
.pk-card .fase-tag { background:#f5ede3; color:#78350f; padding:1px 6px; border-radius:999px; font-size:.6rem; font-weight:600; }
.pk-card .brinde { font-size:.72rem; color:#374151; margin-bottom:3px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.pk-card .custo { font-size:.72rem; color:#0E2E36; font-weight:700; }
.pk-card .prazo { font-size:.65rem; color:#6b7280; margin-top:3px; }
.pk-card .prazo.urg { color:#dc2626; font-weight:700; }
.pk-card .rastr { font-size:.65rem; color:#0369a1; margin-top:2px; font-family:'JetBrains Mono',Consolas,monospace; }

.pk-empty { color:#9ca3af; font-size:.75rem; text-align:center; padding:20px 8px; font-style:italic; }

/* Modal registrar envio */
.pk-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9999; align-items:center; justify-content:center; padding:20px; }
.pk-modal.show { display:flex; }
.pk-modal-body { background:#fff; border-radius:14px; max-width:520px; width:100%; padding:24px; }
.pk-modal h3 { margin:0 0 14px; font-family:'Cormorant Garamond',Georgia,serif; font-size:1.4rem; color:#0E2E36; }
.pk-modal label { display:block; font-size:.72rem; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.03em; margin-bottom:4px; margin-top:10px; }
.pk-modal input, .pk-modal select { width:100%; border:1.5px solid #e5e7eb; border-radius:8px; padding:8px 10px; font-size:.9rem; font-family:inherit; }
.pk-modal .actions { display:flex; gap:8px; margin-top:20px; justify-content:flex-end; }
.pk-modal button { border:none; border-radius:8px; padding:10px 20px; font-size:.85rem; font-weight:700; cursor:pointer; }
.pk-modal .primary { background:#15803d; color:#fff; }
.pk-modal .link { background:#fff; color:#0E2E36; border:1px solid #d1d5db; }
</style>

<div class="pk-hero">
    <div>
        <h1>📋 Fila de Envios</h1>
        <div style="font-size:.85rem;color:#6b7280;margin-top:4px;">Arraste os cards entre colunas. Ao chegar em <strong>Enviado</strong>, você registra fornecedor + rastreio.</div>
    </div>
    <div style="display:flex;gap:6px;">
        <a href="<?= module_url('presenca','aprovacao.php') ?>" class="pk-back">✅ Bandeja de aprovação</a>
        <a href="<?= module_url('presenca') ?>" class="pk-back">← Voltar</a>
    </div>
</div>

<form class="pk-filtros" method="GET">
    <label>Perfil:</label>
    <select name="perfil" onchange="this.form.submit()">
        <option value="0">Todos</option>
        <?php foreach ($perfisPresenca as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $filtroPerfil===(int)$p['id']?'selected':'' ?>><?= e($p['nome']) ?></option><?php endforeach; ?>
    </select>
    <label>Fase:</label>
    <select name="fase" onchange="this.form.submit()">
        <option value="0">Todas</option>
        <?php foreach ($fases as $f): ?><option value="<?= (int)$f['id'] ?>" <?= $filtroFase===(int)$f['id']?'selected':'' ?>><?= e($f['nome']) ?></option><?php endforeach; ?>
    </select>
    <span style="margin-left:auto;font-size:.75rem;color:#6b7280;"><?= count($envios) ?> envio(s)</span>
</form>

<div class="pk-board">
    <?php foreach ($colunas as $slug => $c): ?>
    <div class="pk-col" data-status="<?= $slug ?>" ondragover="pkOver(event)" ondragleave="pkLeave(event)" ondrop="pkDrop(event)">
        <h4 style="background:<?= $c['cor'] ?>;color:<?= $c['txt'] ?>;"><?= $c['lbl'] ?> · <?= count($porStatus[$slug]) ?></h4>
        <?php foreach ($porStatus[$slug] as $e):
            $dias = $e['data_pedido_limite'] ? (int)floor((strtotime($e['data_pedido_limite']) - time()) / 86400) : null;
            $urg = ($slug === 'sugerido' || $slug === 'aprovado') && $dias !== null && $dias <= 3;
        ?>
        <div class="pk-card <?= !empty($e['bloqueado'])?'bloqueado':'' ?>" draggable="true" data-id="<?= (int)$e['id'] ?>" ondragstart="pkStart(event)" ondragend="pkEnd(event)" ondblclick="pkAbrir(<?= (int)$e['id'] ?>)">
            <div class="cli"><?= e($e['cli_nome'] ?: '(cliente removido)') ?></div>
            <div class="meta">
                <span class="perfil-tag" style="background: <?= e($e['perfil_cor']) ?>;"><?= e($e['perfil_nome']) ?></span>
                <span class="fase-tag"><?= e($e['fase_nome']) ?></span>
            </div>
            <div class="brinde">🎁 <?= e($e['brinde_nome'] ?: '(sem brinde)') ?><?php if (!empty($e['eh_kit'])): ?> · KIT<?php endif; ?></div>
            <div class="custo">R$ <?= number_format((float)($e['custo_real'] ?? $e['custo_previsto']), 2, ',', '.') ?><?php if ($e['custo_real']): ?> <span style="color:#6b7280;font-weight:400;font-size:.65rem;">(real)</span><?php endif; ?></div>
            <?php if ($slug === 'sugerido' || $slug === 'aprovado'): ?>
                <?php if ($e['data_pedido_limite']): ?><div class="prazo <?= $urg?'urg':'' ?>">📅 Pedir: <?= date('d/m', strtotime($e['data_pedido_limite'])) ?><?= $dias!==null?' ('.$dias.'d)':'' ?></div><?php endif; ?>
            <?php elseif ($slug === 'em_producao' || $slug === 'enviado' || $slug === 'entregue'): ?>
                <?php if ($e['data_alvo']): ?><div class="prazo">🎯 Alvo: <?= date('d/m', strtotime($e['data_alvo'])) ?></div><?php endif; ?>
                <?php if ($e['rastreio']): ?><div class="rastr">📮 <?= e($e['rastreio']) ?></div><?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($e['bloqueado'])): ?><div style="font-size:.65rem;color:#dc2626;font-weight:700;margin-top:3px;">🔒 Bloqueado</div><?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (empty($porStatus[$slug])): ?><div class="pk-empty">nada aqui</div><?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- Modal registrar envio -->
<div class="pk-modal" id="pkModalEnviado">
    <div class="pk-modal-body">
        <h3>📮 Registrar envio</h3>
        <div style="font-size:.85rem;color:#6b7280;">Preencha os dados do envio pra este presente. Ao salvar, o estoque é baixado automaticamente.</div>
        <input type="hidden" id="mvEnvioId">
        <label>Data do envio</label>
        <input type="date" id="mvDataEnvio" value="<?= date('Y-m-d') ?>">
        <label>Fornecedor</label>
        <select id="mvFornecedor">
            <option value="">— nenhum —</option>
            <?php foreach ($fornecs as $fn): ?><option value="<?= (int)$fn['id'] ?>"><?= e($fn['nome']) ?></option><?php endforeach; ?>
        </select>
        <label>Custo real (R$)</label>
        <input type="text" id="mvCustoReal" placeholder="0,00">
        <label>Código de rastreio</label>
        <input type="text" id="mvRastreio" placeholder="Ex: BR1234567890">
        <div class="actions">
            <button class="link" onclick="pkModalFechar()">Cancelar</button>
            <button class="primary" onclick="pkModalSalvar()">💾 Salvar e mover</button>
        </div>
    </div>
</div>

<script>
var pkCsrf = '<?= $csrf ?>';
var pkDraggingId = null;

function pkStart(ev) { pkDraggingId = ev.currentTarget.dataset.id; ev.currentTarget.classList.add('dragging'); }
function pkEnd(ev)   { ev.currentTarget.classList.remove('dragging'); }
function pkOver(ev)  { ev.preventDefault(); ev.currentTarget.classList.add('drag-over'); }
function pkLeave(ev) { ev.currentTarget.classList.remove('drag-over'); }

function pkDrop(ev) {
    ev.preventDefault();
    ev.currentTarget.classList.remove('drag-over');
    var novoStatus = ev.currentTarget.dataset.status;
    var id = pkDraggingId; pkDraggingId = null;
    if (!id) return;
    if (novoStatus === 'enviado') { pkModalAbrir(id); return; }
    pkMudar(id, novoStatus);
}

function pkMudar(id, novo, extras) {
    var fd = new FormData();
    fd.append('acao', 'mudar_status'); fd.append('envio_id', id); fd.append('novo_status', novo);
    fd.append('csrf_token', pkCsrf);
    if (extras) Object.keys(extras).forEach(function(k){ fd.append(k, extras[k]); });
    fetch('<?= module_url('presenca','api.php') ?>', { method:'POST', body:fd, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'} })
        .then(function(r){ return r.json(); })
        .then(function(j) {
            if (j.csrf) pkCsrf = j.csrf;
            if (j.ok) location.reload();
            else {
                if (String(j.erro||'').indexOf('bloqueado') !== -1) {
                    if (confirm('Este envio está BLOQUEADO por restrição. Confirmar que o endereço já foi validado e forçar a mudança?')) {
                        pkMudar(id, novo, {forca_desbloqueio: 1});
                    }
                } else alert('Erro: ' + (j.erro || '?'));
            }
        });
}

function pkModalAbrir(id) {
    document.getElementById('mvEnvioId').value = id;
    document.getElementById('pkModalEnviado').classList.add('show');
}
function pkModalFechar() { document.getElementById('pkModalEnviado').classList.remove('show'); }
function pkModalSalvar() {
    var id = document.getElementById('mvEnvioId').value;
    var fd = new FormData();
    fd.append('acao','registrar_enviado'); fd.append('envio_id', id);
    fd.append('data_envio', document.getElementById('mvDataEnvio').value);
    fd.append('fornecedor_id', document.getElementById('mvFornecedor').value);
    fd.append('custo_real', document.getElementById('mvCustoReal').value);
    fd.append('rastreio', document.getElementById('mvRastreio').value);
    fd.append('csrf_token', pkCsrf);
    fetch('<?= module_url('presenca','api.php') ?>', { method:'POST', body:fd, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'} })
        .then(function(r){ return r.json(); })
        .then(function(j) {
            if (j.csrf) pkCsrf = j.csrf;
            if (j.ok) location.reload();
            else alert('Erro: ' + (j.erro || '?'));
        });
}

// Duplo clique = abre modal ou detalhe (por enquanto, alerta com resumo — futuro: tela detalhe)
function pkAbrir(id) { /* futuro: modal detalhe */ }
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
