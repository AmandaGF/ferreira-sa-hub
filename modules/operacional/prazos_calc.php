<?php
/**
 * Ferreira & Sa Conecta — Calculadora de Prazos Processuais
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Calculadora de Prazos';
$pdo = db();

$preCaseId = (int)($_GET['case_id'] ?? 0);
$preTipo   = isset($_GET['tipo']) ? $_GET['tipo'] : '';
$preComarca = isset($_GET['comarca']) ? $_GET['comarca'] : '';

$preCase = null;
if ($preCaseId) {
    $stmt = $pdo->prepare("SELECT id, title, case_number, court, comarca, case_type FROM cases WHERE id = ?");
    $stmt->execute(array($preCaseId));
    $preCase = $stmt->fetch();
    if ($preCase && !$preComarca) {
        $preComarca = $preCase['comarca'] ? $preCase['comarca'] : '';
    }
}

$resultado = null;
$salvoComSucesso = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $dataDisp  = isset($_POST['data_disponibilizacao']) ? $_POST['data_disponibilizacao'] : '';
    $qtd       = (int)(isset($_POST['quantidade']) ? $_POST['quantidade'] : 15);
    $unidade   = (isset($_POST['unidade']) && $_POST['unidade'] === 'meses') ? 'meses' : 'dias';
    $comarca   = clean_str(isset($_POST['comarca']) ? $_POST['comarca'] : '', 100);
    $tipoPrazo = clean_str(isset($_POST['tipo_prazo']) ? $_POST['tipo_prazo'] : '', 100);
    $caseId    = (int)(isset($_POST['case_id']) ? $_POST['case_id'] : 0);

    if ($dataDisp && $qtd > 0) {
        $resultado = calcular_prazo_completo($dataDisp, $qtd, $unidade, $comarca ? $comarca : null);

        if (isset($_POST['salvar']) && $_POST['salvar']) {
            try {
                $pdo->prepare(
                    "INSERT INTO prazos_calculos (case_id, tipo_prazo, data_disponibilizacao, data_publicacao, data_inicio_contagem, quantidade, unidade, comarca, data_fatal, calculado_por)
                     VALUES (?,?,?,?,?,?,?,?,?,?)"
                )->execute(array(
                    $caseId ? $caseId : null,
                    $tipoPrazo ? $tipoPrazo : null,
                    $resultado['disponibilizacao'],
                    $resultado['publicacao'],
                    $resultado['inicio_contagem'],
                    $qtd,
                    $unidade,
                    $comarca ? $comarca : null,
                    $resultado['data_fatal'],
                    current_user_id()
                ));
            } catch (Exception $e) {}

            if ($caseId) {
                try {
                    $pdo->prepare(
                        "INSERT INTO prazos_processuais (case_id, tipo, descricao, data_fatal, status) VALUES (?,?,?,?,0)"
                    )->execute(array(
                        $caseId,
                        $tipoPrazo ? $tipoPrazo : 'Prazo',
                        'Prazo calculado: ' . $qtd . ' ' . $unidade,
                        $resultado['data_fatal']
                    ));
                } catch (Exception $e) {}

                try {
                    $pdo->prepare(
                        "INSERT INTO agenda_eventos (titulo, tipo, data_inicio, data_fim, dia_todo, case_id, responsavel_id) VALUES (?,?,?,?,1,?,?)"
                    )->execute(array(
                        'PRAZO: ' . ($tipoPrazo ? $tipoPrazo : 'Processual') . ' - ' . ($preCase ? $preCase['title'] : ''),
                        'prazo',
                        $resultado['data_fatal'],
                        $resultado['data_fatal'],
                        $caseId,
                        current_user_id()
                    ));
                } catch (Exception $e) {}
            }

            $salvoComSucesso = true;
            flash_set('success', 'Prazo salvo! Data fatal: ' . date('d/m/Y', strtotime($resultado['data_fatal'])));
        }
    } else {
        flash_set('error', 'Preencha a data de disponibilização e a quantidade.');
    }
}

$comarcas   = comarcas_rj();
$tiposPrazo = tipos_prazo();

$casesForSelect = array();
try {
    $casesForSelect = $pdo->query(
        "SELECT id, title, case_number FROM cases ORDER BY title ASC LIMIT 200"
    )->fetchAll();
} catch (Exception $e) {}

$extraCss = '
<style>
/* ═══════════════════════════════════════════════════════
   Calculadora de Prazos — Design Profissional
   ═══════════════════════════════════════════════════════ */

/* --- Navigation bar --- */
.prazos-nav {
    display: flex;
    align-items: center;
    gap: .75rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}
.prazos-nav .nav-link {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    padding: .5rem 1rem;
    font-size: .85rem;
    font-weight: 600;
    color: var(--petrol-500);
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    text-decoration: none;
    transition: all var(--transition);
}
.prazos-nav .nav-link:hover {
    color: var(--petrol-900);
    border-color: var(--rose);
    box-shadow: var(--shadow-sm);
}
.prazos-nav .nav-link svg { width: 16px; height: 16px; }
.prazos-nav .nav-separator { color: var(--border); font-size: .8rem; }

/* --- Pre-case banner --- */
.precase-banner {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .75rem 1.25rem;
    background: var(--petrol-100);
    border: 1px solid rgba(23,61,70,.15);
    border-radius: var(--radius);
    margin-bottom: 1.5rem;
    font-size: .88rem;
    color: var(--petrol-900);
}
.precase-banner svg { width: 18px; height: 18px; flex-shrink: 0; color: var(--petrol-500); }
.precase-banner a {
    color: var(--petrol-900);
    text-decoration: none;
    font-weight: 600;
}
.precase-banner a:hover { color: var(--rose-dark); }

/* --- Two-column grid --- */
.prazos-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
    align-items: start;
}
@media (max-width: 960px) {
    .prazos-grid { grid-template-columns: 1fr; }
}

/* --- Form card --- */
.calc-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    transition: box-shadow var(--transition);
}
.calc-card:hover { box-shadow: var(--shadow-md); }

.calc-card-header {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: 1.25rem 1.5rem;
    background: linear-gradient(135deg, var(--petrol-900), var(--petrol-500));
    color: #fff;
}
.calc-card-header svg { width: 24px; height: 24px; opacity: .85; }
.calc-card-header h2 {
    font-size: 1.1rem;
    font-weight: 700;
    letter-spacing: .3px;
    margin: 0;
}

.calc-card-body { padding: 1.5rem; }

/* --- Form fields --- */
.prazo-form .field-group {
    margin-bottom: 1.25rem;
}
.prazo-form .field-label {
    display: block;
    font-weight: 600;
    font-size: .82rem;
    color: var(--petrol-900);
    margin-bottom: .4rem;
    letter-spacing: .2px;
}
.prazo-form .field-label .optional {
    font-weight: 400;
    color: var(--text-muted);
    font-size: .75rem;
}
.prazo-form .field-input,
.prazo-form .field-select {
    width: 100%;
    padding: .6rem .9rem;
    border: 1.5px solid var(--border);
    border-radius: var(--radius);
    font-size: .9rem;
    font-family: var(--font);
    color: var(--text);
    background: var(--bg-card);
    transition: border-color var(--transition), box-shadow var(--transition);
    -webkit-appearance: none;
    appearance: none;
}
.prazo-form .field-select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'12\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%236b7280\' stroke-width=\'2\'%3E%3Cpolyline points=\'6 9 12 15 18 9\'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right .75rem center;
    padding-right: 2.25rem;
}
.prazo-form .field-input:focus,
.prazo-form .field-select:focus {
    outline: none;
    border-color: var(--rose);
    box-shadow: 0 0 0 3px rgba(215,171,144,.18);
}

/* --- D+1 / D+2 preview badges --- */
.preview-badges {
    display: flex;
    gap: .75rem;
    margin-top: .6rem;
    flex-wrap: wrap;
}
.preview-badge {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    padding: .4rem .75rem;
    background: var(--info-bg);
    border: 1px solid #bae6fd;
    border-radius: 20px;
    font-size: .78rem;
    color: var(--info);
    font-weight: 600;
    transition: opacity var(--transition);
}
.preview-badge svg { width: 14px; height: 14px; opacity: .7; }

/* --- Quantity row --- */
.qtd-row {
    display: flex;
    gap: .75rem;
    align-items: stretch;
}
.qtd-row .field-group { flex: 1; margin-bottom: 0; }

/* --- Calculate button --- */
.btn-calcular {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .6rem;
    width: 100%;
    padding: .9rem 1.5rem;
    font-size: 1rem;
    font-weight: 700;
    font-family: var(--font);
    letter-spacing: .5px;
    background: linear-gradient(135deg, var(--petrol-900), var(--petrol-500));
    color: #fff;
    border: none;
    border-radius: var(--radius);
    cursor: pointer;
    transition: all var(--transition);
    margin-top: .5rem;
}
.btn-calcular:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-1px);
}
.btn-calcular:active { transform: translateY(0); }
.btn-calcular svg { width: 20px; height: 20px; }

/* --- Info card --- */
.info-card {
    margin-top: 1.25rem;
    padding: 1.25rem 1.5rem;
    background: var(--petrol-100);
    border: 1px solid rgba(23,61,70,.12);
    border-radius: var(--radius-lg);
    font-size: .82rem;
    color: var(--petrol-700);
    line-height: 1.7;
}
.info-card-title {
    display: flex;
    align-items: center;
    gap: .4rem;
    font-weight: 700;
    font-size: .88rem;
    color: var(--petrol-900);
    margin-bottom: .6rem;
}
.info-card-title svg { width: 16px; height: 16px; }
.info-card ol {
    margin: 0;
    padding-left: 1.2rem;
}
.info-card ol li { margin-bottom: .25rem; }
.info-card ol li strong { color: var(--petrol-900); }

/* ═════════════════════════════════════
   RESULTADO — Timeline & Cards
   ═════════════════════════════════════ */

/* --- Result card wrapper --- */
.result-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    transition: box-shadow var(--transition);
}
.result-card:hover { box-shadow: var(--shadow-md); }

.result-card-header {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: 1rem 1.5rem;
    background: var(--petrol-100);
    border-bottom: 1px solid var(--border);
}
.result-card-header svg { width: 20px; height: 20px; color: var(--petrol-500); }
.result-card-header h3 {
    font-size: 1rem;
    font-weight: 700;
    color: var(--petrol-900);
    margin: 0;
}

.result-card-body { padding: 1.5rem; }

/* --- Timeline --- */
.timeline {
    position: relative;
    padding-left: 2rem;
    margin-bottom: 1.5rem;
}
.timeline::before {
    content: "";
    position: absolute;
    left: 7px;
    top: 4px;
    bottom: 4px;
    width: 2px;
    background: linear-gradient(to bottom, var(--petrol-300), var(--rose), var(--danger));
    border-radius: 2px;
}
.timeline-node {
    position: relative;
    padding-bottom: 1.25rem;
}
.timeline-node:last-child { padding-bottom: 0; }
.timeline-node::before {
    content: "";
    position: absolute;
    left: -2rem;
    top: 4px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: var(--bg-card);
    border: 2.5px solid var(--petrol-300);
    z-index: 1;
}
.timeline-node.node-pub::before { border-color: var(--info); background: var(--info-bg); }
.timeline-node.node-inicio::before { border-color: var(--petrol-500); background: var(--petrol-100); }
.timeline-node.node-fatal::before { border-color: var(--danger); background: var(--danger-bg); }
.timeline-node.node-seg::before { border-color: var(--success); background: var(--success-bg); }

.timeline-label {
    font-size: .75rem;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-bottom: .15rem;
}
.timeline-value {
    font-size: .95rem;
    font-weight: 700;
    color: var(--petrol-900);
}
.timeline-detail {
    font-size: .78rem;
    color: var(--text-muted);
    margin-top: .1rem;
}

/* --- Safety date box --- */
.date-box-seguranca {
    background: linear-gradient(135deg, #059669, #047857);
    border-radius: var(--radius);
    padding: 1.25rem;
    text-align: center;
    margin-bottom: .75rem;
    position: relative;
    overflow: hidden;
}
.date-box-seguranca::before {
    content: "";
    position: absolute;
    top: -30%;
    right: -10%;
    width: 120px;
    height: 120px;
    background: rgba(255,255,255,.06);
    border-radius: 50%;
}
.date-box-seguranca .box-label {
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: rgba(255,255,255,.8);
    margin-bottom: .35rem;
}
.date-box-seguranca .box-date {
    font-size: 2rem;
    font-weight: 800;
    color: #fff;
    letter-spacing: .5px;
}
.date-box-seguranca .box-weekday {
    font-size: .82rem;
    color: rgba(255,255,255,.75);
    margin-top: .15rem;
}
.date-box-seguranca .box-hint {
    font-size: .7rem;
    color: rgba(255,255,255,.6);
    margin-top: .5rem;
}

/* --- Fatal date box --- */
.date-box-fatal {
    background: linear-gradient(135deg, #dc2626, #b91c1c);
    border-radius: var(--radius);
    padding: 1.25rem;
    text-align: center;
    margin-bottom: 1rem;
    position: relative;
    overflow: hidden;
}
.date-box-fatal::before {
    content: "";
    position: absolute;
    top: -30%;
    left: -10%;
    width: 100px;
    height: 100px;
    background: rgba(255,255,255,.06);
    border-radius: 50%;
}
.date-box-fatal .box-label {
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: rgba(255,255,255,.8);
    margin-bottom: .35rem;
}
.date-box-fatal .box-date {
    font-size: 2rem;
    font-weight: 800;
    color: #fff;
    letter-spacing: .5px;
}
.date-box-fatal .box-weekday {
    font-size: .82rem;
    color: rgba(255,255,255,.75);
    margin-top: .15rem;
}

/* --- Days remaining indicator --- */
.dias-indicator {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .5rem;
    padding: .75rem 1rem;
    border-radius: var(--radius);
    font-weight: 700;
    font-size: .92rem;
    text-align: center;
    margin-bottom: .5rem;
}
.dias-indicator svg { width: 18px; height: 18px; }
.dias-indicator.urgente { background: var(--danger-bg); color: var(--danger); border: 1px solid #fecaca; }
.dias-indicator.atencao { background: var(--warning-bg); color: var(--warning); border: 1px solid #fde68a; }
.dias-indicator.ok      { background: var(--success-bg); color: var(--success); border: 1px solid #bbf7d0; }
.dias-indicator.vencido { background: var(--danger-bg); color: #991b1b; border: 1.5px solid #fca5a5; }

.dias-seg-hint {
    text-align: center;
    font-size: .75rem;
    color: var(--success);
    font-weight: 700;
    margin-bottom: 1rem;
}

/* --- Suspension warnings --- */
.susp-section { margin-bottom: 1.25rem; }
.susp-title {
    display: flex;
    align-items: center;
    gap: .4rem;
    font-size: .82rem;
    font-weight: 700;
    color: var(--warning);
    margin-bottom: .5rem;
}
.susp-title svg { width: 16px; height: 16px; }
.susp-list { list-style: none; padding: 0; margin: 0; }
.susp-list li {
    display: flex;
    align-items: flex-start;
    gap: .5rem;
    padding: .6rem .75rem;
    background: var(--warning-bg);
    border-left: 3px solid var(--warning);
    border-radius: 0 8px 8px 0;
    margin-bottom: .4rem;
    font-size: .8rem;
    color: #92400e;
    line-height: 1.4;
}
.susp-list li .susp-dates { font-weight: 700; white-space: nowrap; }
.susp-list li .susp-scope {
    display: inline-block;
    font-size: .68rem;
    padding: .1rem .4rem;
    background: rgba(245,158,11,.15);
    border-radius: 4px;
    color: #b45309;
    font-weight: 600;
    margin-left: .25rem;
}
.susp-none {
    font-size: .82rem;
    color: var(--text-muted);
    font-style: italic;
    padding: .4rem 0;
}

/* --- Action buttons --- */
.result-actions {
    display: flex;
    gap: .75rem;
    padding-top: 1rem;
    border-top: 1px solid var(--border);
}
.result-actions .btn-action {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .4rem;
    padding: .65rem 1rem;
    font-size: .85rem;
    font-weight: 700;
    font-family: var(--font);
    border-radius: var(--radius);
    border: none;
    cursor: pointer;
    transition: all var(--transition);
    text-decoration: none;
}
.btn-action.btn-save {
    background: linear-gradient(135deg, var(--petrol-900), var(--petrol-500));
    color: #fff;
}
.btn-action.btn-save:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }
.btn-action.btn-save.saved {
    background: var(--success-bg);
    color: var(--success);
    border: 1px solid #bbf7d0;
    cursor: default;
    pointer-events: none;
}
.btn-action.btn-copy {
    background: var(--bg);
    color: var(--petrol-700);
    border: 1px solid var(--border);
}
.btn-action.btn-copy:hover { border-color: var(--rose); color: var(--petrol-900); }
.btn-action svg { width: 16px; height: 16px; }

/* ═════════════════════════════════════
   MINI CALENDARIO
   ═════════════════════════════════════ */
.cal-section {
    margin-top: 1.5rem;
}
.cal-section-header {
    display: flex;
    align-items: center;
    gap: .5rem;
    margin-bottom: 1rem;
}
.cal-section-header svg { width: 18px; height: 18px; color: var(--petrol-500); }
.cal-section-header h4 {
    font-size: .95rem;
    font-weight: 700;
    color: var(--petrol-900);
    margin: 0;
}

.cal-month {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1rem;
    margin-bottom: 1rem;
    box-shadow: var(--shadow-sm);
}
.cal-month-title {
    font-weight: 700;
    font-size: .9rem;
    color: var(--petrol-900);
    margin-bottom: .6rem;
    text-transform: capitalize;
    text-align: center;
}
.cal-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 3px;
    text-align: center;
}
.cal-dow {
    font-weight: 700;
    font-size: .7rem;
    color: var(--text-muted);
    padding: .3rem 0;
    text-transform: uppercase;
    letter-spacing: .5px;
}
.cal-day {
    position: relative;
    padding: .3rem .15rem;
    border-radius: 6px;
    min-height: 1.8rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .78rem;
    font-weight: 500;
    transition: background var(--transition);
}
.cal-day.empty { background: transparent; }
.cal-day.fds { background: #f9fafb; color: #c0c4cc; }
.cal-day.suspenso { background: #fef3c7; color: #92400e; font-weight: 600; }
.cal-day.contado { background: #dcfce7; color: #166534; }
.cal-day.fatal {
    background: var(--danger);
    color: #fff;
    font-weight: 800;
    border-radius: 50%;
    box-shadow: 0 0 0 3px rgba(220,38,38,.2);
}
.cal-day.seguranca {
    background: var(--success);
    color: #fff;
    font-weight: 800;
    border-radius: 50%;
    box-shadow: 0 0 0 3px rgba(5,150,105,.2);
}
.cal-day.inicio { background: #dbeafe; color: #1e40af; font-weight: 700; }
.cal-day.publicacao { background: #e0e7ff; color: #3730a3; font-weight: 600; }
.cal-day.fora { color: #e5e7eb; }
.cal-day.hoje {
    outline: 2px solid var(--rose);
    outline-offset: -1px;
}

/* --- Legend --- */
.cal-legend {
    display: flex;
    gap: .75rem;
    flex-wrap: wrap;
    padding: .75rem 1rem;
    background: var(--bg);
    border-radius: var(--radius);
    margin-top: .5rem;
}
.cal-legend-item {
    display: flex;
    align-items: center;
    gap: .3rem;
    font-size: .72rem;
    color: var(--text-muted);
    font-weight: 500;
}
.cal-legend-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    display: inline-block;
    flex-shrink: 0;
}
.dot-fatal     { background: var(--danger); }
.dot-seg       { background: var(--success); }
.dot-suspenso  { background: #fef3c7; border: 1px solid var(--warning); }
.dot-fds       { background: #f3f4f6; border: 1px solid #d1d5db; }
.dot-contado   { background: #dcfce7; border: 1px solid #86efac; }
.dot-inicio    { background: #dbeafe; border: 1px solid #93c5fd; }
.dot-hoje      { background: transparent; border: 2px solid var(--rose); }

/* --- Empty state --- */
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 380px;
    text-align: center;
    padding: 2.5rem;
}
.empty-state-icon {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: var(--petrol-100);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1.25rem;
}
.empty-state-icon svg { width: 36px; height: 36px; color: var(--petrol-300); }
.empty-state h4 {
    font-size: 1rem;
    font-weight: 700;
    color: var(--petrol-900);
    margin: 0 0 .5rem;
}
.empty-state p {
    font-size: .88rem;
    color: var(--text-muted);
    max-width: 280px;
    line-height: 1.6;
    margin: 0;
}
</style>';

require_once APP_ROOT . '/templates/layout_start.php';
?>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- Navigation                                            -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="prazos-nav">
    <a href="<?= module_url('operacional') ?>" class="nav-link">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><polyline points="12 19 5 12 12 5"/></svg>
        Operacional
    </a>
    <span class="nav-separator">|</span>
    <a href="<?= module_url('operacional', 'prazos_suspensoes.php') ?>" class="nav-link">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Gerenciar Suspensões
    </a>
</div>

<?php if ($preCase): ?>
<div class="precase-banner">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
    <span>Calculando prazo para:
        <a href="<?= module_url('operacional', 'caso_ver.php?id=' . (int)$preCase['id']) ?>">
            <?= e($preCase['title'] ? $preCase['title'] : $preCase['case_number']) ?>
        </a>
    </span>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- Main Grid                                             -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="prazos-grid">

    <!-- ════════════════════════════════════════════════════ -->
    <!-- LEFT COLUMN: Form                                  -->
    <!-- ════════════════════════════════════════════════════ -->
    <div>
        <div class="calc-card">
            <div class="calc-card-header">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.92 12.446A9 9 0 1 1 12 3z"/><path d="M17 4l-2 4h4l-2 4"/></svg>
                <h2>Calculadora de Prazos</h2>
            </div>

            <div class="calc-card-body">
                <form method="POST" class="prazo-form" id="prazoForm">
                    <?= csrf_input() ?>

                    <!-- Processo (opcional) -->
                    <div class="field-group">
                        <label class="field-label" for="caseSelect">
                            Processo <span class="optional">(opcional)</span>
                        </label>
                        <select name="case_id" id="caseSelect" class="field-select">
                            <option value="">-- Sem vinculo a processo --</option>
                            <?php foreach ($casesForSelect as $c): ?>
                                <option value="<?= (int)$c['id'] ?>"
                                    <?php if ($preCaseId && $preCaseId == $c['id']): ?> selected<?php endif; ?>
                                >
                                    <?= e($c['title'] ? $c['title'] : ($c['case_number'] ? $c['case_number'] : 'Processo #' . $c['id'])) ?>
                                    <?php if ($c['case_number']): ?> (<?= e($c['case_number']) ?>)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Tipo de prazo -->
                    <div class="field-group">
                        <label class="field-label" for="tipoPrazo">Tipo de Prazo</label>
                        <select name="tipo_prazo" id="tipoPrazo" class="field-select">
                            <option value="">-- Selecione o tipo --</option>
                            <?php foreach ($tiposPrazo as $tp): ?>
                                <option value="<?= e($tp) ?>"
                                    <?php
                                        $postTipo = isset($_POST['tipo_prazo']) ? $_POST['tipo_prazo'] : $preTipo;
                                        if ($postTipo === $tp) echo ' selected';
                                    ?>
                                ><?= e($tp) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Comarca -->
                    <div class="field-group">
                        <label class="field-label" for="comarca">Comarca</label>
                        <select name="comarca" id="comarca" class="field-select">
                            <option value="">-- Selecione a comarca --</option>
                            <?php foreach ($comarcas as $cm): ?>
                                <option value="<?= e($cm) ?>"
                                    <?php
                                        $postComarca = isset($_POST['comarca']) ? $_POST['comarca'] : $preComarca;
                                        if ($postComarca === $cm) echo ' selected';
                                    ?>
                                ><?= e($cm) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Data de disponibilizacao -->
                    <div class="field-group">
                        <label class="field-label" for="dataDisp">Data de Disponibilização (DJe)</label>
                        <input type="date" name="data_disponibilizacao" id="dataDisp"
                               class="field-input"
                               value="<?= e(isset($_POST['data_disponibilizacao']) ? $_POST['data_disponibilizacao'] : '') ?>"
                               required>

                        <!-- Preview D+1 / D+2 -->
                        <div class="preview-badges" id="previewRow" style="display:none;">
                            <span class="preview-badge">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                Publicação (D+1): <strong id="previewPub">--</strong>
                            </span>
                            <span class="preview-badge">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
                                Início contagem: <strong id="previewInicio">--</strong>
                            </span>
                        </div>
                    </div>

                    <!-- Quantidade + Unidade -->
                    <div class="field-group">
                        <label class="field-label">Prazo</label>
                        <div class="qtd-row">
                            <div class="field-group">
                                <input type="number" name="quantidade" id="quantidade"
                                       class="field-input" min="1" max="999"
                                       value="<?= e(isset($_POST['quantidade']) ? $_POST['quantidade'] : '15') ?>"
                                       placeholder="15">
                            </div>
                            <div class="field-group">
                                <select name="unidade" id="unidade" class="field-select">
                                    <option value="dias"<?php if (!isset($_POST['unidade']) || $_POST['unidade'] === 'dias') echo ' selected'; ?>>Dias úteis</option>
                                    <option value="meses"<?php if (isset($_POST['unidade']) && $_POST['unidade'] === 'meses') echo ' selected'; ?>>Meses</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Calcular -->
                    <button type="submit" class="btn-calcular">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2"/><line x1="8" y1="6" x2="16" y2="6"/><line x1="8" y1="10" x2="10" y2="10"/><line x1="14" y1="10" x2="16" y2="10"/><line x1="8" y1="14" x2="10" y2="14"/><line x1="14" y1="14" x2="16" y2="14"/><line x1="8" y1="18" x2="16" y2="18"/></svg>
                        CALCULAR PRAZO
                    </button>
                </form>
            </div>
        </div>

        <!-- Info Box -->
        <div class="info-card">
            <div class="info-card-title">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                Como funciona o cálculo
            </div>
            <ol>
                <li><strong>Disponibilização</strong> = data da publicação no DJe</li>
                <li><strong>Publicação</strong> = D+1 dia útil</li>
                <li><strong>Inicio da contagem</strong> = primeiro dia útil após a publicação</li>
                <li>O prazo conta apenas <strong>dias úteis</strong> (exceto se em meses)</li>
                <li>Se a data fatal cair em dia não útil, avança para o próximo dia útil</li>
                <li>São excluídos: sábados, domingos, feriados e suspensões do TJRJ</li>
            </ol>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════ -->
    <!-- RIGHT COLUMN: Result                               -->
    <!-- ════════════════════════════════════════════════════ -->
    <div>
        <?php if ($resultado): ?>
            <div class="result-card">
                <div class="result-card-header">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    <h3>Resultado do Calculo</h3>
                </div>

                <div class="result-card-body">

                    <!-- Timeline -->
                    <div class="timeline">
                        <div class="timeline-node">
                            <div class="timeline-label">Disponibilização</div>
                            <div class="timeline-value"><?= data_br($resultado['disponibilizacao']) ?></div>
                        </div>
                        <div class="timeline-node node-pub">
                            <div class="timeline-label">Publicação (D+1)</div>
                            <div class="timeline-value"><?= data_br($resultado['publicacao']) ?></div>
                        </div>
                        <div class="timeline-node node-inicio">
                            <div class="timeline-label">Inicio da contagem</div>
                            <div class="timeline-value"><?= data_br($resultado['inicio_contagem']) ?></div>
                        </div>
                        <div class="timeline-node">
                            <div class="timeline-label">Prazo</div>
                            <div class="timeline-value"><?= (int)$resultado['quantidade'] ?> <?= $resultado['unidade'] === 'meses' ? 'meses' : 'dias úteis' ?></div>
                        </div>
                    </div>

                    <!-- Suspensoes -->
                    <?php if (!empty($resultado['suspensoes'])): ?>
                        <div class="susp-section">
                            <div class="susp-title">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                Suspensões encontradas
                            </div>
                            <ul class="susp-list">
                                <?php foreach ($resultado['suspensoes'] as $susp): ?>
                                    <li>
                                        <span class="susp-dates">
                                            <?= data_br($susp['data_inicio']) ?>
                                            <?php if ($susp['data_fim'] !== $susp['data_inicio']): ?>
                                                a <?= data_br($susp['data_fim']) ?>
                                            <?php endif; ?>
                                        </span>
                                        &mdash; <?= e($susp['motivo']) ?>
                                        <span class="susp-scope"><?= e($susp['abrangencia']) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php else: ?>
                        <div class="susp-none">Nenhuma suspensao encontrada no periodo.</div>
                    <?php endif; ?>

                    <!-- Safety date -->
                    <div class="date-box-seguranca">
                        <div class="box-label">Prazo Interno (segurança)</div>
                        <div class="box-date"><?= date('d/m/Y', strtotime($resultado['data_seguranca'])) ?></div>
                        <div class="box-weekday"><?= e($resultado['dia_semana_seg']) ?></div>
                        <div class="box-hint">1 dia útil ANTES do término &mdash; para evitar perda de prazo</div>
                    </div>

                    <!-- Fatal date -->
                    <div class="date-box-fatal">
                        <div class="box-label">Data Fatal (término legal)</div>
                        <div class="box-date" id="dataFatalValor"><?= date('d/m/Y', strtotime($resultado['data_fatal'])) ?></div>
                        <div class="box-weekday"><?= e($resultado['dia_semana_fatal']) ?></div>
                    </div>

                    <!-- Days remaining -->
                    <?php
                    $diasAte = (int)$resultado['dias_ate_prazo'];
                    if ($diasAte < 0) {
                        $diasClass = 'vencido';
                        $diasTexto = 'PRAZO VENCIDO ha ' . abs($diasAte) . ' dia' . (abs($diasAte) !== 1 ? 's' : '');
                        $diasIcon  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
                    } elseif ($diasAte === 0) {
                        $diasClass = 'urgente';
                        $diasTexto = 'PRAZO VENCE HOJE!';
                        $diasIcon  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
                    } elseif ($diasAte <= 3) {
                        $diasClass = 'urgente';
                        $diasTexto = $diasAte . ' dia' . ($diasAte !== 1 ? 's' : '') . ' ate o prazo';
                        $diasIcon  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
                    } elseif ($diasAte <= 7) {
                        $diasClass = 'atencao';
                        $diasTexto = $diasAte . ' dias ate o prazo';
                        $diasIcon  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
                    } else {
                        $diasClass = 'ok';
                        $diasTexto = $diasAte . ' dias ate o prazo';
                        $diasIcon  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
                    }
                    ?>
                    <div class="dias-indicator <?= $diasClass ?>">
                        <?= $diasIcon ?>
                        <?= e($diasTexto) ?>
                    </div>

                    <?php $diasSeg = (int)$resultado['dias_ate_seguranca']; ?>
                    <?php if ($diasSeg >= 0 && $diasSeg !== $diasAte): ?>
                        <div class="dias-seg-hint">Prazo interno (segurança): <?= $diasSeg ?> dia<?= $diasSeg !== 1 ? 's' : '' ?></div>
                    <?php endif; ?>

                    <!-- Action buttons -->
                    <div class="result-actions">
                        <?php if (!$salvoComSucesso): ?>
                            <button type="button" class="btn-action btn-save" id="btnSalvar" onclick="salvarPrazo()">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                                Salvar no processo
                            </button>
                        <?php else: ?>
                            <span class="btn-action btn-save saved">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                Salvo com sucesso
                            </span>
                        <?php endif; ?>

                        <button type="button" class="btn-action btn-copy" onclick="copiarResultado()">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                            Copiar
                        </button>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════ -->
            <!-- Mini Calendar                                 -->
            <!-- ══════════════════════════════════════════════ -->
            <div class="cal-section">
                <div class="cal-section-header">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <h4>Calendario do Prazo</h4>
                </div>

                <?php
                $calInicio = new DateTime($resultado['inicio_contagem']);
                $calFim    = new DateTime($resultado['data_fatal']);

                $diasSuspensos = get_dias_suspensos_expandidos(
                    $calInicio->format('Y-m-d'),
                    $calFim->format('Y-m-d'),
                    $resultado['comarca'] ? $resultado['comarca'] : null
                );

                $diasContados = array();
                $tempDt = new DateTime($resultado['inicio_contagem']);
                $tempDt->modify('+1 day');
                $fatalDate = $resultado['data_fatal'];
                $comarcaCal = $resultado['comarca'] ? $resultado['comarca'] : null;
                $maxIter = 500;
                $iterCount = 0;
                while ($tempDt->format('Y-m-d') <= $fatalDate && $iterCount < $maxIter) {
                    $dd = $tempDt->format('Y-m-d');
                    if (is_dia_util($dd, $comarcaCal)) {
                        $diasContados[$dd] = true;
                    }
                    $tempDt->modify('+1 day');
                    $iterCount++;
                }

                $dateDisp    = $resultado['disponibilizacao'];
                $datePub     = $resultado['publicacao'];
                $dateInicio  = $resultado['inicio_contagem'];
                $dateFatal   = $resultado['data_fatal'];
                $dateSeg     = $resultado['data_seguranca'];
                $hoje        = date('Y-m-d');

                $mesAtual = new DateTime($calInicio->format('Y-m-01'));
                $mesFim   = new DateTime($calFim->format('Y-m-01'));

                $mesesPt = array(
                    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Marco', 4 => 'Abril',
                    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
                    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
                );
                $diasSemanaHeader = array('D', 'S', 'T', 'Q', 'Q', 'S', 'S');

                while ($mesAtual <= $mesFim) {
                    $ano = (int)$mesAtual->format('Y');
                    $mes = (int)$mesAtual->format('n');
                    $diasNoMes = (int)$mesAtual->format('t');
                    $primeiroDow = (int)(new DateTime($mesAtual->format('Y-m-01')))->format('w');

                    echo '<div class="cal-month">';
                    echo '<div class="cal-month-title">' . $mesesPt[$mes] . ' ' . $ano . '</div>';
                    echo '<div class="cal-grid">';

                    foreach ($diasSemanaHeader as $dh) {
                        echo '<div class="cal-dow">' . $dh . '</div>';
                    }

                    for ($e = 0; $e < $primeiroDow; $e++) {
                        echo '<div class="cal-day empty"></div>';
                    }

                    for ($d = 1; $d <= $diasNoMes; $d++) {
                        $dStr = sprintf('%04d-%02d-%02d', $ano, $mes, $d);
                        $dow  = (int)(new DateTime($dStr))->format('w');

                        $cls = '';
                        $title = '';

                        if ($dStr === $dateFatal) {
                            $cls = 'fatal';
                            $title = 'Data Fatal';
                        } elseif ($dStr === $dateSeg) {
                            $cls = 'seguranca';
                            $title = 'Data de Seguranca';
                        } elseif ($dStr === $dateInicio) {
                            $cls = 'inicio';
                            $title = 'Início da contagem';
                        } elseif ($dStr === $datePub) {
                            $cls = 'publicacao';
                            $title = 'Publicação';
                        } elseif (isset($diasSuspensos[$dStr])) {
                            $cls = 'suspenso';
                            $title = e($diasSuspensos[$dStr]);
                        } elseif ($dow === 0 || $dow === 6) {
                            $cls = 'fds';
                        } elseif (isset($diasContados[$dStr])) {
                            $cls = 'contado';
                        } elseif ($dStr < $dateInicio || $dStr > $dateFatal) {
                            $cls = 'fora';
                        }

                        if ($dStr === $hoje) {
                            $cls .= ' hoje';
                        }

                        echo '<div class="cal-day ' . $cls . '"' . ($title ? ' title="' . $title . '"' : '') . '>' . $d . '</div>';
                    }

                    echo '</div>';
                    echo '</div>';
                    $mesAtual->modify('+1 month');
                }
                ?>

                <!-- Legend -->
                <div class="cal-legend">
                    <div class="cal-legend-item"><span class="cal-legend-dot dot-fatal"></span> Data Fatal</div>
                    <div class="cal-legend-item"><span class="cal-legend-dot dot-seg"></span> Seguranca</div>
                    <div class="cal-legend-item"><span class="cal-legend-dot dot-suspenso"></span> Suspensao</div>
                    <div class="cal-legend-item"><span class="cal-legend-dot dot-fds"></span> Fim de semana</div>
                    <div class="cal-legend-item"><span class="cal-legend-dot dot-contado"></span> Dia contado</div>
                    <div class="cal-legend-item"><span class="cal-legend-dot dot-inicio"></span> Início / Publicação</div>
                    <div class="cal-legend-item"><span class="cal-legend-dot dot-hoje"></span> Hoje</div>
                </div>
            </div>

        <?php else: ?>

            <!-- Empty state -->
            <div class="calc-card">
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="10" y1="14" x2="14" y2="14"/></svg>
                    </div>
                    <h4>Nenhum cálculo realizado</h4>
                    <p>Preencha os dados ao lado e clique em <strong>CALCULAR PRAZO</strong> para ver o resultado aqui.</p>
                </div>
            </div>

        <?php endif; ?>
    </div>

</div>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- JavaScript                                            -->
<!-- ═══════════════════════════════════════════════════════ -->
<script>
(function() {
    var dataInput = document.getElementById('dataDisp');
    var qtdInput  = document.getElementById('quantidade');
    var unidInput = document.getElementById('unidade');

    if (dataInput) {
        dataInput.addEventListener('change', previewCalculo);
        if (qtdInput) qtdInput.addEventListener('change', previewCalculo);
        if (unidInput) unidInput.addEventListener('change', previewCalculo);
        if (dataInput.value) previewCalculo();
    }

    function previewCalculo() {
        var data = document.getElementById('dataDisp').value;
        var qtd  = document.getElementById('quantidade').value;
        if (!data || !qtd) {
            document.getElementById('previewRow').style.display = 'none';
            return;
        }
        var dt = new Date(data + 'T12:00:00');
        dt.setDate(dt.getDate() + 1);
        while (dt.getDay() === 0 || dt.getDay() === 6) {
            dt.setDate(dt.getDate() + 1);
        }
        document.getElementById('previewPub').textContent = formatDateBR(dt) + ' (aprox.)';

        dt.setDate(dt.getDate() + 1);
        while (dt.getDay() === 0 || dt.getDay() === 6) {
            dt.setDate(dt.getDate() + 1);
        }
        document.getElementById('previewInicio').textContent = formatDateBR(dt) + ' (aprox.)';

        document.getElementById('previewRow').style.display = 'flex';
    }

    function formatDateBR(dt) {
        var d = ('0' + dt.getDate()).slice(-2);
        var m = ('0' + (dt.getMonth() + 1)).slice(-2);
        var y = dt.getFullYear();
        return d + '/' + m + '/' + y;
    }

    window.salvarPrazo = function() {
        var form = document.getElementById('prazoForm');
        if (!form) return;
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'salvar';
        input.value = '1';
        form.appendChild(input);
        form.submit();
    };

    window.copiarResultado = function() {
        var el = document.getElementById('dataFatalValor');
        if (!el) return;

        var lines = [];
        lines.push('=== CALCULO DE PRAZO ===');
        lines.push('Disponibilização: <?= $resultado ? data_br($resultado['disponibilizacao']) : '' ?>');
        lines.push('Publicação: <?= $resultado ? data_br($resultado['publicacao']) : '' ?>');
        lines.push('Início contagem: <?= $resultado ? data_br($resultado['inicio_contagem']) : '' ?>');
        lines.push('Prazo: <?= $resultado ? (int)$resultado['quantidade'] . ' ' . ($resultado['unidade'] === 'meses' ? 'meses' : 'dias úteis') : '' ?>');
        lines.push('');
        lines.push('PRAZO INTERNO (segurança): <?= $resultado ? date('d/m/Y', strtotime($resultado['data_seguranca'])) . ' (' . $resultado['dia_semana_seg'] . ')' : '' ?>');
        lines.push('DATA FATAL (término legal): ' + el.textContent + ' (<?= $resultado ? $resultado['dia_semana_fatal'] : '' ?>)');
        lines.push('OBS: Considere protocolar até a data de segurança para evitar perda de prazo.');
        <?php if ($resultado && !empty($resultado['suspensoes'])): ?>
        lines.push('');
        lines.push('Suspensões no período:');
        <?php foreach ($resultado['suspensoes'] as $susp): ?>
        lines.push('  - <?= data_br($susp['data_inicio']) ?><?= ($susp['data_fim'] !== $susp['data_inicio']) ? ' a ' . data_br($susp['data_fim']) : '' ?> - <?= e($susp['motivo']) ?>');
        <?php endforeach; ?>
        <?php endif; ?>

        var text = lines.join('\n');

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                alert('Resultado copiado para a area de transferencia!');
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); alert('Resultado copiado!'); } catch(e) {}
            document.body.removeChild(ta);
        }
    };
})();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
