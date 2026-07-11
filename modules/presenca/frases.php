<?php
/**
 * Presença — Banco de Frases.
 * CRUD das frases por fase (ou universais quando fase_id = NULL).
 * Cada frase tem tom (recomeço/companhia/paz/conquista/etc) e ativo.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('presenca');

$pdo = db();
$pageTitle = 'Presença — Banco de Frases';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) { flash_set('error','Sessão expirada.'); redirect(module_url('presenca','frases.php')); }
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'salvar') {
        $id     = (int)($_POST['id'] ?? 0);
        $texto  = clean_str($_POST['texto'] ?? '', 255);
        $faseId = (int)($_POST['fase_id'] ?? 0) ?: null;
        $tom    = clean_str($_POST['tom'] ?? '', 40);
        $ativo  = !empty($_POST['ativo']) ? 1 : 0;
        if (!$texto) { flash_set('error','Texto obrigatório.'); redirect(module_url('presenca','frases.php')); }
        if ($id > 0) {
            $pdo->prepare("UPDATE presenca_frase SET texto=?, fase_id=?, tom=?, ativo=? WHERE id=?")
                ->execute(array($texto,$faseId,$tom,$ativo,$id));
            audit_log('presenca_frase_edit','presenca_frase',$id,'');
            flash_set('success','Frase atualizada.');
        } else {
            $pdo->prepare("INSERT INTO presenca_frase (texto,fase_id,tom,ativo) VALUES (?,?,?,?)")
                ->execute(array($texto,$faseId,$tom,$ativo));
            audit_log('presenca_frase_new','presenca_frase',(int)$pdo->lastInsertId(),'');
            flash_set('success','Frase criada.');
        }
        redirect(module_url('presenca','frases.php'));
    }
    if ($acao === 'toggle_ativo') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) $pdo->prepare("UPDATE presenca_frase SET ativo = 1 - ativo WHERE id = ?")->execute(array($id));
        redirect(module_url('presenca','frases.php'));
    }
    if ($acao === 'excluir') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $emUso = (int)$pdo->query("SELECT COUNT(*) FROM presenca_regra WHERE frase_id = $id")->fetchColumn();
            if ($emUso > 0) {
                flash_set('error', "Frase em uso em $emUso regra(s). Desative em vez de excluir.");
            } else {
                $pdo->prepare("DELETE FROM presenca_frase WHERE id = ?")->execute(array($id));
                audit_log('presenca_frase_del','presenca_frase',$id,'');
                flash_set('success','Frase excluída.');
            }
        }
        redirect(module_url('presenca','frases.php'));
    }
}

$fases = $pdo->query("SELECT id, nome FROM presenca_fase WHERE ativo=1 ORDER BY ordem, id")->fetchAll(PDO::FETCH_ASSOC);
$fasesMap = array(); foreach ($fases as $f) $fasesMap[$f['id']] = $f['nome'];

$filtroFase = isset($_GET['fase']) ? (int)$_GET['fase'] : -1;
$sql = "SELECT * FROM presenca_frase";
$params = array();
if ($filtroFase === 0) { $sql .= " WHERE fase_id IS NULL"; }
elseif ($filtroFase > 0) { $sql .= " WHERE fase_id = ?"; $params[] = $filtroFase; }
$sql .= " ORDER BY fase_id IS NULL, fase_id, id";
$st = $pdo->prepare($sql); $st->execute($params);
$frases = $st->fetchAll(PDO::FETCH_ASSOC);

$editar = null;
if (isset($_GET['editar']) && (int)$_GET['editar'] > 0) {
    $st = $pdo->prepare("SELECT * FROM presenca_frase WHERE id = ?");
    $st->execute(array((int)$_GET['editar']));
    $editar = $st->fetch(PDO::FETCH_ASSOC);
}
$csrf = generate_csrf_token();
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.pfr-hero { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px; }
.pfr-hero h1 { margin:0; font-family:'Cormorant Garamond',Georgia,serif; font-size:1.6rem; font-weight:600; color:#0E2E36; }
.pfr-back { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; text-decoration:none; color:#0E2E36; font-size:.78rem; font-weight:600; }
.pfr-form { background:#fff; border:1.5px solid #d7ab90; border-radius:12px; padding:20px 24px; margin-bottom:20px; box-shadow:0 4px 12px rgba(215,171,144,.15); }
.pfr-form h3 { margin:0 0 14px; font-size:1.05rem; color:#0E2E36; }
.pfr-form label { display:block; font-size:.72rem; font-weight:700; color:#6b7280; margin-bottom:4px; text-transform:uppercase; letter-spacing:.03em; }
.pfr-form input, .pfr-form select, .pfr-form textarea { width:100%; border:1.5px solid #e5e7eb; border-radius:8px; padding:8px 10px; font-size:.9rem; font-family:inherit; }
.pfr-form textarea { min-height:60px; resize:vertical; font-family:'Cormorant Garamond',Georgia,serif; font-style:italic; font-size:1rem; color:#0E2E36; }
.pfr-form input:focus, .pfr-form select:focus, .pfr-form textarea:focus { outline:none; border-color:#B87333; box-shadow:0 0 0 3px rgba(184,115,51,.15); }
.pfr-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; }
.pfr-actions { display:flex; gap:8px; margin-top:14px; }
.pfr-actions button, .pfr-actions .btn-link { background:#0E2E36; color:#fff; border:none; border-radius:8px; padding:9px 18px; font-size:.85rem; font-weight:700; cursor:pointer; text-decoration:none; }
.pfr-actions .btn-link { background:#fff; color:#0E2E36; border:1px solid #d1d5db; }
.pfr-filtro { margin-bottom:14px; display:flex; gap:6px; flex-wrap:wrap; }
.pfr-filtro a { padding:6px 12px; background:#fff; border:1px solid #e5e7eb; border-radius:999px; text-decoration:none; color:#0E2E36; font-size:.78rem; font-weight:700; }
.pfr-filtro a.on { background:#0E2E36; color:#fff; border-color:#0E2E36; }

.pfr-grupo { margin-bottom:20px; }
.pfr-grupo h4 { font-family:'Cormorant Garamond',Georgia,serif; font-size:1.15rem; color:#0E2E36; margin:0 0 10px; font-weight:600; padding-left:12px; border-left:4px solid #B87333; }
.pfr-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px 20px; margin-bottom:10px; display:flex; gap:14px; align-items:center; box-shadow:0 1px 3px rgba(0,0,0,.03); }
.pfr-card.inativo { opacity:.55; }
.pfr-card blockquote { flex:1; margin:0; font-family:'Cormorant Garamond',Georgia,serif; font-style:italic; font-size:1.15rem; color:#0E2E36; line-height:1.35; }
.pfr-card blockquote::before { content:'“ '; color:#B87333; font-weight:700; }
.pfr-card blockquote::after { content:' ”'; color:#B87333; font-weight:700; }
.pfr-card .meta { display:flex; flex-direction:column; gap:4px; align-items:flex-end; }
.pfr-card .tom { background:#f5ede3; color:#78350f; padding:2px 10px; border-radius:999px; font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
.pfr-card .acoes { display:flex; gap:4px; }
.pfr-btn { background:#fff; color:#0E2E36; border:1px solid #d1d5db; border-radius:6px; padding:4px 8px; font-size:.7rem; font-weight:700; cursor:pointer; text-decoration:none; }
.pfr-btn:hover { border-color:#B87333; color:#B87333; }
.pfr-btn.warn { color:#dc2626; border-color:#fecaca; }
</style>

<div class="pfr-hero">
    <div>
        <h1>📚 Banco de Frases</h1>
        <div style="font-size:.85rem;color:#6b7280;margin-top:4px;">Frases por fase da jornada (ou universais — para qualquer momento)</div>
    </div>
    <a href="<?= module_url('presenca') ?>" class="pfr-back">← Voltar</a>
</div>

<div class="pfr-form">
    <h3><?= $editar ? '✏️ Editando frase' : '➕ Nova frase' ?></h3>
    <form method="POST">
        <?= csrf_input() ?>
        <input type="hidden" name="acao" value="salvar">
        <?php if ($editar): ?><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>"><?php endif; ?>
        <div class="pfr-grid">
            <div style="grid-column:1 / -1;">
                <label>Texto da frase * <span style="color:#6b7280;font-weight:400;text-transform:none;letter-spacing:0;">(até 255 caracteres)</span></label>
                <textarea name="texto" maxlength="255" required placeholder="Ex: Cada fase vencida, uma luz a mais."><?= e($editar['texto'] ?? '') ?></textarea>
            </div>
            <div>
                <label>Fase</label>
                <select name="fase_id">
                    <option value="0" <?= empty($editar['fase_id'])?'selected':'' ?>>— Universal (qualquer fase) —</option>
                    <?php foreach ($fases as $f): ?>
                        <option value="<?= (int)$f['id'] ?>" <?= (int)($editar['fase_id']??0)===(int)$f['id']?'selected':'' ?>><?= e($f['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Tom</label>
                <input type="text" name="tom" maxlength="40" value="<?= e($editar['tom'] ?? '') ?>" placeholder="Ex: companhia, recomeço, paz">
            </div>
            <div>
                <label>Ativo?</label>
                <div style="padding-top:10px;"><input type="checkbox" name="ativo" id="ativoFr" <?= empty($editar)||!empty($editar['ativo'])?'checked':'' ?>><label for="ativoFr" style="display:inline;font-size:.85rem;color:#0E2E36;font-weight:600;text-transform:none;letter-spacing:0;margin-left:4px;">Sim</label></div>
            </div>
        </div>
        <div class="pfr-actions">
            <button type="submit">💾 <?= $editar ? 'Salvar' : 'Criar' ?></button>
            <?php if ($editar): ?><a href="<?= module_url('presenca','frases.php') ?>" class="btn-link">Cancelar</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="pfr-filtro">
    <a href="?" class="<?= $filtroFase === -1 ? 'on' : '' ?>">Todas</a>
    <a href="?fase=0" class="<?= $filtroFase === 0 ? 'on' : '' ?>">Universais</a>
    <?php foreach ($fases as $f): ?>
        <a href="?fase=<?= (int)$f['id'] ?>" class="<?= $filtroFase === (int)$f['id'] ? 'on' : '' ?>"><?= e($f['nome']) ?></a>
    <?php endforeach; ?>
</div>

<?php
// Agrupa por fase
$grupos = array();
foreach ($frases as $fr) {
    $k = $fr['fase_id'] ? 'F' . $fr['fase_id'] : 'U';
    $grupos[$k][] = $fr;
}
foreach ($grupos as $chave => $lista):
    if ($chave === 'U') { $tit = '✨ Universais (qualquer fase)'; }
    else { $fid = (int)substr($chave, 1); $tit = '🛤️ ' . e($fasesMap[$fid] ?? 'Fase #' . $fid); }
?>
<div class="pfr-grupo">
    <h4><?= $tit ?> — <?= count($lista) ?></h4>
    <?php foreach ($lista as $fr): ?>
    <div class="pfr-card <?= $fr['ativo']?'':'inativo' ?>">
        <blockquote><?= e($fr['texto']) ?></blockquote>
        <div class="meta">
            <?php if (!empty($fr['tom'])): ?><span class="tom"><?= e($fr['tom']) ?></span><?php endif; ?>
            <div class="acoes">
                <a href="?editar=<?= (int)$fr['id'] ?>" class="pfr-btn">✏️</a>
                <form method="POST" style="display:inline;"><?= csrf_input() ?><input type="hidden" name="acao" value="toggle_ativo"><input type="hidden" name="id" value="<?= (int)$fr['id'] ?>"><button type="submit" class="pfr-btn <?= $fr['ativo']?'warn':'' ?>"><?= $fr['ativo']?'⏸':'▶' ?></button></form>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Excluir esta frase?')"><?= csrf_input() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= (int)$fr['id'] ?>"><button type="submit" class="pfr-btn warn">🗑</button></form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
