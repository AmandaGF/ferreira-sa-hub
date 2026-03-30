<?php
/**
 * Ferreira & Sá Hub — Alvarás
 * Cálculo automático: valor_honorarios = valor * honorarios_pct
 *                     repasse_cliente = valor - valor_honorarios
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_role('admin','gestao','operacional')) { redirect(url('modules/dashboard/')); }

$pageTitle = 'Alvarás';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $clientId = (int)($_POST['client_id'] ?? 0) ?: null;
        $numProcesso = clean_str($_POST['numero_processo'] ?? '', 50);
        $dataPet = $_POST['data_peticionamento'] ?? null;
        $valor = (float)str_replace(array('.', ','), array('', '.'), $_POST['valor'] ?? '0');
        $honPct = (float)str_replace(',', '.', $_POST['honorarios_pct'] ?? '0');
        $natureza = clean_str($_POST['natureza'] ?? '', 100);
        $prazoPgto = clean_str($_POST['prazo_pagamento'] ?? '', 50);
        $estadoUf = clean_str($_POST['estado_uf'] ?? '', 30);
        $obs = clean_str($_POST['observacoes'] ?? '', 500);

        $valorHon = round($valor * ($honPct / 100), 2);
        $repasse = round($valor - $valorHon, 2);

        $pdo->prepare("INSERT INTO alvaras (client_id, numero_processo, data_peticionamento, valor, honorarios_pct, valor_honorarios, repasse_cliente, natureza, prazo_pagamento, observacoes, estado_uf) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute(array($clientId, $numProcesso ?: null, $dataPet ?: null, $valor, $honPct, $valorHon, $repasse, $natureza ?: null, $prazoPgto ?: null, $obs ?: null, $estadoUf ?: null));
        flash_set('success', 'Alvará registrado!');
        redirect(module_url('alvaras'));
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) { $pdo->prepare("DELETE FROM alvaras WHERE id = ?")->execute(array($id)); flash_set('success', 'Removido.'); }
        redirect(module_url('alvaras'));
    }
}

$alvaras = $pdo->query(
    "SELECT a.*, c.name as client_name
     FROM alvaras a LEFT JOIN clients c ON c.id = a.client_id
     ORDER BY a.created_at DESC"
)->fetchAll();

$totalValor = 0; $totalHon = 0; $totalRepasse = 0;
foreach ($alvaras as $a) { $totalValor += (float)$a['valor']; $totalHon += (float)$a['valor_honorarios']; $totalRepasse += (float)$a['repasse_cliente']; }

$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem;">
    <div style="display:flex;gap:.75rem;">
        <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:.5rem 1rem;">
            <div style="font-size:.65rem;color:var(--text-muted);text-transform:uppercase;">Total</div>
            <div style="font-size:1.1rem;font-weight:800;color:var(--petrol-900);">R$ <?= number_format($totalValor, 2, ',', '.') ?></div>
        </div>
        <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:.5rem 1rem;">
            <div style="font-size:.65rem;color:var(--text-muted);text-transform:uppercase;">Honorários</div>
            <div style="font-size:1.1rem;font-weight:800;color:var(--rose-dark);">R$ <?= number_format($totalHon, 2, ',', '.') ?></div>
        </div>
        <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:.5rem 1rem;">
            <div style="font-size:.65rem;color:var(--text-muted);text-transform:uppercase;">Repasse</div>
            <div style="font-size:1.1rem;font-weight:800;color:var(--success);">R$ <?= number_format($totalRepasse, 2, ',', '.') ?></div>
        </div>
    </div>
    <button class="btn btn-primary btn-sm" data-modal="modalAlvara">+ Novo Alvará</button>
</div>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Cliente</th><th>Nº Processo</th><th>Valor</th><th>%</th><th>Honorários</th><th>Repasse</th><th>Natureza</th><th>UF</th><th>Ações</th></tr></thead>
            <tbody>
                <?php if (empty($alvaras)): ?>
                    <tr><td colspan="9" class="text-center text-muted" style="padding:2rem;">Nenhum alvará registrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($alvaras as $a): ?>
                    <tr>
                        <td class="font-bold text-sm"><?= e($a['client_name'] ?: '—') ?></td>
                        <td class="text-sm"><?= e($a['numero_processo'] ?: '—') ?></td>
                        <td class="text-sm font-bold">R$ <?= number_format((float)$a['valor'], 2, ',', '.') ?></td>
                        <td class="text-sm"><?= $a['honorarios_pct'] ?>%</td>
                        <td class="text-sm" style="color:var(--rose-dark);font-weight:600;">R$ <?= number_format((float)$a['valor_honorarios'], 2, ',', '.') ?></td>
                        <td class="text-sm" style="color:var(--success);font-weight:600;">R$ <?= number_format((float)$a['repasse_cliente'], 2, ',', '.') ?></td>
                        <td class="text-sm"><?= e($a['natureza'] ?: '—') ?></td>
                        <td class="text-sm"><?= e($a['estado_uf'] ?: '—') ?></td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <?= csrf_input() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm" style="font-size:.6rem;padding:.15rem .3rem;opacity:.4;" data-confirm="Excluir?">🗑️</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="modalAlvara">
    <div class="modal">
        <div class="modal-header"><h3>Novo Alvará</h3><button class="modal-close">&times;</button></div>
        <div class="modal-body">
            <form method="POST">
                <?= csrf_input() ?><input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label class="form-label">Cliente</label>
                    <select name="client_id" class="form-select"><option value="">—</option>
                        <?php foreach ($clients as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Nº Processo</label><input type="text" name="numero_processo" class="form-input"></div>
                    <div class="form-group"><label class="form-label">Data peticionamento</label><input type="date" name="data_peticionamento" class="form-input"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Valor (R$) *</label><input type="text" name="valor" class="form-input" required placeholder="10.000,00"></div>
                    <div class="form-group"><label class="form-label">% Honorários *</label><input type="text" name="honorarios_pct" class="form-input" required placeholder="30" value="30"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Natureza</label><input type="text" name="natureza" class="form-input" placeholder="Alimentar, FGTS..."></div>
                    <div class="form-group"><label class="form-label">UF</label><input type="text" name="estado_uf" class="form-input" placeholder="RJ" maxlength="2"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Prazo pagamento</label><input type="text" name="prazo_pagamento" class="form-input"></div>
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
