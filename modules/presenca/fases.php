<?php
/**
 * Presença — Fases da Jornada.
 * CRUD das fases (Boas-vindas, O fôlego, O marco, A nova fase, Aniversário do caso...).
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('presenca');

$pdo = db();
$pageTitle = 'Presença — Fases da Jornada';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) { flash_set('error','Sessão expirada.'); redirect(module_url('presenca','fases.php')); }
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'salvar') {
        $id      = (int)($_POST['id'] ?? 0);
        $nome    = clean_str($_POST['nome'] ?? '', 80);
        $slug    = strtolower(preg_replace('/[^a-z0-9]+/i','-', trim($nome)));
        $gatilho = clean_str($_POST['gatilho'] ?? '', 255);
        $tipo    = in_array($_POST['tipo'] ?? '', array('processual','efemeride')) ? $_POST['tipo'] : 'processual';
        $recorr  = !empty($_POST['recorrente']) ? 1 : 0;
        $ordem   = (int)($_POST['ordem'] ?? 0);
        $ativo   = !empty($_POST['ativo']) ? 1 : 0;
        if (!$nome) { flash_set('error','Nome obrigatório.'); redirect(module_url('presenca','fases.php')); }
        if ($id > 0) {
            $pdo->prepare("UPDATE presenca_fase SET nome=?, slug=?, gatilho=?, tipo=?, recorrente=?, ordem=?, ativo=? WHERE id=?")
                ->execute(array($nome,$slug,$gatilho,$tipo,$recorr,$ordem,$ativo,$id));
            audit_log('presenca_fase_edit','presenca_fase',$id,$nome);
            flash_set('success','Fase atualizada.');
        } else {
            $pdo->prepare("INSERT INTO presenca_fase (nome,slug,gatilho,tipo,recorrente,ordem,ativo) VALUES (?,?,?,?,?,?,?)")
                ->execute(array($nome,$slug,$gatilho,$tipo,$recorr,$ordem,$ativo));
            audit_log('presenca_fase_new','presenca_fase',(int)$pdo->lastInsertId(),$nome);
            flash_set('success','Fase criada.');
        }
        redirect(module_url('presenca','fases.php'));
    }
    if ($acao === 'toggle_ativo') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) $pdo->prepare("UPDATE presenca_fase SET ativo = 1 - ativo WHERE id = ?")->execute(array($id));
        redirect(module_url('presenca','fases.php'));
    }
}

$fases = $pdo->query("SELECT f.*, (SELECT COUNT(*) FROM presenca_regra WHERE fase_id=f.id AND ativo=1) AS regras,
                             (SELECT COUNT(*) FROM presenca_frase WHERE fase_id=f.id AND ativo=1) AS frases
                     FROM presenca_fase f ORDER BY ordem, id")->fetchAll(PDO::FETCH_ASSOC);
$editar = null;
if (isset($_GET['editar']) && (int)$_GET['editar'] > 0) {
    $st = $pdo->prepare("SELECT * FROM presenca_fase WHERE id = ?");
    $st->execute(array((int)$_GET['editar']));
    $editar = $st->fetch(PDO::FETCH_ASSOC);
}
$csrf = generate_csrf_token();
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.pf-hero { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px; }
.pf-hero h1 { margin:0; font-family:'Cormorant Garamond',Georgia,serif; font-size:1.6rem; font-weight:600; color:#0E2E36; }
.pf-back { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; text-decoration:none; color:#0E2E36; font-size:.78rem; font-weight:600; }
.pf-form { background:#fff; border:1.5px solid #d7ab90; border-radius:12px; padding:20px 24px; margin-bottom:20px; box-shadow:0 4px 12px rgba(215,171,144,.15); }
.pf-form h3 { margin:0 0 14px; font-size:1.05rem; color:#0E2E36; }
.pf-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; }
.pf-form label { display:block; font-size:.72rem; font-weight:700; color:#6b7280; margin-bottom:4px; text-transform:uppercase; letter-spacing:.03em; }
.pf-form input[type=text], .pf-form input[type=number], .pf-form select { width:100%; border:1.5px solid #e5e7eb; border-radius:8px; padding:8px 10px; font-size:.88rem; font-family:inherit; }
.pf-form input:focus, .pf-form select:focus { outline:none; border-color:#B87333; box-shadow:0 0 0 3px rgba(184,115,51,.15); }
.pf-actions { display:flex; gap:8px; margin-top:14px; }
.pf-actions button, .pf-actions .btn-link { background:#0E2E36; color:#fff; border:none; border-radius:8px; padding:9px 18px; font-size:.85rem; font-weight:700; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:5px; }
.pf-actions .btn-link { background:#fff; color:#0E2E36; border:1px solid #d1d5db; }

.pf-list { background:#fff; border-radius:12px; overflow:hidden; border:1px solid #e5e7eb; }
.pf-row { display:grid; grid-template-columns:60px 2fr 1.5fr 130px 90px 90px 150px; gap:10px; padding:12px 16px; align-items:center; border-bottom:1px solid #f3f4f6; }
.pf-row:last-child { border-bottom:none; }
.pf-row.head { background:#fafafa; font-size:.7rem; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; font-weight:700; }
.pf-row.inativo { opacity:.55; }
.pf-row .nome { font-weight:800; color:#0E2E36; font-size:.9rem; }
.pf-row .slug { font-size:.68rem; color:#6b7280; font-family:'JetBrains Mono',Consolas,monospace; margin-top:2px; }
.pf-row .gatilho { font-size:.8rem; color:#374151; }
.pf-row .tipo-tag { display:inline-block; padding:2px 8px; border-radius:999px; font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
.pf-row .tipo-tag.processual { background:#eff6ff; color:#1d4ed8; }
.pf-row .tipo-tag.efemeride { background:#fef3c7; color:#78350f; }
.pf-row .badge-num { background:#f5ede3; color:#78350f; padding:2px 8px; border-radius:999px; font-size:.72rem; font-weight:700; }
.pf-row .acoes { display:flex; gap:6px; }
.pf-btn { background:#fff; color:#0E2E36; border:1px solid #d1d5db; border-radius:6px; padding:5px 10px; font-size:.72rem; font-weight:700; cursor:pointer; text-decoration:none; }
.pf-btn:hover { border-color:#B87333; color:#B87333; }
.pf-btn.warn { color:#dc2626; border-color:#fecaca; }
@media (max-width:900px) {
  .pf-row { grid-template-columns: 1fr; gap:6px; }
  .pf-row.head { display:none; }
}
</style>

<div class="pf-hero">
    <div>
        <h1>🛤️ Fases da Jornada</h1>
        <div style="font-size:.85rem;color:#6b7280;margin-top:4px;">Boas-vindas → O fôlego → O marco → A nova fase → efemérides</div>
    </div>
    <a href="<?= module_url('presenca') ?>" class="pf-back">← Voltar</a>
</div>

<div class="pf-form">
    <h3><?= $editar ? '✏️ Editando: ' . e($editar['nome']) : '➕ Nova fase' ?></h3>
    <form method="POST">
        <?= csrf_input() ?>
        <input type="hidden" name="acao" value="salvar">
        <?php if ($editar): ?><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>"><?php endif; ?>
        <div class="pf-grid">
            <div style="grid-column:span 2;">
                <label>Nome *</label>
                <input type="text" name="nome" required maxlength="80" value="<?= e($editar['nome'] ?? '') ?>" placeholder="Ex: O fôlego">
            </div>
            <div>
                <label>Tipo</label>
                <select name="tipo">
                    <option value="processual" <?= ($editar['tipo']??'')==='processual'?'selected':'' ?>>Processual</option>
                    <option value="efemeride" <?= ($editar['tipo']??'')==='efemeride'?'selected':'' ?>>Efeméride</option>
                </select>
            </div>
            <div>
                <label>Ordem</label>
                <input type="number" name="ordem" min="0" value="<?= (int)($editar['ordem'] ?? 0) ?>">
            </div>
            <div style="grid-column:1 / -1;">
                <label>Gatilho (texto livre — o que aciona)</label>
                <input type="text" name="gatilho" maxlength="255" value="<?= e($editar['gatilho'] ?? '') ?>" placeholder="Ex: Sentença / trânsito em julgado / acordo homologado">
            </div>
            <div>
                <label>Recorrente?</label>
                <div style="padding-top:10px;"><input type="checkbox" name="recorrente" id="recorr" <?= !empty($editar['recorrente'])?'checked':'' ?>><label for="recorr" style="display:inline;font-size:.85rem;color:#0E2E36;font-weight:600;text-transform:none;letter-spacing:0;margin-left:4px;">Sim (todo ano)</label></div>
            </div>
            <div>
                <label>Ativo?</label>
                <div style="padding-top:10px;"><input type="checkbox" name="ativo" id="ativoF" <?= empty($editar)||!empty($editar['ativo'])?'checked':'' ?>><label for="ativoF" style="display:inline;font-size:.85rem;color:#0E2E36;font-weight:600;text-transform:none;letter-spacing:0;margin-left:4px;">Sim</label></div>
            </div>
        </div>
        <div class="pf-actions">
            <button type="submit">💾 <?= $editar ? 'Salvar' : 'Criar' ?></button>
            <?php if ($editar): ?><a href="<?= module_url('presenca','fases.php') ?>" class="btn-link">Cancelar</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="pf-list">
    <div class="pf-row head">
        <div>Ordem</div><div>Nome</div><div>Gatilho</div><div>Tipo</div><div>Regras</div><div>Frases</div><div>Ações</div>
    </div>
    <?php foreach ($fases as $f): ?>
    <div class="pf-row <?= $f['ativo']?'':'inativo' ?>">
        <div><span class="badge-num"><?= (int)$f['ordem'] ?></span></div>
        <div><div class="nome"><?= e($f['nome']) ?><?php if (!empty($f['recorrente'])): ?> 🔁<?php endif; ?></div><div class="slug"><?= e($f['slug']) ?></div></div>
        <div class="gatilho"><?= e($f['gatilho'] ?: '—') ?></div>
        <div><span class="tipo-tag <?= e($f['tipo']) ?>"><?= e($f['tipo']) ?></span></div>
        <div><span class="badge-num"><?= (int)$f['regras'] ?></span></div>
        <div><span class="badge-num"><?= (int)$f['frases'] ?></span></div>
        <div class="acoes">
            <a href="?editar=<?= (int)$f['id'] ?>" class="pf-btn">✏️</a>
            <form method="POST" style="display:inline;"><?= csrf_input() ?><input type="hidden" name="acao" value="toggle_ativo"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button type="submit" class="pf-btn <?= $f['ativo']?'warn':'' ?>"><?= $f['ativo']?'⏸':'▶' ?></button></form>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
