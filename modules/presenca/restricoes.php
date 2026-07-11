<?php
/**
 * Presença — Restrições de Sensibilidade.
 * Marca cliente/caso como 'nao_enviar' ou 'confirmar_endereco'.
 * DEVER DE CUIDADO: em Família, mandar presente pra casa de cliente em
 * medida protetiva ou divórcio litigioso é risco real. Esta tabela suprime
 * ou condiciona o envio. Nenhum envio automático fura restrição.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('presenca');

$pdo = db();
$pageTitle = 'Presença — Restrições de Sensibilidade';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) { flash_set('error','Sessão expirada.'); redirect(module_url('presenca','restricoes.php')); }
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'salvar') {
        $id       = (int)($_POST['id'] ?? 0);
        $cliId    = (int)($_POST['cliente_id'] ?? 0);
        $procId   = (int)($_POST['processo_id'] ?? 0) ?: null;
        $nivel    = in_array($_POST['nivel'] ?? '', array('nao_enviar','confirmar_endereco')) ? $_POST['nivel'] : 'nao_enviar';
        $motivo   = clean_str($_POST['motivo'] ?? '', 255);
        $ativo    = !empty($_POST['ativo']) ? 1 : 0;
        $userId   = current_user_id();
        if (!$cliId) { flash_set('error','Cliente obrigatório.'); redirect(module_url('presenca','restricoes.php')); }
        if ($id > 0) {
            $pdo->prepare("UPDATE presenca_restricao SET cliente_id=?, processo_id=?, nivel=?, motivo=?, ativo=? WHERE id=?")
                ->execute(array($cliId,$procId,$nivel,$motivo,$ativo,$id));
            audit_log('presenca_restricao_edit','presenca_restricao',$id,"cli=$cliId nivel=$nivel");
        } else {
            $pdo->prepare("INSERT INTO presenca_restricao (cliente_id,processo_id,nivel,motivo,criado_por,ativo) VALUES (?,?,?,?,?,?)")
                ->execute(array($cliId,$procId,$nivel,$motivo,$userId,$ativo));
            audit_log('presenca_restricao_new','presenca_restricao',(int)$pdo->lastInsertId(),"cli=$cliId nivel=$nivel");
        }
        flash_set('success','Restrição salva.');
        redirect(module_url('presenca','restricoes.php'));
    }
    if ($acao === 'toggle_ativo') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) $pdo->prepare("UPDATE presenca_restricao SET ativo = 1 - ativo WHERE id = ?")->execute(array($id));
        redirect(module_url('presenca','restricoes.php'));
    }
    if ($acao === 'excluir') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) { $pdo->prepare("DELETE FROM presenca_restricao WHERE id = ?")->execute(array($id)); audit_log('presenca_restricao_del','presenca_restricao',$id,''); }
        redirect(module_url('presenca','restricoes.php'));
    }
}

// AJAX autocompletar cliente
if (($_GET['ajax'] ?? '') === 'buscar_cliente') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) { echo '[]'; exit; }
    $st = $pdo->prepare("SELECT id, name, phone FROM clients WHERE name LIKE ? ORDER BY name LIMIT 12");
    $st->execute(array('%' . $q . '%'));
    echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
    exit;
}
if (($_GET['ajax'] ?? '') === 'buscar_casos') {
    header('Content-Type: application/json; charset=utf-8');
    $cid = (int)($_GET['client_id'] ?? 0);
    if (!$cid) { echo '[]'; exit; }
    $st = $pdo->prepare("SELECT id, title, case_number FROM cases WHERE client_id = ? ORDER BY id DESC LIMIT 30");
    $st->execute(array($cid));
    echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

$restr = $pdo->query("SELECT r.*, c.name AS cli_nome, cs.title AS caso_titulo, u.name AS user_nome
                     FROM presenca_restricao r
                     LEFT JOIN clients c ON c.id = r.cliente_id
                     LEFT JOIN cases cs ON cs.id = r.processo_id
                     LEFT JOIN users u ON u.id = r.criado_por
                     ORDER BY r.ativo DESC, r.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$editar = null;
if (isset($_GET['editar']) && (int)$_GET['editar'] > 0) {
    $st = $pdo->prepare("SELECT r.*, c.name AS cli_nome FROM presenca_restricao r LEFT JOIN clients c ON c.id = r.cliente_id WHERE r.id = ?");
    $st->execute(array((int)$_GET['editar']));
    $editar = $st->fetch(PDO::FETCH_ASSOC);
}

$csrf = generate_csrf_token();
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.pre-hero { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px; }
.pre-hero h1 { margin:0; font-family:'Cormorant Garamond',Georgia,serif; font-size:1.6rem; font-weight:600; color:#0E2E36; }
.pre-back { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; text-decoration:none; color:#0E2E36; font-size:.78rem; font-weight:600; }

.pre-aviso { background:linear-gradient(135deg,#fef2f2,#fff);border-left:4px solid #dc2626;padding:14px 18px;border-radius:8px;margin-bottom:16px;font-size:.88rem;color:#7f1d1d;line-height:1.5; }

.pre-form { background:#fff; border:1.5px solid #d7ab90; border-radius:12px; padding:20px 24px; margin-bottom:20px; box-shadow:0 4px 12px rgba(215,171,144,.15); }
.pre-form h3 { margin:0 0 14px; font-size:1.05rem; color:#0E2E36; }
.pre-form label { display:block; font-size:.72rem; font-weight:700; color:#6b7280; margin-bottom:4px; text-transform:uppercase; letter-spacing:.03em; }
.pre-form input, .pre-form select, .pre-form textarea { width:100%; border:1.5px solid #e5e7eb; border-radius:8px; padding:8px 10px; font-size:.88rem; font-family:inherit; }
.pre-form input:focus, .pre-form select:focus, .pre-form textarea:focus { outline:none; border-color:#B87333; box-shadow:0 0 0 3px rgba(184,115,51,.15); }
.pre-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; }
.pre-actions { display:flex; gap:8px; margin-top:14px; }
.pre-actions button, .pre-actions .btn-link { background:#0E2E36; color:#fff; border:none; border-radius:8px; padding:9px 18px; font-size:.85rem; font-weight:700; cursor:pointer; text-decoration:none; }
.pre-actions .btn-link { background:#fff; color:#0E2E36; border:1px solid #d1d5db; }

.pre-autocom { position:relative; }
.pre-autocom-box { display:none; position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #e5e7eb; border-radius:0 0 8px 8px; max-height:220px; overflow-y:auto; z-index:20; box-shadow:0 4px 12px rgba(0,0,0,.1); }
.pre-autocom-box div { padding:8px 12px; cursor:pointer; font-size:.85rem; border-bottom:1px solid #f3f4f6; }
.pre-autocom-box div:hover { background:#f5ede3; }

.pre-lista { background:#fff; border-radius:12px; overflow:hidden; border:1px solid #e5e7eb; }
.pre-row { padding:14px 18px; border-bottom:1px solid #f3f4f6; display:grid; grid-template-columns:1.5fr 1fr 160px 130px 130px 150px; gap:12px; align-items:center; }
.pre-row:last-child { border-bottom:none; }
.pre-row.head { background:#fafafa; font-size:.68rem; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; font-weight:700; }
.pre-row.inativo { opacity:.45; }
.pre-row .cli { font-weight:800; color:#0E2E36; font-size:.9rem; }
.pre-row .cli small { color:#6b7280; font-weight:400; font-size:.72rem; }
.pre-row .motivo { font-size:.82rem; color:#374151; font-style:italic; }
.pre-row .nivel-tag { display:inline-block; padding:3px 10px; border-radius:999px; font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
.pre-row .nivel-tag.nao_enviar { background:#fee2e2; color:#7f1d1d; }
.pre-row .nivel-tag.confirmar_endereco { background:#fef3c7; color:#78350f; }
.pre-btn { background:#fff; color:#0E2E36; border:1px solid #d1d5db; border-radius:6px; padding:4px 10px; font-size:.7rem; font-weight:700; cursor:pointer; text-decoration:none; }
.pre-btn.warn { color:#dc2626; border-color:#fecaca; }
@media (max-width:900px) { .pre-row { grid-template-columns: 1fr; gap:6px; } .pre-row.head { display:none; } }
</style>

<div class="pre-hero">
    <div>
        <h1>🛡️ Restrições de Sensibilidade</h1>
        <div style="font-size:.85rem;color:#6b7280;margin-top:4px;">Marca cliente/caso como "não enviar" ou "confirmar endereço"</div>
    </div>
    <a href="<?= module_url('presenca') ?>" class="pre-back">← Voltar</a>
</div>

<div class="pre-aviso">
    ⚠️ <strong>Dever de cuidado.</strong> Em Família, mandar presente pra casa de cliente em <em>medida protetiva</em> ou <em>divórcio litigioso</em> é risco real. Nenhum envio automático fura restrição — sistema respeita esta lista silenciosamente. Não é capricho: é proteção da cliente.
</div>

<div class="pre-form">
    <h3><?= $editar ? '✏️ Editando restrição' : '➕ Nova restrição' ?></h3>
    <form method="POST">
        <?= csrf_input() ?>
        <input type="hidden" name="acao" value="salvar">
        <?php if ($editar): ?><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>"><?php endif; ?>
        <div class="pre-grid">
            <div style="grid-column:span 2;position:relative;">
                <label>Cliente *</label>
                <input type="hidden" name="cliente_id" id="cliId" value="<?= (int)($editar['cliente_id'] ?? 0) ?>">
                <div class="pre-autocom">
                    <input type="text" id="cliBusca" placeholder="Digite o nome do cliente..." value="<?= e($editar['cli_nome'] ?? '') ?>" autocomplete="off" oninput="preBuscarCli(this.value)">
                    <div class="pre-autocom-box" id="cliBox"></div>
                </div>
            </div>
            <div style="grid-column:span 2;">
                <label>Processo específico (opcional)</label>
                <select name="processo_id" id="procSel">
                    <option value="0">— Todos os processos deste cliente —</option>
                    <?php if ($editar && !empty($editar['processo_id'])): ?>
                        <?php $stP = $pdo->prepare("SELECT id, title, case_number FROM cases WHERE id = ?"); $stP->execute(array((int)$editar['processo_id'])); if ($p = $stP->fetch()): ?>
                            <option value="<?= (int)$p['id'] ?>" selected><?= e($p['title'] ?: 'Caso #' . $p['id']) ?><?= $p['case_number'] ? ' — ' . e($p['case_number']) : '' ?></option>
                        <?php endif; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div>
                <label>Nível da restrição *</label>
                <select name="nivel" required>
                    <option value="nao_enviar" <?= ($editar['nivel']??'')==='nao_enviar'?'selected':'' ?>>🚫 Não enviar</option>
                    <option value="confirmar_endereco" <?= ($editar['nivel']??'')==='confirmar_endereco'?'selected':'' ?>>⚠️ Confirmar endereço</option>
                </select>
            </div>
            <div>
                <label>Ativo?</label>
                <div style="padding-top:10px;"><input type="checkbox" name="ativo" id="ativoR" <?= empty($editar)||!empty($editar['ativo'])?'checked':'' ?>><label for="ativoR" style="display:inline;font-size:.85rem;color:#0E2E36;font-weight:600;text-transform:none;letter-spacing:0;margin-left:4px;">Sim</label></div>
            </div>
            <div style="grid-column:1 / -1;">
                <label>Motivo (interno — não é enviado a lugar nenhum)</label>
                <textarea name="motivo" maxlength="255" rows="2" placeholder="Ex: Cliente em divórcio litigioso, presente em casa expõe ela ao ex-cônjuge."><?= e($editar['motivo'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="pre-actions">
            <button type="submit">💾 <?= $editar ? 'Salvar' : 'Criar' ?></button>
            <?php if ($editar): ?><a href="<?= module_url('presenca','restricoes.php') ?>" class="btn-link">Cancelar</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="pre-lista">
    <div class="pre-row head">
        <div>Cliente</div><div>Motivo</div><div>Nível</div><div>Registrado por</div><div>Quando</div><div>Ações</div>
    </div>
    <?php foreach ($restr as $r): ?>
    <div class="pre-row <?= $r['ativo']?'':'inativo' ?>">
        <div>
            <div class="cli"><?= e($r['cli_nome'] ?: '(cliente removido)') ?></div>
            <?php if (!empty($r['caso_titulo'])): ?><small>📁 <?= e(mb_substr($r['caso_titulo'], 0, 50)) ?></small><?php else: ?><small>Todos os processos</small><?php endif; ?>
        </div>
        <div class="motivo"><?= e($r['motivo'] ?: '—') ?></div>
        <div><span class="nivel-tag <?= e($r['nivel']) ?>"><?= $r['nivel']==='nao_enviar' ? '🚫 Não enviar' : '⚠️ Confirmar' ?></span></div>
        <div style="font-size:.78rem;color:#6b7280;"><?= e($r['user_nome'] ?: '—') ?></div>
        <div style="font-size:.78rem;color:#6b7280;"><?= date('d/m/Y', strtotime($r['created_at'])) ?></div>
        <div style="display:flex;gap:4px;">
            <a href="?editar=<?= (int)$r['id'] ?>" class="pre-btn">✏️</a>
            <form method="POST" style="display:inline;"><?= csrf_input() ?><input type="hidden" name="acao" value="toggle_ativo"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button type="submit" class="pre-btn <?= $r['ativo']?'warn':'' ?>"><?= $r['ativo']?'⏸':'▶' ?></button></form>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Excluir esta restrição?')"><?= csrf_input() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button type="submit" class="pre-btn warn">🗑</button></form>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($restr)): ?>
    <div style="padding:24px;text-align:center;color:#6b7280;font-size:.9rem;">Nenhuma restrição cadastrada.</div>
    <?php endif; ?>
</div>

<script>
var preTimer = null;
function preBuscarCli(q) {
    clearTimeout(preTimer);
    var box = document.getElementById('cliBox');
    if (q.length < 2) { box.style.display = 'none'; return; }
    preTimer = setTimeout(function() {
        fetch('<?= module_url('presenca','restricoes.php') ?>?ajax=buscar_cliente&q=' + encodeURIComponent(q))
            .then(function(r){ return r.json(); })
            .then(function(arr) {
                var html = '';
                arr.forEach(function(c) {
                    html += '<div onclick="preSelCli(' + c.id + ',&quot;' + (c.name||'').replace(/"/g,'') + '&quot;)">' + (c.name||'') + (c.phone?' · '+c.phone:'') + '</div>';
                });
                box.innerHTML = html || '<div style="color:#999;cursor:default;">Nenhum cliente</div>';
                box.style.display = 'block';
            });
    }, 250);
}
function preSelCli(id, nome) {
    document.getElementById('cliId').value = id;
    document.getElementById('cliBusca').value = nome;
    document.getElementById('cliBox').style.display = 'none';
    // carrega processos
    fetch('<?= module_url('presenca','restricoes.php') ?>?ajax=buscar_casos&client_id=' + id)
        .then(function(r){ return r.json(); })
        .then(function(arr) {
            var sel = document.getElementById('procSel');
            sel.innerHTML = '<option value="0">— Todos os processos deste cliente —</option>';
            arr.forEach(function(c) {
                var opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = (c.title || 'Caso #'+c.id) + (c.case_number ? ' — ' + c.case_number : '');
                sel.appendChild(opt);
            });
        });
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
