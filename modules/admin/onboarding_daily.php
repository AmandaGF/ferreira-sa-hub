<?php
/**
 * Admin — visualização das Dailies das colaboradoras.
 * Acesso: SOMENTE admin.
 *
 * Permite acompanhar o que cada colaboradora está priorizando,
 * humor, tarefas concluídas e reflexões. Read-only (admin não edita).
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_role('admin');

$pdo = db();

// Self-heal (mesmo schema da página pública)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS colaboradores_daily (
        id INT AUTO_INCREMENT PRIMARY KEY,
        colaborador_id INT NOT NULL,
        data DATE NOT NULL,
        humor VARCHAR(20) NULL,
        foco_principal VARCHAR(300) NULL,
        tarefas_json LONGTEXT NULL,
        notas TEXT NULL,
        aprendizados TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_col_data (colaborador_id, data),
        INDEX idx_data (data)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Filtros
$fColab = (int)($_GET['colab'] ?? 0);
$fData = $_GET['data'] ?? '';
$fInicio = $_GET['inicio'] ?? '';
$fFim = $_GET['fim'] ?? '';

if (!$fInicio && !$fFim && !$fData) {
    // Default: últimos 14 dias
    $fInicio = date('Y-m-d', strtotime('-13 days'));
    $fFim = date('Y-m-d');
}

// Lista colaboradores
$colabs = array();
try {
    $colabs = $pdo->query("SELECT id, nome_completo FROM colaboradores_onboarding WHERE status != 'arquivado' ORDER BY nome_completo")->fetchAll();
} catch (Exception $e) {}

// Query
$where = '1=1';
$params = array();
if ($fColab) { $where .= ' AND d.colaborador_id = ?'; $params[] = $fColab; }
if ($fData)  { $where .= ' AND d.data = ?';          $params[] = $fData; }
if ($fInicio && !$fData) { $where .= ' AND d.data >= ?'; $params[] = $fInicio; }
if ($fFim && !$fData)    { $where .= ' AND d.data <= ?'; $params[] = $fFim; }

$lista = array();
try {
    $st = $pdo->prepare("SELECT d.*, c.nome_completo
                         FROM colaboradores_daily d
                         LEFT JOIN colaboradores_onboarding c ON c.id = d.colaborador_id
                         WHERE $where
                         ORDER BY d.data DESC, c.nome_completo
                         LIMIT 300");
    $st->execute($params);
    $lista = $st->fetchAll();
} catch (Exception $e) {}

// Totais
$totDailies = (int)$pdo->query("SELECT COUNT(*) FROM colaboradores_daily")->fetchColumn();
$totHoje = (int)$pdo->query("SELECT COUNT(DISTINCT colaborador_id) FROM colaboradores_daily WHERE data = CURDATE()")->fetchColumn();
$totColabAtivas = count($colabs);

$pageTitle = 'Daily Planner — Colaboradores';
require_once APP_ROOT . '/templates/layout_start.php';

function _data_br($iso) {
    if (!$iso) return '';
    $dt = DateTime::createFromFormat('Y-m-d', $iso);
    if (!$dt) return $iso;
    $diasSemana = array('domingo','segunda','terça','quarta','quinta','sexta','sábado');
    return $diasSemana[(int)$dt->format('w')] . ', ' . $dt->format('d/m/Y');
}
?>

<style>
.d-card { background:#fff; border-radius:14px; padding:1.5rem 1.6rem; margin-bottom:1.2rem; box-shadow:0 2px 8px rgba(0,0,0,.04); border:1px solid #e5e7eb; }
.d-card h3 { font-size:1.05rem; color:#052228; padding-bottom:.5rem; border-bottom:2px solid #d7ab90; margin-bottom:1rem; }
.tot-grid { display:grid; gap:.85rem; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); margin-bottom:1.2rem; }
.tot-card { background:#fff; border-radius:14px; padding:1.2rem 1.3rem; box-shadow:0 2px 8px rgba(0,0,0,.04); border-top:4px solid #B87333; }
.tot-card.verde { border-top-color:#10b981; }
.tot-card.azul { border-top-color:#3b82f6; }
.tot-card .lbl { font-size:.7rem; letter-spacing:1.5px; font-weight:700; color:#6a3c2c; text-transform:uppercase; }
.tot-card .val { font-size:2rem; font-weight:900; color:#052228; margin-top:.3rem; line-height:1; }
.tot-card.verde .val { color:#047857; }
.tot-card .sub { font-size:.78rem; color:#6b7280; margin-top:.2rem; }

.d-filtros { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1rem; align-items:center; }
.d-filtros select, .d-filtros input { padding:.45rem .75rem; border-radius:8px; border:1.5px solid #e5e7eb; font-size:.82rem; background:#fff; }
.d-filtros .grupo { display:flex; align-items:center; gap:.4rem; font-size:.78rem; color:#6b7280; }
.d-filtros button { background:#052228; color:#fff; border:0; padding:.45rem 1rem; border-radius:8px; font-weight:700; font-size:.82rem; cursor:pointer; }
.d-filtros .limpar { background:#fff; color:#6b7280; border:1.5px solid #e5e7eb; }

.d-item { background:#fff; border:1.5px solid #e5e7eb; border-radius:12px; padding:1.1rem 1.3rem; margin-bottom:.85rem; }
.d-item .head { display:flex; justify-content:space-between; align-items:flex-start; gap:.6rem; flex-wrap:wrap; margin-bottom:.5rem; }
.d-item .nome { font-weight:700; color:#052228; font-size:.98rem; display:flex; align-items:center; gap:.5rem; }
.d-item .data { font-size:.74rem; color:#6b7280; text-transform:capitalize; }
.d-item .humor { font-size:1.6rem; line-height:1; }
.d-item .foco { background:#fff7ed; border-left:4px solid #B87333; padding:.55rem .8rem; border-radius:0 8px 8px 0; margin-top:.3rem; font-size:.9rem; color:#6a3c2c; }
.d-item .foco strong { color:#052228; }
.d-item .secao { margin-top:.7rem; }
.d-item .secao h4 { font-size:.78rem; font-weight:800; color:#6a3c2c; text-transform:uppercase; letter-spacing:.04em; margin-bottom:.3rem; }
.d-item .tarefas { list-style:none; padding:0; margin:0; }
.d-item .tarefas li { padding:.25rem 0; font-size:.85rem; color:#374151; }
.d-item .tarefas li.feito { text-decoration:line-through; color:#9ca3af; }
.d-item .nota-box { background:#fafafa; border:1px solid #e5e7eb; border-radius:8px; padding:.5rem .8rem; font-size:.85rem; color:#374151; line-height:1.5; }
.d-item .aprend { background:#eff6ff; border-left:4px solid #3b82f6; padding:.55rem .8rem; border-radius:0 8px 8px 0; font-size:.85rem; color:#1e40af; line-height:1.5; }
</style>

<div class="card">
    <div class="card-header">
        <h3>📓 Daily Planner — Colaboradores</h3>
        <p style="font-size:.82rem;color:#6b7280;margin-top:.3rem;">Visualize o que cada uma anda priorizando: foco, tarefas, humor e reflexões. Read-only.</p>
    </div>
</div>

<div class="tot-grid" style="margin-top:1rem;">
    <div class="tot-card azul">
        <div class="lbl">Dailies registradas</div>
        <div class="val"><?= $totDailies ?></div>
        <div class="sub">total no histórico</div>
    </div>
    <div class="tot-card verde">
        <div class="lbl">Preencheram hoje</div>
        <div class="val"><?= $totHoje ?>/<?= $totColabAtivas ?></div>
        <div class="sub">colaboradoras com daily de hoje</div>
    </div>
    <div class="tot-card">
        <div class="lbl">Resultados filtrados</div>
        <div class="val"><?= count($lista) ?></div>
        <div class="sub">no filtro atual</div>
    </div>
</div>

<div class="d-card">
    <form method="GET" class="d-filtros">
        <span class="grupo"><strong>Colaboradora:</strong>
            <select name="colab" onchange="this.form.submit()">
                <option value="0">Todas</option>
                <?php foreach ($colabs as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $fColab === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['nome_completo']) ?></option>
                <?php endforeach; ?>
            </select>
        </span>
        <span class="grupo"><strong>Dia:</strong> <input type="date" name="data" value="<?= e($fData) ?>"></span>
        <span class="grupo"><strong>De:</strong> <input type="date" name="inicio" value="<?= e($fInicio) ?>"></span>
        <span class="grupo"><strong>Até:</strong> <input type="date" name="fim" value="<?= e($fFim) ?>"></span>
        <button type="submit">Filtrar</button>
        <a href="?" class="limpar" style="text-decoration:none;padding:.45rem 1rem;border-radius:8px;border:1.5px solid #e5e7eb;color:#6b7280;font-weight:700;font-size:.82rem;">Limpar</a>
    </form>

    <?php if (empty($lista)): ?>
        <p style="color:#6b7280;font-size:.85rem;text-align:center;padding:2rem;">Nenhuma daily encontrada nesse filtro.</p>
    <?php else: ?>
        <?php foreach ($lista as $d):
            $tarefas = array();
            if (!empty($d['tarefas_json'])) {
                $dec = json_decode($d['tarefas_json'], true);
                if (is_array($dec)) $tarefas = $dec;
            }
            $tarefasFeitas = 0;
            foreach ($tarefas as $t) if (!empty($t['feito'])) $tarefasFeitas++;
        ?>
        <div class="d-item">
            <div class="head">
                <div class="nome">
                    <?php if (!empty($d['humor'])): ?>
                        <span class="humor"><?= e($d['humor']) ?></span>
                    <?php endif; ?>
                    <span><?= e($d['nome_completo']) ?></span>
                </div>
                <div class="data"><?= e(_data_br($d['data'])) ?></div>
            </div>

            <?php if (!empty($d['foco_principal'])): ?>
                <div class="foco"><strong>🎯 Foco:</strong> <?= e($d['foco_principal']) ?></div>
            <?php endif; ?>

            <?php if (!empty($tarefas)): ?>
                <div class="secao">
                    <h4>✅ Tarefas (<?= $tarefasFeitas ?>/<?= count($tarefas) ?> feitas)</h4>
                    <ul class="tarefas">
                        <?php foreach ($tarefas as $t): if (!empty($t['texto'])): ?>
                            <li class="<?= !empty($t['feito']) ? 'feito' : '' ?>"><?= !empty($t['feito']) ? '☑' : '☐' ?> <?= e($t['texto']) ?></li>
                        <?php endif; endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($d['notas'])): ?>
                <div class="secao">
                    <h4>📝 Notas</h4>
                    <div class="nota-box"><?= nl2br(e($d['notas'])) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($d['aprendizados'])): ?>
                <div class="secao">
                    <h4>💡 Aprendizado</h4>
                    <div class="aprend"><?= nl2br(e($d['aprendizados'])) ?></div>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
