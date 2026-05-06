<?php
/**
 * Admin — gestão das indicações dos colaboradores.
 * Acesso: SOMENTE admin.
 *
 * Fluxo:
 *  - lista todas as indicações com filtro por mês + status + colaborador
 *  - admin atualiza status (lead → em_negociacao → contrato_fechado / perdido)
 *  - ao marcar contrato_fechado, define valor_contrato + percentual → calcula valor_a_receber
 *  - anotação visível pra colaboradora
 *  - notifica colaboradora ao mudar status
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_role('admin');

$pdo = db();

// Self-heal
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS colaboradores_indicacoes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        colaborador_id INT NOT NULL,
        indicado_nome VARCHAR(150) NOT NULL,
        indicado_telefone VARCHAR(20) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'lead',
        valor_contrato DECIMAL(10,2) NULL,
        percentual DECIMAL(5,2) NULL DEFAULT 5.00,
        valor_a_receber DECIMAL(10,2) NULL,
        data_indicacao DATE NOT NULL,
        data_fechamento DATE NULL,
        observacoes TEXT NULL,
        anotacao_admin TEXT NULL,
        criado_por_admin TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_col (colaborador_id),
        INDEX idx_status (status),
        INDEX idx_fech (data_fechamento)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Handler: atualizar indicação
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'atualizar' && $id) {
        $status = $_POST['status'] ?? 'lead';
        $valorContrato = trim($_POST['valor_contrato'] ?? '');
        $percentual = trim($_POST['percentual'] ?? '5');
        $anotacao = trim($_POST['anotacao_admin'] ?? '');
        $dataFechamento = trim($_POST['data_fechamento'] ?? '');

        // Normalizar valores BR (1.234,56 → 1234.56)
        $valorContratoNum = $valorContrato !== '' ? (float)str_replace(',', '.', str_replace('.', '', $valorContrato)) : null;
        $percentualNum = $percentual !== '' ? (float)str_replace(',', '.', $percentual) : 5.0;

        // Calcula valor a receber se contrato fechado
        $valorAReceber = null;
        if ($status === 'contrato_fechado' && $valorContratoNum !== null && $percentualNum > 0) {
            $valorAReceber = round($valorContratoNum * ($percentualNum / 100.0), 2);
        }

        // Data de fechamento auto se status = contrato_fechado e nada informado
        if ($status === 'contrato_fechado' && !$dataFechamento) {
            $dataFechamento = date('Y-m-d');
        } elseif ($status !== 'contrato_fechado') {
            $dataFechamento = null;
        }

        try {
            $pdo->prepare("UPDATE colaboradores_indicacoes SET
                    status = ?, valor_contrato = ?, percentual = ?, valor_a_receber = ?,
                    data_fechamento = ?, anotacao_admin = ?
                  WHERE id = ?")
                ->execute(array($status, $valorContratoNum, $percentualNum, $valorAReceber, $dataFechamento, $anotacao, $id));

            // Notifica colaboradora (se mudou)
            try {
                $st = $pdo->prepare("SELECT i.*, c.nome_completo FROM colaboradores_indicacoes i
                                     LEFT JOIN colaboradores_onboarding c ON c.id = i.colaborador_id
                                     WHERE i.id = ?");
                $st->execute(array($id));
                $reg = $st->fetch();
                // só registra audit
                if (function_exists('audit_log')) {
                    audit_log('indicacao.update', 'indicacao=' . $id . ' status=' . $status);
                }
            } catch (Exception $e) {}

            flash_set('success', 'Indicação atualizada.');
        } catch (Exception $e) {
            flash_set('error', 'Erro: ' . $e->getMessage());
        }
        redirect(module_url('admin', 'onboarding_indicacoes.php'));
    }
}

$statusLabels = array(
    'lead' => '👀 Lead',
    'em_negociacao' => '💬 Em negociação',
    'contrato_fechado' => '✅ Contrato fechado',
    'perdido' => '✕ Perdido',
);

// Filtros
$fStatus = $_GET['status'] ?? '';
$fColab = (int)($_GET['colab'] ?? 0);
$mesFiltro = $_GET['mes'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mesFiltro)) $mesFiltro = date('Y-m');
$mesIni = $mesFiltro . '-01';
$mesFim = date('Y-m-t', strtotime($mesIni));
$mesAnt = date('Y-m', strtotime($mesIni . ' -1 month'));
$mesProx = date('Y-m', strtotime($mesIni . ' +1 month'));

$meses = array('janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro');
$mesLabel = $meses[(int)substr($mesFiltro, 5, 2) - 1] . ' de ' . substr($mesFiltro, 0, 4);

// Lista colaboradoras pra filtro
$colabs = array();
try {
    $colabs = $pdo->query("SELECT id, nome_completo FROM colaboradores_onboarding WHERE status != 'arquivado' ORDER BY nome_completo")->fetchAll();
} catch (Exception $e) {}

// Query
$where = "((i.data_indicacao BETWEEN :ini AND :fim) OR (i.data_fechamento BETWEEN :ini AND :fim))";
$params = array(':ini' => $mesIni, ':fim' => $mesFim);
if ($fStatus && isset($statusLabels[$fStatus])) {
    $where .= " AND i.status = :st";
    $params[':st'] = $fStatus;
}
if ($fColab) {
    $where .= " AND i.colaborador_id = :colab";
    $params[':colab'] = $fColab;
}

$lista = array();
try {
    $st = $pdo->prepare("SELECT i.*, c.nome_completo
                         FROM colaboradores_indicacoes i
                         LEFT JOIN colaboradores_onboarding c ON c.id = i.colaborador_id
                         WHERE $where
                         ORDER BY
                            CASE i.status WHEN 'lead' THEN 1 WHEN 'em_negociacao' THEN 2 WHEN 'contrato_fechado' THEN 3 ELSE 4 END,
                            i.data_indicacao DESC");
    $st->execute($params);
    $lista = $st->fetchAll();
} catch (Exception $e) {}

// Totais do mês
$totMes = array('total' => 0, 'fechados' => 0, 'a_pagar' => 0.0);
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM colaboradores_indicacoes WHERE data_indicacao BETWEEN ? AND ?");
    $st->execute(array($mesIni, $mesFim));
    $totMes['total'] = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*), IFNULL(SUM(valor_a_receber),0) FROM colaboradores_indicacoes
                         WHERE status = 'contrato_fechado' AND data_fechamento BETWEEN ? AND ?");
    $st->execute(array($mesIni, $mesFim));
    $r = $st->fetch(PDO::FETCH_NUM);
    $totMes['fechados'] = (int)$r[0];
    $totMes['a_pagar'] = (float)$r[1];
} catch (Exception $e) {}

$pageTitle = 'Indicações dos Colaboradores';
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.i-card { background:#fff; border-radius:14px; padding:1.5rem 1.6rem; margin-bottom:1.2rem; box-shadow:0 2px 8px rgba(0,0,0,.04); border:1px solid #e5e7eb; }
.i-card h3 { font-size:1.05rem; color:#052228; padding-bottom:.5rem; border-bottom:2px solid #d7ab90; margin-bottom:1rem; }
.tot-grid { display:grid; gap:.85rem; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); margin-bottom:1.2rem; }
.tot-card { background:#fff; border-radius:14px; padding:1.2rem 1.3rem; box-shadow:0 2px 8px rgba(0,0,0,.04); border-top:4px solid #B87333; }
.tot-card.verde { border-top-color:#10b981; }
.tot-card.azul { border-top-color:#3b82f6; }
.tot-card .lbl { font-size:.7rem; letter-spacing:1.5px; font-weight:700; color:#6a3c2c; text-transform:uppercase; }
.tot-card .val { font-size:2rem; font-weight:900; color:#052228; margin-top:.3rem; line-height:1; }
.tot-card.verde .val { color:#047857; }
.tot-card .sub { font-size:.78rem; color:#6b7280; margin-top:.2rem; }

.mes-nav { display:flex; align-items:center; justify-content:space-between; gap:.5rem; background:#fff; border-radius:14px; padding:.85rem 1.1rem; margin-bottom:1rem; box-shadow:0 2px 8px rgba(0,0,0,.04); }
.mes-nav a { background:#fff7ed; color:#6a3c2c; padding:.45rem .9rem; border-radius:8px; text-decoration:none; font-weight:700; font-size:.85rem; }
.mes-nav .central { font-weight:800; color:#052228; text-transform:capitalize; font-size:1.05rem; }

.i-filtros { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1rem; align-items:center; }
.i-filtros select { padding:.45rem .75rem; border-radius:8px; border:1.5px solid #e5e7eb; font-size:.82rem; background:#fff; }

.i-item { background:#fff; border-radius:12px; padding:1.1rem 1.3rem; margin-bottom:.85rem; border:1.5px solid #e5e7eb; }
.i-item.lead { border-color:#fcd34d; background:#fffbeb; }
.i-item.em_negociacao { border-color:#93c5fd; background:#eff6ff; }
.i-item.contrato_fechado { border-color:#34d399; background:#ecfdf5; }
.i-item.perdido { border-color:#fca5a5; background:#fef2f2; opacity:.85; }
.i-item .head { display:flex; justify-content:space-between; align-items:flex-start; gap:.6rem; flex-wrap:wrap; }
.i-item .nome { font-weight:700; color:#052228; font-size:1rem; }
.i-item .meta { font-size:.74rem; color:#6b7280; margin-top:.25rem; }
.i-item .obs { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:.55rem .8rem; margin-top:.55rem; font-size:.84rem; line-height:1.5; }
.i-item .receber { background:#d1fae5; color:#065f46; padding:.4rem .8rem; border-radius:8px; font-weight:800; font-size:.92rem; margin-top:.5rem; display:inline-block; }
.status-pill { display:inline-block; padding:.2rem .65rem; border-radius:12px; font-size:.7rem; font-weight:700; }
.status-pill.lead { background:#fef3c7; color:#92400e; }
.status-pill.em_negociacao { background:#dbeafe; color:#1e40af; }
.status-pill.contrato_fechado { background:#d1fae5; color:#065f46; }
.status-pill.perdido { background:#fee2e2; color:#991b1b; }

.editar-form { background:#f9fafb; border:1px dashed #d1d5db; border-radius:8px; padding:.85rem; margin-top:.7rem; display:grid; gap:.5rem; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); }
.editar-form label { font-size:.72rem; font-weight:700; color:#374151; }
.editar-form input, .editar-form select, .editar-form textarea { width:100%; padding:.45rem .65rem; border:1.5px solid #e5e7eb; border-radius:6px; font-size:.82rem; font-family:inherit; }
.editar-form .full { grid-column:1/-1; }
.editar-form button { background:#052228; color:#fff; border:0; padding:.5rem 1.2rem; border-radius:8px; font-weight:700; cursor:pointer; font-size:.8rem; }
.btn-toggle { background:#fff; border:1.5px solid #B87333; color:#B87333; padding:.35rem .8rem; border-radius:6px; font-size:.74rem; font-weight:700; cursor:pointer; margin-top:.5rem; }
</style>

<div class="card">
    <div class="card-header">
        <h3>💸 Indicações dos colaboradores</h3>
        <p style="font-size:.82rem;color:#6b7280;margin-top:.3rem;">Acompanhe quem cada pessoa indicou e atualize o status. Quando virar contrato fechado, defina o valor + percentual e a colaboradora vê o quanto vai receber.</p>
    </div>
</div>

<!-- Totais -->
<div class="tot-grid" style="margin-top:1rem;">
    <div class="tot-card azul">
        <div class="lbl">Indicações no mês</div>
        <div class="val"><?= $totMes['total'] ?></div>
        <div class="sub">total da equipe</div>
    </div>
    <div class="tot-card">
        <div class="lbl">Contratos fechados</div>
        <div class="val"><?= $totMes['fechados'] ?></div>
        <div class="sub">no mês</div>
    </div>
    <div class="tot-card verde">
        <div class="lbl">A pagar (mês)</div>
        <div class="val">R$ <?= number_format($totMes['a_pagar'], 2, ',', '.') ?></div>
        <div class="sub">soma das comissões</div>
    </div>
</div>

<!-- Nav mensal -->
<div class="mes-nav">
    <a href="?mes=<?= $mesAnt ?><?= $fStatus ? '&status=' . urlencode($fStatus) : '' ?><?= $fColab ? '&colab=' . $fColab : '' ?>">◀ <?= e($meses[(int)substr($mesAnt,5,2)-1]) ?>/<?= substr($mesAnt,0,4) ?></a>
    <span class="central"><?= e($mesLabel) ?></span>
    <a href="?mes=<?= $mesProx ?><?= $fStatus ? '&status=' . urlencode($fStatus) : '' ?><?= $fColab ? '&colab=' . $fColab : '' ?>"><?= e($meses[(int)substr($mesProx,5,2)-1]) ?>/<?= substr($mesProx,0,4) ?> ▶</a>
</div>

<!-- Filtros -->
<div class="i-card">
    <form method="GET" class="i-filtros">
        <input type="hidden" name="mes" value="<?= e($mesFiltro) ?>">
        <select name="status" onchange="this.form.submit()">
            <option value="">Todos os status</option>
            <?php foreach ($statusLabels as $k => $l): ?>
                <option value="<?= e($k) ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="colab" onchange="this.form.submit()">
            <option value="0">Todas as colaboradoras</option>
            <?php foreach ($colabs as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $fColab === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['nome_completo']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php if (empty($lista)): ?>
        <p style="color:#6b7280;font-size:.85rem;text-align:center;padding:2rem;">Nenhuma indicação neste filtro.</p>
    <?php else: ?>
        <?php foreach ($lista as $i): ?>
        <div class="i-item <?= e($i['status']) ?>">
            <div class="head">
                <div style="flex:1;min-width:240px;">
                    <div class="nome">👤 <?= e($i['indicado_nome']) ?></div>
                    <div class="meta">
                        Indicada por <strong><?= e($i['nome_completo']) ?></strong>
                        <?php if ($i['indicado_telefone']): ?> &middot; 📞 <?= e($i['indicado_telefone']) ?><?php endif; ?>
                        &middot; em <?= e(date('d/m/Y', strtotime($i['data_indicacao']))) ?>
                        <?php if ($i['data_fechamento']): ?> &middot; ✅ Fechado em <?= e(date('d/m/Y', strtotime($i['data_fechamento']))) ?><?php endif; ?>
                    </div>
                </div>
                <span class="status-pill <?= e($i['status']) ?>"><?= e($statusLabels[$i['status']] ?? $i['status']) ?></span>
            </div>

            <?php if (!empty($i['observacoes'])): ?>
                <div class="obs"><strong>📝 Obs da colaboradora:</strong><br><?= nl2br(e($i['observacoes'])) ?></div>
            <?php endif; ?>

            <?php if ($i['status'] === 'contrato_fechado' && $i['valor_a_receber']): ?>
                <div class="receber">💸 Comissão: R$ <?= number_format($i['valor_a_receber'], 2, ',', '.') ?>
                <?php if ($i['percentual'] && $i['valor_contrato']): ?>
                    <span style="font-weight:500;font-size:.78rem;opacity:.85;">
                        (<?= number_format($i['percentual'], 0, ',', '.') ?>% sobre R$ <?= number_format($i['valor_contrato'], 2, ',', '.') ?>)
                    </span>
                <?php endif; ?>
                </div>
            <?php endif; ?>

            <button type="button" class="btn-toggle" onclick="document.getElementById('edit-<?= (int)$i['id'] ?>').style.display = (document.getElementById('edit-<?= (int)$i['id'] ?>').style.display === 'grid' ? 'none' : 'grid')">⚙️ Atualizar</button>

            <form method="POST" id="edit-<?= (int)$i['id'] ?>" class="editar-form" style="display:none;">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="atualizar">
                <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                <div>
                    <label>Status</label>
                    <select name="status">
                        <?php foreach ($statusLabels as $k => $l): ?>
                            <option value="<?= e($k) ?>" <?= $i['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Valor do contrato (R$)</label>
                    <input name="valor_contrato" placeholder="0,00" value="<?= $i['valor_contrato'] !== null ? number_format($i['valor_contrato'], 2, ',', '.') : '' ?>">
                </div>
                <div>
                    <label>Percentual (%)</label>
                    <input name="percentual" placeholder="5" value="<?= $i['percentual'] !== null ? number_format($i['percentual'], 2, ',', '.') : '5' ?>">
                </div>
                <div>
                    <label>Data de fechamento</label>
                    <input type="date" name="data_fechamento" value="<?= e($i['data_fechamento']) ?>">
                </div>
                <div class="full">
                    <label>Anotação (visível pra colaboradora)</label>
                    <textarea name="anotacao_admin" rows="2" placeholder="Ex: estamos em negociação, contrato será assinado dia X..."><?= e($i['anotacao_admin']) ?></textarea>
                </div>
                <div class="full">
                    <button type="submit">💾 Salvar atualização</button>
                </div>
            </form>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
