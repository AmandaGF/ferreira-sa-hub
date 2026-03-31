<?php
/**
 * Ferreira & Sá Hub — Parceiros (Advogados externos)
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('gestao')) { redirect(url('modules/dashboard/')); }

$pageTitle = 'Parceiros';
$pdo = db();

// AJAX busca contatos
if (isset($_GET['ajax_busca'])) {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo '[]'; exit; }
    $stmt = $pdo->prepare("SELECT id, name, phone, email FROM clients WHERE name LIKE ? ORDER BY name LIMIT 10");
    $stmt->execute(array('%' . $q . '%'));
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $nome = clean_str($_POST['nome'] ?? '', 150);
        $oab = clean_str($_POST['oab'] ?? '', 30);
        $area = clean_str($_POST['area'] ?? '', 60);
        $email = trim($_POST['email'] ?? '');
        $tel = clean_str($_POST['telefone'] ?? '', 30);
        $pct = (float)str_replace(',', '.', $_POST['pct_honorarios'] ?? '0');
        $obs = clean_str($_POST['observacoes'] ?? '', 500);

        if ($nome) {
            if ($action === 'update' && $id) {
                $pdo->prepare("UPDATE parceiros SET nome=?, oab=?, area=?, email=?, telefone=?, pct_honorarios=?, observacoes=? WHERE id=?")
                    ->execute(array($nome, $oab ?: null, $area ?: null, $email ?: null, $tel ?: null, $pct ?: null, $obs ?: null, $id));
                flash_set('success', 'Parceiro atualizado!');
            } else {
                $pdo->prepare("INSERT INTO parceiros (nome, oab, area, email, telefone, pct_honorarios, observacoes) VALUES (?,?,?,?,?,?,?)")
                    ->execute(array($nome, $oab ?: null, $area ?: null, $email ?: null, $tel ?: null, $pct ?: null, $obs ?: null));
                flash_set('success', 'Parceiro cadastrado!');
            }
        }
        redirect(module_url('parceiros'));
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) { $pdo->prepare("UPDATE parceiros SET ativo = NOT ativo WHERE id = ?")->execute(array($id)); }
        redirect(module_url('parceiros'));
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            // Desvincular cases primeiro
            $pdo->prepare("UPDATE cases SET parceiro_id = NULL WHERE parceiro_id = ?")->execute(array($id));
            $pdo->prepare("DELETE FROM parceiros WHERE id = ?")->execute(array($id));
            flash_set('success', 'Parceiro excluído.');
        }
        redirect(module_url('parceiros'));
    }
}

$parceiros = $pdo->query("SELECT * FROM parceiros ORDER BY ativo DESC, nome ASC")->fetchAll();

$processosParceiro = array();
try {
    $rows = $pdo->query("SELECT parceiro_id, COUNT(*) as total FROM cases WHERE parceiro_id IS NOT NULL AND status NOT IN ('concluido','arquivado') GROUP BY parceiro_id")->fetchAll();
    foreach ($rows as $r) { $processosParceiro[(int)$r['parceiro_id']] = (int)$r['total']; }
} catch (Exception $e) {}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
    <h3 style="font-size:.95rem;font-weight:700;color:var(--petrol-900);">Advogados Parceiros</h3>
    <button class="btn btn-primary btn-sm" data-modal="modalParceiro">+ Novo Parceiro</button>
</div>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Nome</th><th>OAB</th><th>Área</th><th>Contato</th><th>% Hon.</th><th>Processos</th><th>Status</th><th>Ações</th></tr></thead>
            <tbody>
                <?php if (empty($parceiros)): ?>
                    <tr><td colspan="8" class="text-center text-muted" style="padding:2rem;">Nenhum parceiro cadastrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($parceiros as $p): ?>
                    <tr style="<?= !$p['ativo'] ? 'opacity:.5;' : '' ?>">
                        <td class="font-bold"><?= e($p['nome']) ?></td>
                        <td class="text-sm"><?= e($p['oab'] ?: '—') ?></td>
                        <td class="text-sm"><?= e($p['area'] ?: '—') ?></td>
                        <td class="text-sm">
                            <?php if ($p['telefone']): ?><a href="https://wa.me/55<?= preg_replace('/\D/', '', $p['telefone']) ?>" target="_blank" style="color:var(--success);"><?= e($p['telefone']) ?></a><?php endif; ?>
                            <?php if ($p['email']): ?><br><span class="text-muted"><?= e($p['email']) ?></span><?php endif; ?>
                        </td>
                        <td class="text-sm"><?= $p['pct_honorarios'] ? $p['pct_honorarios'] . '%' : '—' ?></td>
                        <td><span class="badge badge-info"><?= isset($processosParceiro[(int)$p['id']]) ? $processosParceiro[(int)$p['id']] : 0 ?></span></td>
                        <td><span class="badge badge-<?= $p['ativo'] ? 'success' : 'gestao' ?>"><?= $p['ativo'] ? 'Ativo' : 'Inativo' ?></span></td>
                        <td style="white-space:nowrap;">
                            <form method="POST" style="display:inline;">
                                <?= csrf_input() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm" style="font-size:.65rem;padding:.2rem .35rem;" title="<?= $p['ativo'] ? 'Desativar' : 'Ativar' ?>"><?= $p['ativo'] ? '⏸️' : '▶️' ?></button>
                            </form>
                            <form method="POST" style="display:inline;" data-confirm="Excluir parceiro <?= e($p['nome']) ?>?">
                                <?= csrf_input() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm" style="font-size:.65rem;padding:.2rem .35rem;color:#dc2626;border-color:#dc2626;" title="Excluir">🗑️</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Novo Parceiro -->
<div class="modal-overlay" id="modalParceiro">
    <div class="modal">
        <div class="modal-header"><h3>Novo Parceiro</h3><button class="modal-close">&times;</button></div>
        <div class="modal-body">
            <form method="POST">
                <?= csrf_input() ?><input type="hidden" name="action" value="create">
                <div class="form-group" style="position:relative;">
                    <label class="form-label">Buscar na Agenda de Contatos</label>
                    <input type="text" id="buscaContato" class="form-input" placeholder="Digite o nome para buscar..." autocomplete="off" style="border-color:var(--rose);">
                    <div id="buscaContatoRes" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:20;background:#fff;border:1.5px solid var(--border);border-radius:0 0 8px 8px;max-height:160px;overflow-y:auto;box-shadow:0 4px 12px rgba(0,0,0,.15);"></div>
                </div>
                <div class="form-group"><label class="form-label">Nome *</label><input type="text" name="nome" id="parceiroNome" class="form-input" required></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">OAB</label><input type="text" name="oab" class="form-input"></div>
                    <div class="form-group"><label class="form-label">Área</label><input type="text" name="area" class="form-input" placeholder="Trabalhista, Criminal..."></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">E-mail</label><input type="email" name="email" id="parceiroEmail" class="form-input"></div>
                    <div class="form-group"><label class="form-label">Telefone</label><input type="text" name="telefone" id="parceiroTel" class="form-input"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">% Honorários</label><input type="text" name="pct_honorarios" class="form-input" placeholder="30"></div>
                    <div class="form-group"><label class="form-label">Observações</label><input type="text" name="observacoes" class="form-input"></div>
                </div>
                <div class="modal-footer" style="border:none;padding:1rem 0 0;">
                    <button type="button" class="btn btn-outline" data-modal-close>Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function() {
    var input = document.getElementById('buscaContato');
    var results = document.getElementById('buscaContatoRes');
    if (!input) return;
    var timer;
    input.addEventListener('input', function() {
        clearTimeout(timer);
        var q = this.value.trim();
        if (q.length < 2) { results.style.display = 'none'; return; }
        timer = setTimeout(function() {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', '<?= module_url("parceiros") ?>?ajax_busca=1&q=' + encodeURIComponent(q));
            xhr.onload = function() {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (!data.length) { results.innerHTML = '<div style="padding:8px 12px;font-size:.82rem;color:#999;">Nenhum contato encontrado</div>'; results.style.display = 'block'; return; }
                    results.innerHTML = data.map(function(c) {
                        return '<div style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #eee;font-size:.82rem;" onmouseover="this.style.background=\'rgba(215,171,144,.15)\'" onmouseout="this.style.background=\'#fff\'" onclick="preencherParceiro(\'' + (c.name||'').replace(/'/g,"\\'") + '\',\'' + (c.email||'').replace(/'/g,"\\'") + '\',\'' + (c.phone||'').replace(/'/g,"\\'") + '\')"><strong>' + (c.name||'') + '</strong>' + (c.phone ? ' — ' + c.phone : '') + (c.email ? ' — ' + c.email : '') + '</div>';
                    }).join('');
                    results.style.display = 'block';
                } catch(e) { results.style.display = 'none'; }
            };
            xhr.send();
        }, 300);
    });
    document.addEventListener('click', function(e) {
        if (!input.contains(e.target) && !results.contains(e.target)) results.style.display = 'none';
    });
})();

function preencherParceiro(nome, email, tel) {
    document.getElementById('buscaContatoRes').style.display = 'none';
    document.getElementById('buscaContato').value = nome;
    document.getElementById('parceiroNome').value = nome;
    if (email) document.getElementById('parceiroEmail').value = email;
    if (tel) document.getElementById('parceiroTel').value = tel;
}
</script>
<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
