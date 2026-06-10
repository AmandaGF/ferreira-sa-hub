<?php
/**
 * Ferreira & Sa Hub -- Central VIP -- Log de Acessos
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

if (!has_min_role('gestao')) {
    flash_set('error', 'Acesso restrito.');
    redirect(url('modules/dashboard/index.php'));
}

$pageTitle = 'Log de Acessos — Central VIP';
$pdo = db();

// ── Filtros ─────────────────────────────────────────────
$filterUser  = (int)($_GET['user_id'] ?? 0);
$filterFrom  = $_GET['date_from'] ?? '';
$filterTo    = $_GET['date_to'] ?? '';
// Amanda 10/06/2026: busca textual por nome (escrevendo)
$filterQ     = trim($_GET['q'] ?? '');

$where = '1=1';
$params = array();

if ($filterUser) {
    $where .= ' AND la.usuario_id = ?';
    $params[] = $filterUser;
}
if ($filterFrom) {
    $where .= ' AND DATE(la.criado_em) >= ?';
    $params[] = $filterFrom;
}
if ($filterTo) {
    $where .= ' AND DATE(la.criado_em) <= ?';
    $params[] = $filterTo;
}
if ($filterQ) {
    // Quebra em palavras pra casar nome composto fora de ordem
    $palavras = preg_split('/\s+/', $filterQ);
    $palavras = array_filter($palavras, function($p){ return mb_strlen($p) >= 2; });
    if ($palavras) {
        foreach ($palavras as $p) {
            $where .= ' AND c.name LIKE ?';
            $params[] = '%' . $p . '%';
        }
    } else {
        $where .= ' AND c.name LIKE ?';
        $params[] = '%' . $filterQ . '%';
    }
}

$logs = $pdo->prepare(
    "SELECT la.*, c.name as client_name
     FROM salavip_log_acesso la
     JOIN salavip_usuarios su ON su.id = la.usuario_id
     JOIN clients c ON c.id = su.cliente_id
     WHERE $where
     ORDER BY la.criado_em DESC
     LIMIT 100"
);
$logs->execute($params);
$logs = $logs->fetchAll();

// Usuarios para filtro
$usuarios = $pdo->query(
    "SELECT su.id, c.name FROM salavip_usuarios su JOIN clients c ON c.id = su.cliente_id ORDER BY c.name"
)->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.log-table { width:100%; border-collapse:collapse; font-size:.82rem; }
.log-table th { background:var(--petrol-900); color:#fff; padding:.5rem .75rem; text-align:left; font-size:.72rem; text-transform:uppercase; letter-spacing:.5px; }
.log-table td { padding:.5rem .75rem; border-bottom:1px solid var(--border); vertical-align:middle; }
.log-table tr:hover { background:rgba(215,171,144,.04); }
.log-filters { display:flex; gap:.5rem; flex-wrap:wrap; align-items:flex-end; }
</style>

<a href="<?= module_url('salavip') ?>" class="btn btn-outline btn-sm mb-2">&larr; Voltar</a>

<!-- Filters -->
<div class="card mb-2">
    <div class="card-body">
        <form method="GET" class="log-filters">
            <div style="flex:1;min-width:220px;">
                <label class="form-label">Buscar por nome</label>
                <input type="text" name="q" value="<?= e($filterQ) ?>" placeholder="Digite o nome do cliente..."
                       class="form-control" style="font-size:.82rem;width:100%;"
                       autocomplete="off" oninput="logFiltrarClientside(this.value)">
            </div>
            <div>
                <label class="form-label">Usuário (lista)</label>
                <select name="user_id" class="form-control" style="font-size:.78rem;width:200px;">
                    <option value="">Todos</option>
                    <?php foreach ($usuarios as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $filterUser == $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label">De</label>
                <input type="date" name="date_from" value="<?= e($filterFrom) ?>" class="form-control" style="font-size:.78rem;">
            </div>
            <div>
                <label class="form-label">Até</label>
                <input type="date" name="date_to" value="<?= e($filterTo) ?>" class="form-control" style="font-size:.78rem;">
            </div>
            <div>
                <button type="submit" class="btn btn-outline btn-sm">Filtrar</button>
                <?php if ($filterUser || $filterFrom || $filterTo || $filterQ): ?>
                    <a href="<?= module_url('salavip', 'log.php') ?>" class="btn btn-outline btn-sm">Limpar</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Log Table -->
<div class="card">
    <div class="card-header">
        <h3>Últimos 100 Acessos <span id="logCnt" style="color:#94a3b8;font-size:.85rem;font-weight:400;"></span></h3>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto;">
        <?php if (empty($logs)): ?>
            <div style="text-align:center;padding:2rem;">
                <p class="text-muted text-sm">Nenhum registro encontrado.</p>
            </div>
        <?php else: ?>
            <table class="log-table">
                <thead>
                    <tr>
                        <th>Usuário</th>
                        <th>Ação</th>
                        <th>IP</th>
                        <th>Data/Hora</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td style="font-weight:600;"><?= e($log['client_name']) ?></td>
                            <td><?= e($log['acao'] ?? $log['action'] ?? '—') ?></td>
                            <td class="text-sm text-muted" style="font-family:monospace;"><?= e($log['ip'] ?? $log['ip_address'] ?? '—') ?></td>
                            <td class="text-sm"><?= date('d/m/Y H:i:s', strtotime($log['criado_em'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
// Amanda 10/06/2026: filtro instantaneo enquanto digita (sem reload). Enter ainda
// submete o form pra busca server-side (alarga alem das 100 ultimas).
function logFiltrarClientside(termo) {
    var t = (termo || '').toLowerCase().trim();
    var rows = document.querySelectorAll('table.log-table tbody tr');
    var visiveis = 0;
    rows.forEach(function(tr) {
        if (!t) { tr.style.display = ''; visiveis++; return; }
        // Casa cada palavra do termo (nome composto fora de ordem)
        var palavras = t.split(/\s+/).filter(function(p){ return p.length >= 2; });
        var txt = tr.textContent.toLowerCase();
        var bate = palavras.length ? palavras.every(function(p){ return txt.indexOf(p) !== -1; })
                                   : (txt.indexOf(t) !== -1);
        tr.style.display = bate ? '' : 'none';
        if (bate) visiveis++;
    });
    var cnt = document.getElementById('logCnt');
    if (cnt) cnt.textContent = t ? '— ' + visiveis + ' de ' + rows.length + ' (digite e pressione Filtrar pra ampliar)' : '';
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
