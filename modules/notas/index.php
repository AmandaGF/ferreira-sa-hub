<?php
/**
 * Ferreira & Sá Hub — Notas Pessoais (Segunda Memória)
 *
 * Bloquinho privado por usuário pra anotar lembretes, pendências futuras
 * e qualquer coisa que precise revisitar depois. SO o dono ve as proprias notas.
 *
 * Amanda 16/06/2026.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = '📝 Notas Pessoais';
$pdo = db();
$uid = (int)current_user_id();

// Self-heal: tabela
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notas_pessoais (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        titulo VARCHAR(200) NOT NULL DEFAULT '',
        conteudo MEDIUMTEXT NOT NULL,
        fixada TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('ativa','arquivada','feita') NOT NULL DEFAULT 'ativa',
        cor VARCHAR(20) DEFAULT NULL,
        criada_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        atualizada_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_status (user_id, status, fixada, atualizada_em),
        INDEX idx_user_atual (user_id, atualizada_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// ── POST handlers ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) { flash_set('error', 'CSRF inválido'); redirect($_SERVER['REQUEST_URI']); }
    $act = $_POST['action'] ?? '';
    $id  = (int)($_POST['id'] ?? 0);

    if ($act === 'criar' || $act === 'editar') {
        $titulo = trim($_POST['titulo'] ?? '');
        $conteudo = trim($_POST['conteudo'] ?? '');
        $cor = trim($_POST['cor'] ?? '') ?: null;
        if ($conteudo === '' && $titulo === '') {
            flash_set('error', 'A nota não pode ficar vazia.');
            redirect(module_url('notas'));
        }
        if ($act === 'criar') {
            $pdo->prepare("INSERT INTO notas_pessoais (user_id, titulo, conteudo, cor) VALUES (?,?,?,?)")
                ->execute(array($uid, $titulo, $conteudo, $cor));
            flash_set('success', '✓ Nota criada.');
        } else {
            // Só o dono pode editar
            $pdo->prepare("UPDATE notas_pessoais SET titulo = ?, conteudo = ?, cor = ? WHERE id = ? AND user_id = ?")
                ->execute(array($titulo, $conteudo, $cor, $id, $uid));
            flash_set('success', '✓ Nota atualizada.');
        }
        redirect(module_url('notas'));
    }

    if ($act === 'toggle_fixada') {
        $pdo->prepare("UPDATE notas_pessoais SET fixada = 1 - fixada WHERE id = ? AND user_id = ?")
            ->execute(array($id, $uid));
        redirect(module_url('notas'));
    }

    if ($act === 'marcar_feita') {
        $pdo->prepare("UPDATE notas_pessoais SET status = 'feita' WHERE id = ? AND user_id = ?")
            ->execute(array($id, $uid));
        flash_set('success', '✓ Nota marcada como feita.');
        redirect(module_url('notas'));
    }

    if ($act === 'reativar') {
        $pdo->prepare("UPDATE notas_pessoais SET status = 'ativa' WHERE id = ? AND user_id = ?")
            ->execute(array($id, $uid));
        redirect(module_url('notas') . '?' . http_build_query(array('filtro' => $_POST['voltar_filtro'] ?? 'ativas')));
    }

    if ($act === 'arquivar') {
        $pdo->prepare("UPDATE notas_pessoais SET status = 'arquivada' WHERE id = ? AND user_id = ?")
            ->execute(array($id, $uid));
        flash_set('success', 'Nota arquivada.');
        redirect(module_url('notas'));
    }

    if ($act === 'excluir') {
        $pdo->prepare("DELETE FROM notas_pessoais WHERE id = ? AND user_id = ?")
            ->execute(array($id, $uid));
        flash_set('success', 'Nota excluída.');
        redirect(module_url('notas'));
    }
}

// ── Filtros / lista ──────────────────────────────────
$filtro = $_GET['filtro'] ?? 'ativas';
$busca  = trim($_GET['q'] ?? '');
$editandoId = (int)($_GET['edit'] ?? 0);

$where = "user_id = ?"; $params = array($uid);
if ($filtro === 'ativas')     { $where .= " AND status = 'ativa'"; }
elseif ($filtro === 'feitas') { $where .= " AND status = 'feita'"; }
elseif ($filtro === 'arquivadas') { $where .= " AND status = 'arquivada'"; }
if ($busca !== '') {
    $where .= " AND (titulo LIKE ? OR conteudo LIKE ?)";
    $params[] = '%' . $busca . '%';
    $params[] = '%' . $busca . '%';
}

$notas = $pdo->prepare("SELECT * FROM notas_pessoais WHERE $where ORDER BY fixada DESC, atualizada_em DESC LIMIT 200");
$notas->execute($params);
$notas = $notas->fetchAll(PDO::FETCH_ASSOC);

// Contadores por status
$cnts = $pdo->prepare("SELECT status, COUNT(*) as n FROM notas_pessoais WHERE user_id = ? GROUP BY status");
$cnts->execute(array($uid));
$cntMap = array('ativa'=>0,'feita'=>0,'arquivada'=>0);
foreach ($cnts->fetchAll(PDO::FETCH_ASSOC) as $r) { $cntMap[$r['status']] = (int)$r['n']; }

// Pra modo edição: carrega a nota
$notaEdit = null;
if ($editandoId > 0) {
    $st = $pdo->prepare("SELECT * FROM notas_pessoais WHERE id = ? AND user_id = ?");
    $st->execute(array($editandoId, $uid));
    $notaEdit = $st->fetch(PDO::FETCH_ASSOC);
}

require_once APP_ROOT . '/templates/layout_start.php';

// Helper visual
function _cor_nota($cor) {
    $cores = array(
        'amarela'  => array('#fef3c7', '#f59e0b'),
        'rosa'     => array('#fce7f3', '#ec4899'),
        'verde'    => array('#dcfce7', '#10b981'),
        'azul'     => array('#dbeafe', '#3b82f6'),
        'laranja'  => array('#ffedd5', '#fb923c'),
        'roxa'     => array('#ede9fe', '#7c3aed'),
    );
    return $cores[$cor] ?? array('#fff', '#cbd5e1');
}
?>

<style>
.notas-wrap { display:grid; grid-template-columns: 1fr 380px; gap:1.5rem; align-items:start; }
@media (max-width:900px) { .notas-wrap { grid-template-columns: 1fr; } }
.notas-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap:.85rem; }
.nota-card { background:#fffbeb; border:1px solid #fcd34d; border-radius:10px; padding:.85rem 1rem; position:relative; transition:transform .12s, box-shadow .12s; box-shadow:0 1px 3px rgba(0,0,0,.05); }
.nota-card:hover { transform:translateY(-2px); box-shadow:0 6px 16px rgba(0,0,0,.08); }
.nota-card.fixada { box-shadow:0 0 0 3px #fde047, 0 6px 16px rgba(0,0,0,.08); }
.nota-card .nota-tit { font-size:.95rem; font-weight:700; color:#052228; margin-bottom:.3rem; word-wrap:break-word; }
.nota-card .nota-corpo { font-size:.82rem; color:#475569; white-space:pre-wrap; word-wrap:break-word; max-height:260px; overflow-y:auto; line-height:1.5; }
.nota-card .nota-meta { font-size:.66rem; color:#94a3b8; margin-top:.5rem; display:flex; justify-content:space-between; gap:.4rem; flex-wrap:wrap; align-items:center; }
.nota-card .nota-acoes { display:flex; gap:.25rem; flex-wrap:wrap; margin-top:.4rem; }
.nota-card .nota-acoes a, .nota-card .nota-acoes button { background:rgba(255,255,255,.7); border:1px solid rgba(0,0,0,.08); padding:.2rem .55rem; border-radius:6px; font-size:.66rem; cursor:pointer; color:#475569; text-decoration:none; font-weight:600; }
.nota-card .nota-acoes a:hover, .nota-card .nota-acoes button:hover { background:#fff; color:#052228; }
.nota-card.feita { opacity:.55; background:#f3f4f6; }
.nota-card.feita .nota-tit, .nota-card.feita .nota-corpo { text-decoration:line-through; }
.nota-card.arquivada { background:#f8fafc; border-color:#cbd5e1; }
.nota-form { background:#fff; border:1.5px solid #e5e7eb; border-radius:12px; padding:1rem; position:sticky; top:1rem; }
.nota-form h3 { font-size:.95rem; color:#052228; margin:0 0 .6rem; }
.nota-form input, .nota-form textarea { width:100%; padding:.5rem .65rem; border:1.5px solid #d1d5db; border-radius:8px; font-size:.88rem; font-family:inherit; }
.nota-form textarea { min-height:220px; resize:vertical; line-height:1.5; }
.nota-form label { font-size:.72rem; font-weight:700; color:#475569; display:block; margin:.6rem 0 .3rem; text-transform:uppercase; letter-spacing:.5px; }
.cor-opt { display:inline-block; width:26px; height:26px; border-radius:50%; cursor:pointer; margin-right:.25rem; border:3px solid transparent; transition:transform .12s; }
.cor-opt:hover { transform:scale(1.12); }
.cor-opt.selected { border-color:#052228; transform:scale(1.08); }
.filtro-pill { display:inline-flex; align-items:center; gap:.3rem; padding:.35rem .8rem; border-radius:14px; font-size:.78rem; font-weight:600; color:#475569; background:#fff; border:1px solid #e2e8f0; cursor:pointer; text-decoration:none; }
.filtro-pill.ativo { background:#052228; color:#fff; border-color:#052228; }
.filtro-pill .cnt { background:rgba(0,0,0,.12); padding:1px 7px; border-radius:8px; font-size:.65rem; }
.filtro-pill.ativo .cnt { background:rgba(255,255,255,.2); }
.empty { text-align:center; padding:3rem 1rem; color:#94a3b8; }
.empty .ico { font-size:3rem; margin-bottom:.5rem; }
</style>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;">
    <div>
        <h2 style="margin:0;color:#052228;">📝 Notas Pessoais</h2>
        <small style="color:#6b7280;">Sua segunda memória — anotações privadas que só você vê.</small>
    </div>
    <form method="GET" style="display:flex;gap:.4rem;align-items:center;">
        <input type="hidden" name="filtro" value="<?= e($filtro) ?>">
        <input type="text" name="q" value="<?= e($busca) ?>" placeholder="🔍 Buscar nas notas..." style="padding:.45rem .75rem;border:1.5px solid var(--border);border-radius:8px;font-size:.82rem;width:240px;">
        <?php if ($busca): ?>
            <a href="<?= module_url('notas') ?>?filtro=<?= e($filtro) ?>" style="font-size:.74rem;color:#94a3b8;text-decoration:none;">✕ limpar</a>
        <?php endif; ?>
    </form>
</div>

<div style="display:flex;gap:.4rem;margin-bottom:1rem;flex-wrap:wrap;">
    <a href="<?= module_url('notas') ?>?filtro=ativas" class="filtro-pill <?= $filtro==='ativas'?'ativo':'' ?>">📝 Ativas <span class="cnt"><?= $cntMap['ativa'] ?></span></a>
    <a href="<?= module_url('notas') ?>?filtro=feitas" class="filtro-pill <?= $filtro==='feitas'?'ativo':'' ?>">✅ Feitas <span class="cnt"><?= $cntMap['feita'] ?></span></a>
    <a href="<?= module_url('notas') ?>?filtro=arquivadas" class="filtro-pill <?= $filtro==='arquivadas'?'ativo':'' ?>">📦 Arquivadas <span class="cnt"><?= $cntMap['arquivada'] ?></span></a>
</div>

<div class="notas-wrap">
    <div>
        <?php if (empty($notas)): ?>
            <div class="empty">
                <div class="ico">📭</div>
                <div>Nenhuma nota <?= $filtro === 'ativas' ? 'ativa' : ($filtro === 'feitas' ? 'feita' : 'arquivada') ?>.</div>
                <?php if ($filtro === 'ativas'): ?>
                    <small>Use o formulário ao lado pra criar a primeira →</small>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="notas-grid">
                <?php foreach ($notas as $n):
                    list($bg, $border) = _cor_nota($n['cor']);
                ?>
                    <div class="nota-card <?= $n['status']==='feita'?'feita':'' ?><?= $n['status']==='arquivada'?'arquivada':'' ?><?= $n['fixada']?' fixada':'' ?>"
                         style="background:<?= $bg ?>;border-color:<?= $border ?>;">
                        <?php if ($n['fixada']): ?>
                            <span style="position:absolute;top:6px;right:6px;font-size:.85rem;" title="Fixada">📌</span>
                        <?php endif; ?>
                        <?php if ($n['titulo']): ?>
                            <div class="nota-tit"><?= e($n['titulo']) ?></div>
                        <?php endif; ?>
                        <div class="nota-corpo"><?= nl2br(e($n['conteudo'])) ?></div>
                        <div class="nota-meta">
                            <span>📅 <?= date('d/m/Y H:i', strtotime($n['atualizada_em'])) ?></span>
                        </div>
                        <div class="nota-acoes">
                            <a href="<?= module_url('notas') ?>?edit=<?= (int)$n['id'] ?>&filtro=<?= e($filtro) ?>">✏️ Editar</a>
                            <form method="POST" style="display:inline;">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="toggle_fixada">
                                <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                <button type="submit"><?= $n['fixada'] ? '📌 Desafixar' : '📌 Fixar' ?></button>
                            </form>
                            <?php if ($n['status'] === 'ativa'): ?>
                                <form method="POST" style="display:inline;">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="marcar_feita">
                                    <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                    <button type="submit">✅ Feita</button>
                                </form>
                            <?php else: ?>
                                <form method="POST" style="display:inline;">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="reativar">
                                    <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                    <input type="hidden" name="voltar_filtro" value="<?= e($filtro) ?>">
                                    <button type="submit">↩️ Reativar</button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Excluir esta nota permanentemente?');">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="excluir">
                                <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                <button type="submit" style="color:#dc2626;">🗑️ Excluir</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="nota-form">
        <h3><?= $notaEdit ? '✏️ Editar nota' : '➕ Nova nota' ?></h3>
        <form method="POST">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="<?= $notaEdit ? 'editar' : 'criar' ?>">
            <?php if ($notaEdit): ?>
                <input type="hidden" name="id" value="<?= (int)$notaEdit['id'] ?>">
            <?php endif; ?>

            <label>Título (opcional)</label>
            <input type="text" name="titulo" maxlength="200" value="<?= e($notaEdit['titulo'] ?? '') ?>" placeholder="Ex.: Revisar duplicatas Legal One">

            <label>Conteúdo</label>
            <textarea name="conteudo" required placeholder="Escreva aqui o que quer lembrar..."><?= e($notaEdit['conteudo'] ?? '') ?></textarea>

            <label>Cor (opcional)</label>
            <div id="coresPicker">
                <input type="hidden" name="cor" id="corHidden" value="<?= e($notaEdit['cor'] ?? '') ?>">
                <?php foreach (array(''=>'#fff','amarela'=>'#fef3c7','rosa'=>'#fce7f3','verde'=>'#dcfce7','azul'=>'#dbeafe','laranja'=>'#ffedd5','roxa'=>'#ede9fe') as $key => $hex): ?>
                    <span class="cor-opt <?= ($notaEdit['cor'] ?? '') === $key ? 'selected' : '' ?>" style="background:<?= $hex ?>;border:2px solid <?= $hex==='#fff' ? '#cbd5e1' : 'transparent' ?>;" data-cor="<?= $key ?>" onclick="selecionarCor(this)" title="<?= $key ?: 'sem cor' ?>"></span>
                <?php endforeach; ?>
            </div>

            <div style="display:flex;gap:.4rem;margin-top:1rem;">
                <button type="submit" class="btn btn-primary" style="flex:1;"><?= $notaEdit ? '💾 Salvar' : '➕ Criar nota' ?></button>
                <?php if ($notaEdit): ?>
                    <a href="<?= module_url('notas') ?>?filtro=<?= e($filtro) ?>" class="btn btn-outline">Cancelar</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script>
function selecionarCor(el) {
    document.querySelectorAll('.cor-opt').forEach(function(c){ c.classList.remove('selected'); });
    el.classList.add('selected');
    document.getElementById('corHidden').value = el.dataset.cor;
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
