<?php
/**
 * Ferreira & Sá Hub — Central VIP — Palavras de Bloqueio
 * CRUD dos termos que fazem o andamento NÃO aparecer pro cliente no portal.
 *
 * Onde é usado: salavip_src/pages/dashboard.php filtra andamentos que contém
 * qualquer termo ativo desta tabela antes de exibir pro cliente logado.
 * Exemplo: "conclusos", "vista ao MP", "despacho de mero expediente" — ruido
 * jurídico interno que confunde quem não é da área.
 *
 * Criado 31/05/2026 (Nilce r12): link existia em modules/salavip/index.php
 * apontando aqui mas o arquivo não existia → 404.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

if (!has_min_role('gestao')) {
    flash_set('error', 'Acesso restrito.');
    redirect(url('modules/dashboard/index.php'));
}

$pageTitle = 'Palavras de Bloqueio — Central VIP';
$pdo = db();

// Self-heal (a tabela já é criada por salavip_src/migrate.php, mas garantia)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS salavip_palavras_bloqueio (
        id INT AUTO_INCREMENT PRIMARY KEY,
        termo VARCHAR(200) NOT NULL,
        ativo TINYINT(1) DEFAULT 1,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ativo (ativo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// ── POST handlers ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $termo = trim($_POST['termo'] ?? '');
        if ($termo !== '') {
            $existe = $pdo->prepare("SELECT id FROM salavip_palavras_bloqueio WHERE termo = ?");
            $existe->execute(array($termo));
            if ($existe->fetchColumn()) {
                flash_set('error', 'Esse termo já está cadastrado.');
            } else {
                $pdo->prepare("INSERT INTO salavip_palavras_bloqueio (termo, ativo) VALUES (?, 1)")->execute(array($termo));
                audit_log('salavip_palavra_bloqueio_add', 'salavip_palavras_bloqueio', (int)$pdo->lastInsertId(), $termo);
                flash_set('success', "Termo \"$termo\" adicionado.");
            }
        } else {
            flash_set('error', 'Informe o termo.');
        }
        redirect(module_url('salavip', 'palavras_bloqueio.php'));
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE salavip_palavras_bloqueio SET ativo = 1 - ativo WHERE id = ?")->execute(array($id));
            audit_log('salavip_palavra_bloqueio_toggle', 'salavip_palavras_bloqueio', $id);
            flash_set('success', 'Status alterado.');
        }
        redirect(module_url('salavip', 'palavras_bloqueio.php'));
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $st = $pdo->prepare("SELECT termo FROM salavip_palavras_bloqueio WHERE id = ?");
            $st->execute(array($id));
            $termo = (string)$st->fetchColumn();
            $pdo->prepare("DELETE FROM salavip_palavras_bloqueio WHERE id = ?")->execute(array($id));
            audit_log('salavip_palavra_bloqueio_del', 'salavip_palavras_bloqueio', $id, $termo);
            flash_set('success', "Termo \"$termo\" removido.");
        }
        redirect(module_url('salavip', 'palavras_bloqueio.php'));
    }
}

$termos = $pdo->query("SELECT id, termo, ativo, criado_em FROM salavip_palavras_bloqueio ORDER BY ativo DESC, termo ASC")->fetchAll();
$totalAtivos   = 0;
$totalInativos = 0;
foreach ($termos as $t) { if ($t['ativo']) $totalAtivos++; else $totalInativos++; }

require_once APP_ROOT . '/templates/layout_start.php';
?>
<style>
.pb-card { background:var(--bg-card); border:1px solid var(--border); border-radius:12px; padding:1.25rem; margin-bottom:1.25rem; }
.pb-form { display:flex; gap:.5rem; flex-wrap:wrap; }
.pb-form input[type=text] { flex:1; min-width:240px; padding:.6rem .85rem; border:1.5px solid var(--border); border-radius:8px; font-size:.9rem; }
.pb-table { width:100%; border-collapse:collapse; }
.pb-table th { background:var(--petrol-900); color:#fff; font-size:.7rem; text-transform:uppercase; letter-spacing:.5px; padding:.6rem .85rem; text-align:left; }
.pb-table td { padding:.55rem .85rem; border-bottom:1px solid var(--border); font-size:.85rem; }
.pb-table tr:hover td { background:#fafbfc; }
.pb-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:.65rem; font-weight:700; }
.pb-badge.ativo   { background:#dcfce7; color:#166534; }
.pb-badge.inativo { background:#f3f4f6; color:#6b7280; }
.pb-btn { padding:4px 10px; font-size:.72rem; border:none; border-radius:6px; cursor:pointer; font-weight:600; }
</style>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:.75rem;">
    <div>
        <h2 style="margin:0;">🚫 Palavras de Bloqueio</h2>
        <p style="font-size:.78rem;color:var(--text-muted);margin:.2rem 0 0;">Termos jurídicos que escondem o andamento pro cliente no portal — evita ruído (ex: "conclusos", "vista ao MP").</p>
    </div>
    <a href="<?= module_url('salavip') ?>" class="btn btn-outline btn-sm">← Voltar</a>
</div>

<div class="pb-card" style="background:#fef3c7;border-color:#fcd34d;">
    <p style="margin:0;font-size:.82rem;color:#78350f;">
        <strong>Como funciona:</strong> qualquer andamento do processo que contém <strong>algum termo ATIVO</strong> desta lista
        não aparece pro cliente logado na Central VIP. Filtro é case-insensitive e busca <em>substring</em>
        (ex: "conclusos" pega "feitos conclusos ao juiz"). A equipe interna continua vendo tudo normalmente.
    </p>
</div>

<div class="pb-card">
    <h3 style="margin:0 0 .75rem;font-size:.95rem;">➕ Adicionar termo</h3>
    <form method="POST" class="pb-form">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="add">
        <input type="text" name="termo" maxlength="200" required placeholder="Ex: conclusos, vista ao MP, despacho de mero expediente" title="Termo que será buscado no texto do andamento. Case-insensitive, busca por substring.">
        <button type="submit" class="btn btn-primary">Adicionar</button>
    </form>
</div>

<div class="pb-card" style="padding:0;overflow:hidden;">
    <div style="padding:.85rem 1rem;background:#fafbfc;border-bottom:1px solid var(--border);font-size:.8rem;color:var(--text-muted);">
        <strong style="color:var(--petrol-900);"><?= count($termos) ?> termos</strong>
        · <span style="color:#166534;font-weight:700;"><?= $totalAtivos ?> ativos</span>
        · <span style="color:#6b7280;"><?= $totalInativos ?> inativos</span>
    </div>
    <?php if (empty($termos)): ?>
        <p style="padding:2rem;text-align:center;color:var(--text-muted);font-size:.9rem;">Nenhum termo cadastrado. Adicione termos pra começar a filtrar andamentos sensíveis no portal do cliente.</p>
    <?php else: ?>
    <table class="pb-table">
        <thead>
            <tr>
                <th>Termo</th>
                <th style="width:90px;">Status</th>
                <th style="width:130px;">Cadastrado em</th>
                <th style="width:170px;text-align:right;">Ações</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($termos as $t): ?>
            <tr>
                <td style="font-family:monospace;font-weight:600;<?= $t['ativo'] ? '' : 'color:#9ca3af;text-decoration:line-through;' ?>">
                    <?= htmlspecialchars($t['termo'], ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td><span class="pb-badge <?= $t['ativo'] ? 'ativo' : 'inativo' ?>"><?= $t['ativo'] ? 'ATIVO' : 'INATIVO' ?></span></td>
                <td style="font-size:.75rem;color:var(--text-muted);"><?= $t['criado_em'] ? date('d/m/Y', strtotime($t['criado_em'])) : '—' ?></td>
                <td style="text-align:right;">
                    <form method="POST" style="display:inline;">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <button type="submit" class="pb-btn" style="background:<?= $t['ativo'] ? '#fef3c7' : '#dcfce7' ?>;color:<?= $t['ativo'] ? '#92400e' : '#166534' ?>;" title="<?= $t['ativo'] ? 'Desativar (cliente passa a ver andamentos com este termo)' : 'Ativar (cliente para de ver andamentos com este termo)' ?>">
                            <?= $t['ativo'] ? '⏸ Desativar' : '▶ Ativar' ?>
                        </button>
                    </form>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Remover o termo definitivamente?\n\nTermo: <?= htmlspecialchars(addslashes($t['termo']), ENT_QUOTES, 'UTF-8') ?>');">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <button type="submit" class="pb-btn" style="background:#fee2e2;color:#991b1b;" title="Remover definitivamente">🗑</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
