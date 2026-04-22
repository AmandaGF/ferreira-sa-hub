<?php
/**
 * Ferreira & Sá Hub — Ofícios Enviados
 * Alerta visual para ofícios sem retorno_ar após 15 dias do data_envio
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_role('admin','gestao','operacional')) { redirect(url('modules/dashboard/')); }

$pageTitle = 'Ofícios Enviados';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $clientId = (int)($_POST['client_id'] ?? 0) ?: null;
        $numProcesso = clean_str($_POST['numero_processo'] ?? '', 50);
        $empregador = clean_str($_POST['empregador'] ?? '', 250);
        $dataEnvio = $_POST['data_envio'] ?? null;
        $codRastreio = clean_str($_POST['cod_rastreio'] ?? '', 100);
        $plataforma = clean_str($_POST['plataforma'] ?? '', 50);
        $obs = clean_str($_POST['observacoes'] ?? '', 500);

        $pdo->prepare("INSERT INTO oficios_enviados (client_id, numero_processo, empregador, data_envio, cod_rastreio, plataforma, observacoes) VALUES (?,?,?,?,?,?,?)")
            ->execute(array($clientId, $numProcesso ?: null, $empregador ?: null, $dataEnvio ?: null, $codRastreio ?: null, $plataforma ?: null, $obs ?: null));
        flash_set('success', 'Ofício registrado!');
        redirect(module_url('oficios'));
    }

    if ($action === 'registrar_ar') {
        $id = (int)($_POST['id'] ?? 0);
        $retorno = clean_str($_POST['retorno_ar'] ?? '', 100);
        if ($id) {
            $pdo->prepare("UPDATE oficios_enviados SET retorno_ar = ? WHERE id = ?")->execute(array($retorno, $id));
            flash_set('success', 'AR registrado!');
        }
        redirect(module_url('oficios'));
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) { $pdo->prepare("DELETE FROM oficios_enviados WHERE id = ?")->execute(array($id)); flash_set('success', 'Ofício removido.'); }
        redirect(module_url('oficios'));
    }
}

$oficios = $pdo->query(
    "SELECT o.*, c.name as client_name
     FROM oficios_enviados o
     LEFT JOIN clients c ON c.id = o.client_id
     ORDER BY o.data_envio DESC"
)->fetchAll();

$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
    <h3 style="font-size:.95rem;font-weight:700;color:var(--petrol-900);">Ofícios de Pensão Alimentícia</h3>
    <a href="<?= module_url('oficios', 'novo_oficio.php') ?>" class="btn btn-primary btn-sm">+ Novo Ofício</a>
</div>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Cliente</th><th>Empregador</th><th>Nº Processo</th><th>Envio</th><th>Rastreio</th><th>AR</th><th>Ações</th></tr></thead>
            <tbody>
                <?php if (empty($oficios)): ?>
                    <tr><td colspan="7" class="text-center text-muted" style="padding:2rem;">Nenhum ofício registrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($oficios as $o):
                        $diasEnvio = $o['data_envio'] ? (int)((strtotime('today') - strtotime($o['data_envio'])) / 86400) : 0;
                        $semAR = !$o['retorno_ar'] && $diasEnvio > 15;
                    ?>
                    <tr style="<?= $semAR ? 'background:#fef2f2;' : '' ?>">
                        <td class="font-bold"><?= e($o['client_name'] ?: '—') ?></td>
                        <td class="text-sm"><?= e($o['empregador'] ?: '—') ?></td>
                        <td class="text-sm"><?= e($o['numero_processo'] ?: '—') ?></td>
                        <td class="text-sm"><?= $o['data_envio'] ? date('d/m/Y', strtotime($o['data_envio'])) : '—' ?></td>
                        <td class="text-sm"><?= e($o['cod_rastreio'] ?: '—') ?></td>
                        <td>
                            <?php if ($o['retorno_ar']): ?>
                                <span class="badge badge-success"><?= e($o['retorno_ar']) ?></span>
                            <?php elseif ($semAR): ?>
                                <span class="badge badge-danger">⚠️ Sem AR (+<?= $diasEnvio ?>d)</span>
                            <?php else: ?>
                                <span class="text-muted text-sm">Aguardando</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;gap:.2rem;align-items:center;flex-wrap:wrap;">
                                <a href="<?= module_url('oficios', 'novo_oficio.php?id=' . (int)$o['id']) ?>" class="btn btn-primary btn-sm" style="font-size:.65rem;padding:.2rem .45rem;background:#3730a3;" title="Abrir / editar o ofício com todos os dados e templates">✏️ Abrir</a>
                                <?php if (!$o['retorno_ar']): ?>
                                <form method="POST" style="display:inline-flex;gap:.2rem;">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="registrar_ar">
                                    <input type="hidden" name="id" value="<?= $o['id'] ?>">
                                    <input type="text" name="retorno_ar" class="form-input" style="width:70px;font-size:.7rem;padding:.2rem .4rem;" placeholder="AR...">
                                    <button type="submit" class="btn btn-success btn-sm" style="font-size:.65rem;padding:.2rem .35rem;" title="Registrar número do AR recebido">✓</button>
                                </form>
                                <?php endif; ?>
                                <form method="POST" style="display:inline;">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $o['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm" style="font-size:.6rem;padding:.15rem .3rem;opacity:.4;" data-confirm="Excluir ofício #<?= (int)$o['id'] ?>?" title="Excluir">🗑️</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="modalOficio">
    <div class="modal">
        <div class="modal-header"><h3>Novo Ofício</h3><button class="modal-close">&times;</button></div>
        <div class="modal-body">
            <form method="POST">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label class="form-label">Cliente</label>
                    <select name="client_id" class="form-select">
                        <option value="">— Selecionar —</option>
                        <?php foreach ($clients as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Empregador</label><input type="text" name="empregador" class="form-input"></div>
                    <div class="form-group"><label class="form-label">Nº Processo</label><input type="text" name="numero_processo" class="form-input"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Data de envio</label><input type="date" name="data_envio" class="form-input" value="<?= date('Y-m-d') ?>"></div>
                    <div class="form-group"><label class="form-label">Cód. rastreio</label><input type="text" name="cod_rastreio" class="form-input"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Plataforma</label><input type="text" name="plataforma" class="form-input" placeholder="Correios, TJRJ..."></div>
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

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
