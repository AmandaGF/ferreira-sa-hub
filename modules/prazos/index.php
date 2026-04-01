<?php
/**
 * Ferreira & Sá Hub — Prazos Processuais
 * Alertas: 10d, 5d, 1d antes do prazo fatal
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_role('admin','gestao','operacional')) { redirect(url('modules/dashboard/')); }

$pageTitle = 'Prazos Processuais';
$pdo = db();

// Ações
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $clientId = (int)($_POST['client_id'] ?? 0) ?: null;
        $caseId = (int)($_POST['case_id'] ?? 0) ?: null;
        $numProcesso = clean_str($_POST['numero_processo'] ?? '', 50);
        $descricao = clean_str($_POST['descricao_acao'] ?? '', 250);
        $prazoFatal = $_POST['prazo_fatal'] ?? '';

        if ($descricao && $prazoFatal) {
            $pdo->prepare("INSERT INTO prazos_processuais (client_id, case_id, numero_processo, descricao_acao, prazo_fatal, usuario_id) VALUES (?,?,?,?,?,?)")
                ->execute(array($clientId, $caseId, $numProcesso ?: null, $descricao, $prazoFatal, current_user_id()));
            flash_set('success', 'Prazo cadastrado!');
        }
        redirect(module_url('prazos'));
    }

    if ($action === 'concluir') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare("UPDATE prazos_processuais SET concluido = 1, concluido_em = NOW() WHERE id = ?")
                ->execute(array($id));
            flash_set('success', 'Prazo concluído!');
        }
        redirect(module_url('prazos'));
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare("DELETE FROM prazos_processuais WHERE id = ?")->execute(array($id));
            flash_set('success', 'Prazo removido.');
        }
        redirect(module_url('prazos'));
    }
}

// Filtro
$filtro = isset($_GET['filtro']) ? $_GET['filtro'] : 'pendentes';

if ($filtro === 'todos') {
    $prazos = $pdo->query(
        "SELECT p.*, c.name as client_name, cs.title as case_title
         FROM prazos_processuais p
         LEFT JOIN clients c ON c.id = p.client_id
         LEFT JOIN cases cs ON cs.id = p.case_id
         ORDER BY p.concluido ASC, p.prazo_fatal ASC"
    )->fetchAll();
} else {
    $prazos = $pdo->query(
        "SELECT p.*, c.name as client_name, cs.title as case_title
         FROM prazos_processuais p
         LEFT JOIN clients c ON c.id = p.client_id
         LEFT JOIN cases cs ON cs.id = p.case_id
         WHERE p.concluido = 0
         ORDER BY p.prazo_fatal ASC"
    )->fetchAll();
}

$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.prazo-card { padding:.75rem 1rem; margin-bottom:.4rem; border-radius:var(--radius); border-left:4px solid #ccc; background:var(--bg-card); display:flex; align-items:center; gap:.75rem; }
.prazo-card.urgente { border-left-color:#ef4444; background:#fef2f2; }
.prazo-card.alerta { border-left-color:#f59e0b; background:#fffbeb; }
.prazo-card.normal { border-left-color:#6366f1; }
.prazo-card.concluido { border-left-color:#059669; opacity:.5; }
.prazo-info { flex:1; }
.prazo-desc { font-size:.85rem; font-weight:700; color:var(--petrol-900); }
.prazo-meta { font-size:.7rem; color:var(--text-muted); margin-top:.1rem; }
.prazo-data { font-size:.82rem; font-weight:700; flex-shrink:0; }
.prazo-data.urgente { color:#ef4444; }
.prazo-data.alerta { color:#f59e0b; }
</style>

<?php
$voltarCaso = (int)($_GET['voltar_caso'] ?? $_GET['case_id'] ?? 0);
if ($voltarCaso > 0): ?>
<div style="display:flex;gap:.5rem;margin-bottom:.75rem;">
    <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $voltarCaso) ?>" class="btn btn-outline btn-sm">← Analisar processo</a>
    <a href="<?= module_url('agenda') ?>?voltar_caso=<?= $voltarCaso ?>" class="btn btn-outline btn-sm">📅 Agenda</a>
</div>
<?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem;">
    <div style="display:flex;gap:.35rem;">
        <a href="?filtro=pendentes<?= $voltarCaso ? '&case_id=' . $voltarCaso : '' ?>" class="btn btn-<?= $filtro === 'pendentes' ? 'primary' : 'outline' ?> btn-sm">Pendentes</a>
        <a href="?filtro=todos<?= $voltarCaso ? '&case_id=' . $voltarCaso : '' ?>" class="btn btn-<?= $filtro === 'todos' ? 'primary' : 'outline' ?> btn-sm">Todos</a>
    </div>
    <button class="btn btn-primary btn-sm" data-modal="modalPrazo">+ Novo Prazo</button>
</div>

<?php if (empty($prazos)): ?>
    <div class="card"><div class="card-body" style="text-align:center;padding:2rem;"><h3>Nenhum prazo <?= $filtro === 'pendentes' ? 'pendente' : '' ?></h3></div></div>
<?php else: ?>
    <?php foreach ($prazos as $p):
        $diasRestantes = (int)((strtotime($p['prazo_fatal']) - strtotime('today')) / 86400);
        $isVencido = $diasRestantes < 0 && !$p['concluido'];
        $isUrgente = $diasRestantes <= 1 && !$p['concluido'];
        $isAlerta = $diasRestantes <= 5 && !$p['concluido'];
        $cardClass = $p['concluido'] ? 'concluido' : ($isUrgente || $isVencido ? 'urgente' : ($isAlerta ? 'alerta' : 'normal'));
        $dataClass = $isUrgente || $isVencido ? 'urgente' : ($isAlerta ? 'alerta' : '');
    ?>
    <div class="prazo-card <?= $cardClass ?>">
        <div class="prazo-info">
            <div class="prazo-desc"><?= $p['concluido'] ? '<s>' : '' ?><?= e($p['descricao_acao']) ?><?= $p['concluido'] ? '</s>' : '' ?></div>
            <div class="prazo-meta">
                <?php if ($p['client_name']): ?>👤 <?= e($p['client_name']) ?> · <?php endif; ?>
                <?php if ($p['numero_processo']): ?>🏛️ <?= e($p['numero_processo']) ?> · <?php endif; ?>
                <?php if ($p['case_title']): ?>📂 <?= e($p['case_title']) ?><?php endif; ?>
            </div>
        </div>
        <div class="prazo-data <?= $dataClass ?>">
            <?php if ($isVencido): ?>⚠️ VENCIDO <?= abs($diasRestantes) ?>d
            <?php elseif ($p['concluido']): ?>✅ <?= date('d/m', strtotime($p['concluido_em'])) ?>
            <?php else: ?><?= $diasRestantes === 0 ? 'HOJE' : $diasRestantes . 'd' ?>
            <?php endif; ?>
            <div style="font-size:.65rem;font-weight:400;color:var(--text-muted);"><?= date('d/m/Y', strtotime($p['prazo_fatal'])) ?></div>
        </div>
        <?php if (!$p['concluido']): ?>
        <form method="POST" style="display:flex;gap:.2rem;">
            <?= csrf_input() ?>
            <input type="hidden" name="id" value="<?= $p['id'] ?>">
            <button type="submit" name="action" value="concluir" class="btn btn-success btn-sm" style="font-size:.65rem;padding:.2rem .4rem;" title="Concluir">✓</button>
            <button type="submit" name="action" value="delete" class="btn btn-outline btn-sm" style="font-size:.65rem;padding:.2rem .4rem;opacity:.4;" title="Excluir" data-confirm="Excluir prazo?">🗑️</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Modal: Novo Prazo -->
<div class="modal-overlay" id="modalPrazo">
    <div class="modal">
        <div class="modal-header"><h3>Novo Prazo Processual</h3><button class="modal-close">&times;</button></div>
        <div class="modal-body">
            <form method="POST">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label class="form-label">Descrição da ação *</label>
                    <input type="text" name="descricao_acao" class="form-input" required placeholder="Ex: Contestação, Réplica, Recurso...">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Prazo fatal *</label>
                        <input type="date" name="prazo_fatal" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nº do processo</label>
                        <input type="text" name="numero_processo" class="form-input" placeholder="0000000-00.0000.0.00.0000">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Cliente</label>
                    <select name="client_id" class="form-select">
                        <option value="">— Opcional —</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-footer" style="border:none;padding:1rem 0 0;">
                    <button type="button" class="btn btn-outline" data-modal-close>Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar Prazo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
