<?php
/**
 * Ferreira & Sa Conecta — Suspensoes de Prazos
 * Gerenciamento de feriados, recessos e suspensoes forenses.
 */

require_once __DIR__ . '/../../core/middleware.php';
require_access('operacional');

$pageTitle = 'Suspensões de Prazos';
$pdo = db();
$currentYear = (int)date('Y');
$filtroAno = (int)($_GET['ano'] ?? $currentYear);
$filtroMes = isset($_GET['mes']) ? (int)$_GET['mes'] : 0; // 0 = todos
$meses = array('','Janeiro','Fevereiro','Marco','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro');

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'adicionar') {
        $dataInicio = $_POST['data_inicio'] ?? '';
        $dataFim = $_POST['data_fim'] ?? '';
        $tipo = $_POST['tipo'] ?? 'outros';
        $abrangencia = $_POST['abrangencia'] ?? 'todo_estado';
        $comarca = ($abrangencia === 'comarca_especifica') ? clean_str($_POST['comarca'] ?? '', 100) : null;
        $motivo = clean_str($_POST['motivo'] ?? '', 300);
        $ato = clean_str($_POST['ato_legislacao'] ?? '', 200);

        if ($dataInicio && $dataFim && $motivo) {
            $pdo->prepare("INSERT INTO prazos_suspensoes (data_inicio, data_fim, tipo, abrangencia, comarca, motivo, ato_legislacao, criado_por) VALUES (?,?,?,?,?,?,?,?)")
                ->execute(array($dataInicio, $dataFim, $tipo, $abrangencia, $comarca, $motivo, $ato ? $ato : null, current_user_id()));
            flash_set('success', 'Suspensão adicionada!');
        }
        redirect(module_url('operacional', 'prazos_suspensoes.php?ano=' . $filtroAno));
    }

    if ($action === 'importar_texto') {
        $texto = $_POST['texto_pdf'] ?? '';
        $importados = 0;
        $erros = array();

        if ($texto) {
            // Parser: detectar padrões de datas e suspensões do TJRJ
            // Formatos esperados:
            //   "02/04/2026 a 03/04/2026 - Semana Santa - Ato Executivo 123"
            //   "21/04/2026 - Tiradentes"
            //   "Período: 02/04 a 03/04/2026 | Motivo: Semana Santa | Ato: TJ 123"
            //   "02 e 03 de abril de 2026 - Semana Santa"
            $linhas = preg_split('/\r?\n/', $texto);

            foreach ($linhas as $linha) {
                $linha = trim($linha);
                if (strlen($linha) < 5) continue;

                $dataInicio = null;
                $dataFim = null;
                $motivo = '';
                $ato = '';
                $comarca = null;
                $abrangencia = 'todo_estado';
                $tipo = 'outros';

                // Padrão 1: dd/mm/aaaa a dd/mm/aaaa — motivo — ato
                if (preg_match('#(\d{2}/\d{2}/\d{4})\s*(?:a|até|ate|-)\s*(\d{2}/\d{2}/\d{4})#i', $linha, $m)) {
                    $dataInicio = DateTime::createFromFormat('d/m/Y', $m[1]);
                    $dataFim = DateTime::createFromFormat('d/m/Y', $m[2]);
                    $resto = trim(preg_replace('#\d{2}/\d{2}/\d{4}\s*(?:a|até|ate|-)\s*\d{2}/\d{2}/\d{4}#i', '', $linha));
                }
                // Padrão 2: dd/mm/aaaa — motivo (data única)
                elseif (preg_match('#(\d{2}/\d{2}/\d{4})#', $linha, $m)) {
                    $dataInicio = DateTime::createFromFormat('d/m/Y', $m[1]);
                    $dataFim = $dataInicio ? clone $dataInicio : null;
                    $resto = trim(preg_replace('#\d{2}/\d{2}/\d{4}#', '', $linha));
                }
                // Padrão 3: dd/mm a dd/mm (sem ano, assume ano do filtro)
                elseif (preg_match('#(\d{2}/\d{2})\s*(?:a|até|ate|-)\s*(\d{2}/\d{2})#i', $linha, $m)) {
                    $dataInicio = DateTime::createFromFormat('d/m/Y', $m[1] . '/' . $filtroAno);
                    $dataFim = DateTime::createFromFormat('d/m/Y', $m[2] . '/' . $filtroAno);
                    $resto = trim(preg_replace('#\d{2}/\d{2}\s*(?:a|até|ate|-)\s*\d{2}/\d{2}#i', '', $linha));
                }
                else {
                    continue; // linha sem data reconhecível
                }

                if (!$dataInicio || !$dataFim) continue;

                // Extrair motivo e ato do resto
                $resto = preg_replace('/^[\s\-–—|:,]+/', '', $resto);
                $partes = preg_split('/\s*[-–—|]\s*/', $resto, 3);
                $motivo = isset($partes[0]) ? trim($partes[0]) : '';
                $ato = isset($partes[1]) ? trim($partes[1]) : '';
                if (!$motivo) $motivo = $linha; // usar linha inteira se não parseou

                // Detectar tipo pelo motivo
                $motivoLower = mb_strtolower($motivo);
                if (strpos($motivoLower, 'carnaval') !== false) $tipo = 'carnaval';
                elseif (strpos($motivoLower, 'santa') !== false || strpos($motivoLower, 'pascoa') !== false || strpos($motivoLower, 'páscoa') !== false) $tipo = 'semana_santa';
                elseif (strpos($motivoLower, 'recesso') !== false) $tipo = 'recesso';
                elseif (strpos($motivoLower, 'chuva') !== false || strpos($motivoLower, 'temporal') !== false || strpos($motivoLower, 'alagamento') !== false) $tipo = 'suspensao_chuvas';
                elseif (strpos($motivoLower, 'energia') !== false) $tipo = 'suspensao_energia';
                elseif (strpos($motivoLower, 'sistema') !== false || strpos($motivoLower, 'pje') !== false) $tipo = 'suspensao_sistema';
                elseif (strpos($motivoLower, 'ponto facultativo') !== false) $tipo = 'ponto_facultativo';
                elseif (strpos($motivoLower, 'nacional') !== false || strpos($motivoLower, 'tiradentes') !== false || strpos($motivoLower, 'independ') !== false || strpos($motivoLower, 'natal') !== false || strpos($motivoLower, 'trabalho') !== false || strpos($motivoLower, 'finados') !== false || strpos($motivoLower, 'aparecida') !== false || strpos($motivoLower, 'republica') !== false || strpos($motivoLower, 'república') !== false) $tipo = 'feriado_nacional';
                elseif (strpos($motivoLower, 'estadual') !== false || strpos($motivoLower, 'jorge') !== false || strpos($motivoLower, 'consciencia') !== false || strpos($motivoLower, 'consciência') !== false) $tipo = 'feriado_estadual';
                elseif (strpos($motivoLower, 'municipal') !== false) $tipo = 'feriado_municipal';

                // Detectar comarca
                if (preg_match('/comarca\s+(?:de\s+)?([A-ZÀ-Ú][a-záéíóúàãõâêîôû\s]+)/i', $linha, $mc)) {
                    $comarca = trim($mc[1]);
                    $abrangencia = 'comarca_especifica';
                }

                // Inserir
                try {
                    $pdo->prepare("INSERT INTO prazos_suspensoes (data_inicio, data_fim, tipo, abrangencia, comarca, motivo, ato_legislacao, fonte_pdf, criado_por) VALUES (?,?,?,?,?,?,?,?,?)")
                        ->execute(array(
                            $dataInicio->format('Y-m-d'),
                            $dataFim->format('Y-m-d'),
                            $tipo, $abrangencia, $comarca,
                            clean_str($motivo, 300),
                            $ato ? clean_str($ato, 200) : null,
                            'Importação texto PDF',
                            current_user_id()
                        ));
                    $importados++;
                } catch (Exception $e) {
                    $erros[] = mb_substr($linha, 0, 50) . ': ' . $e->getMessage();
                }
            }
        }

        if ($importados > 0) {
            flash_set('success', $importados . ' suspensão(ões) importada(s) com sucesso!');
        }
        if (!empty($erros)) {
            flash_set('error', 'Erros: ' . implode('; ', array_slice($erros, 0, 3)));
        }
        if ($importados === 0 && empty($erros)) {
            flash_set('error', 'Nenhuma suspensão encontrada no texto. Verifique o formato (datas dd/mm/aaaa).');
        }
        redirect(module_url('operacional', 'prazos_suspensoes.php?ano=' . $filtroAno));
    }

    if ($action === 'excluir' && has_role('admin')) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare("DELETE FROM prazos_suspensoes WHERE id = ?")->execute(array($id));
            flash_set('success', 'Suspensão removida.');
        }
        redirect(module_url('operacional', 'prazos_suspensoes.php?ano=' . $filtroAno));
    }
}

// Fetch suspensions
$where = array("(YEAR(data_inicio) = ? OR YEAR(data_fim) = ?)");
$params = array($filtroAno, $filtroAno);
if ($filtroMes > 0) {
    $where[] = "(MONTH(data_inicio) = ? OR MONTH(data_fim) = ?)";
    $params[] = $filtroMes;
    $params[] = $filtroMes;
}
$whereStr = implode(' AND ', $where);
$stmt = $pdo->prepare("SELECT * FROM prazos_suspensoes WHERE $whereStr ORDER BY data_inicio ASC");
$stmt->execute($params);
$suspensoes = $stmt->fetchAll();

// Stats
$totalSusp = count($suspensoes);
$totalDias = 0;
foreach ($suspensoes as $s) {
    $d1 = new DateTime($s['data_inicio']);
    $d2 = new DateTime($s['data_fim']);
    $totalDias += (int)$d1->diff($d2)->days + 1;
}

$comarcas = comarcas_rj();
$tipoLabels = array(
    'feriado_nacional' => 'Feriado Nacional',
    'feriado_estadual' => 'Feriado Estadual',
    'feriado_municipal' => 'Feriado Municipal',
    'recesso' => 'Recesso Forense',
    'suspensao_chuvas' => 'Suspensão (Chuvas)',
    'suspensao_energia' => 'Suspensão (Energia)',
    'suspensao_sistema' => 'Suspensão (Sistema)',
    'ponto_facultativo' => 'Ponto Facultativo',
    'carnaval' => 'Carnaval',
    'semana_santa' => 'Semana Santa',
    'outros' => 'Outros',
);
$tipoCores = array(
    'feriado_nacional' => '#dc2626',
    'feriado_estadual' => '#d97706',
    'feriado_municipal' => '#2563eb',
    'recesso' => '#7c3aed',
    'suspensao_chuvas' => '#0891b2',
    'suspensao_energia' => '#ea580c',
    'suspensao_sistema' => '#64748b',
    'ponto_facultativo' => '#059669',
    'carnaval' => '#e11d48',
    'semana_santa' => '#6366f1',
    'outros' => '#6b7280',
);

// Calendar data: count suspension days per month
$calendarData = array();
for ($m = 1; $m <= 12; $m++) {
    $calendarData[$m] = 0;
}
foreach ($suspensoes as $s) {
    $d1 = new DateTime($s['data_inicio']);
    $d2 = new DateTime($s['data_fim']);
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($d1, $interval, $d2->modify('+1 day'));
    foreach ($period as $day) {
        $mNum = (int)$day->format('n');
        $yNum = (int)$day->format('Y');
        if ($yNum === $filtroAno) {
            $calendarData[$mNum]++;
        }
    }
}

$canManage = has_role('admin', 'gestao');

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.susp-stats { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
.susp-stat-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px 24px; text-align: center; min-width: 140px; }
.susp-stat-card .stat-value { font-size: 2rem; font-weight: 700; color: #1e293b; }
.susp-stat-card .stat-label { font-size: 0.85rem; color: #64748b; margin-top: 4px; }

.month-filters { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 20px; }
.month-filters .btn { padding: 6px 12px; font-size: 0.82rem; border-radius: 6px; }
.month-filters .btn.active { background: #2563eb; color: #fff; border-color: #2563eb; }

.year-nav { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.year-nav .year-label { font-size: 1.3rem; font-weight: 700; color: #1e293b; min-width: 60px; text-align: center; }

.form-toggle { cursor: pointer; user-select: none; display: flex; align-items: center; gap: 8px; }
.form-toggle .toggle-icon { transition: transform 0.2s; display: inline-block; }
.form-toggle .toggle-icon.open { transform: rotate(90deg); }

.form-collapse { max-height: 0; overflow: hidden; transition: max-height 0.35s ease; }
.form-collapse.open { max-height: 800px; }

.form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; }

.susp-table { width: 100%; border-collapse: collapse; }
.susp-table th { text-align: left; padding: 10px 12px; font-size: 0.82rem; color: #64748b; border-bottom: 2px solid #e5e7eb; text-transform: uppercase; letter-spacing: 0.03em; }
.susp-table td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; }
.susp-table tr { transition: background 0.15s; }
.susp-table tr:hover { background: #f8fafc; }
.susp-row { border-left: 4px solid #e5e7eb; }

.tipo-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 0.78rem; font-weight: 600; color: #fff; white-space: nowrap; }

.calendar-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
@media (max-width: 768px) { .calendar-grid { grid-template-columns: repeat(2, 1fr); } }
.cal-month { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px; text-align: center; transition: box-shadow 0.15s; }
.cal-month:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.cal-month .cal-name { font-weight: 600; font-size: 0.9rem; color: #334155; margin-bottom: 6px; }
.cal-month .cal-count { font-size: 1.6rem; font-weight: 700; }
.cal-month .cal-count.zero { color: #94a3b8; }
.cal-month .cal-count.has-days { color: #dc2626; }
.cal-month .cal-sub { font-size: 0.78rem; color: #94a3b8; }
.cal-month.current { border-color: #2563eb; background: #eff6ff; }

.btn-delete-susp { background: none; border: 1px solid #fca5a5; color: #dc2626; border-radius: 6px; padding: 4px 10px; font-size: 0.8rem; cursor: pointer; transition: background 0.15s; }
.btn-delete-susp:hover { background: #fef2f2; }

.empty-state { text-align: center; padding: 40px 20px; color: #94a3b8; }
.empty-state .empty-icon { font-size: 2.5rem; margin-bottom: 8px; }
</style>

<!-- Year navigation -->
<div class="year-nav">
    <a href="<?= e(module_url('operacional', 'prazos_suspensoes.php?ano=' . ($filtroAno - 1) . ($filtroMes ? '&mes=' . $filtroMes : ''))) ?>" class="btn btn-outline" title="Ano anterior">&larr;</a>
    <span class="year-label"><?= $filtroAno ?></span>
    <a href="<?= e(module_url('operacional', 'prazos_suspensoes.php?ano=' . ($filtroAno + 1) . ($filtroMes ? '&mes=' . $filtroMes : ''))) ?>" class="btn btn-outline" title="Proximo ano">&rarr;</a>
    <?php if ($filtroAno !== $currentYear): ?>
        <a href="<?= e(module_url('operacional', 'prazos_suspensoes.php?ano=' . $currentYear)) ?>" class="btn btn-outline" style="font-size:0.82rem;">Ano atual</a>
    <?php endif; ?>
</div>

<!-- Stats -->
<div class="susp-stats">
    <div class="susp-stat-card">
        <div class="stat-value"><?= $totalSusp ?></div>
        <div class="stat-label">Suspensoes</div>
    </div>
    <div class="susp-stat-card">
        <div class="stat-value"><?= $totalDias ?></div>
        <div class="stat-label">Dias suspensos</div>
    </div>
    <div class="susp-stat-card">
        <div class="stat-value"><?= $filtroMes > 0 ? e($meses[$filtroMes]) : 'Todos' ?></div>
        <div class="stat-label">Periodo filtrado</div>
    </div>
</div>

<!-- Month filters -->
<div class="month-filters">
    <a href="<?= e(module_url('operacional', 'prazos_suspensoes.php?ano=' . $filtroAno . '&mes=0')) ?>" class="btn <?= $filtroMes === 0 ? 'active' : 'btn-outline' ?>">Todos</a>
    <?php for ($m = 1; $m <= 12; $m++): ?>
        <a href="<?= e(module_url('operacional', 'prazos_suspensoes.php?ano=' . $filtroAno . '&mes=' . $m)) ?>" class="btn <?= $filtroMes === $m ? 'active' : 'btn-outline' ?>"><?= substr($meses[$m], 0, 3) ?></a>
    <?php endfor; ?>
</div>

<!-- Add suspension form (admin/gestao only) -->
<?php if ($canManage): ?>
<div class="card" style="margin-bottom: 20px;">
    <div class="card-header form-toggle" onclick="toggleForm()">
        <span class="toggle-icon" id="toggleIcon">&#9654;</span>
        <strong>Adicionar Suspensão</strong>
    </div>
    <div class="card-body form-collapse" id="formCollapse">
        <form method="POST" action="<?= e(module_url('operacional', 'prazos_suspensoes.php?ano=' . $filtroAno)) ?>">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="adicionar">
            <div class="form-grid">
                <div>
                    <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:4px;">Data Inicio *</label>
                    <input type="date" name="data_inicio" class="form-input" required>
                </div>
                <div>
                    <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:4px;">Data Fim *</label>
                    <input type="date" name="data_fim" class="form-input" required>
                </div>
                <div>
                    <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:4px;">Tipo</label>
                    <select name="tipo" class="form-select">
                        <?php foreach ($tipoLabels as $val => $label): ?>
                            <option value="<?= e($val) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:4px;">Abrangência</label>
                    <select name="abrangencia" id="selAbrangência" class="form-select" onchange="toggleComarca()">
                        <option value="todo_estado">Todo o Estado</option>
                        <option value="capital">Capital</option>
                        <option value="comarca_especifica">Comarca Especifica</option>
                    </select>
                </div>
                <div id="comarcaWrapper" style="display:none;">
                    <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:4px;">Comarca</label>
                    <select name="comarca" class="form-select">
                        <option value="">-- Selecione --</option>
                        <?php foreach ($comarcas as $c): ?>
                            <option value="<?= e($c) ?>"><?= e($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:4px;">Motivo *</label>
                    <input type="text" name="motivo" class="form-input" required maxlength="300" placeholder="Ex: Natal, Recesso Forense...">
                </div>
                <div>
                    <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:4px;">Ato / Legislação</label>
                    <input type="text" name="ato_legislacao" class="form-input" maxlength="200" placeholder="Ex: Resolução TJ 123/2026">
                </div>
            </div>
            <div style="margin-top: 16px;">
                <button type="submit" class="btn btn-primary">Adicionar</button>
            </div>
        </form>
    </div>
</div>

<!-- Importar do PDF do TJRJ -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-header form-toggle" onclick="toggleImport()">
        <span class="toggle-icon" id="toggleImportIcon">&#9654;</span>
        <strong>Importar do PDF do TJRJ</strong>
    </div>
    <div class="card-body" id="importCollapse" style="display:none;">
        <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:.75rem;">
            Abra o PDF de suspensões do TJRJ, selecione todo o texto (Ctrl+A), copie (Ctrl+C) e cole abaixo.
            O sistema vai detectar automaticamente as datas, motivos e atos legislativos.
        </p>
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:var(--radius);padding:.6rem .8rem;margin-bottom:.75rem;font-size:.78rem;color:#92400e;">
            <strong>Formatos aceitos:</strong><br>
            02/04/2026 a 03/04/2026 - Semana Santa - Ato Executivo 123<br>
            21/04/2026 - Tiradentes<br>
            20/12 a 06/01 - Recesso Forense - Ato TJ 168/2025
        </div>
        <form method="POST">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="importar_texto">
            <textarea name="texto_pdf" class="form-textarea" rows="10" style="font-size:.82rem;font-family:monospace;width:100%;" placeholder="Cole aqui o texto copiado do PDF..."></textarea>
            <div style="margin-top:.5rem;display:flex;gap:.5rem;">
                <button type="submit" class="btn btn-primary">Importar suspensões</button>
                <span style="font-size:.72rem;color:var(--text-muted);align-self:center;">O sistema vai detectar e cadastrar as suspensões automaticamente</span>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Suspensions table -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
        <strong>Suspensoes <?= $filtroAno ?></strong>
        <?php if ($filtroMes > 0): ?>
            <span style="color:#64748b; font-weight:400;"> &mdash; <?= e($meses[$filtroMes]) ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body" style="padding: 0; overflow-x: auto;">
        <?php if (empty($suspensoes)): ?>
            <div class="empty-state">
                <div class="empty-icon">&#128197;</div>
                <p>Nenhuma suspensao encontrada para este periodo.</p>
            </div>
        <?php else: ?>
            <table class="susp-table">
                <thead>
                    <tr>
                        <th>Periodo</th>
                        <th>Tipo</th>
                        <th>Abrangência</th>
                        <th>Comarca</th>
                        <th>Motivo</th>
                        <th>Ato/Legislacao</th>
                        <?php if (has_role('admin')): ?><th style="width:70px;">Acoes</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($suspensoes as $s):
                        $tipoKey = $s['tipo'] ? $s['tipo'] : 'outros';
                        $cor = isset($tipoCores[$tipoKey]) ? $tipoCores[$tipoKey] : '#6b7280';
                        $tipoLabel = isset($tipoLabels[$tipoKey]) ? $tipoLabels[$tipoKey] : ucfirst($tipoKey);
                        $dI = date('d/m', strtotime($s['data_inicio']));
                        $dF = date('d/m', strtotime($s['data_fim']));
                        $periodo = ($s['data_inicio'] === $s['data_fim']) ? $dI : $dI . ' - ' . $dF;
                        $abrangLabel = 'Todo o Estado';
                        if ($s['abrangencia'] === 'capital') $abrangLabel = 'Capital';
                        if ($s['abrangencia'] === 'comarca_especifica') $abrangLabel = 'Comarca';
                    ?>
                    <tr class="susp-row" style="border-left-color: <?= e($cor) ?>;">
                        <td style="white-space:nowrap; font-weight:600;"><?= e($periodo) ?></td>
                        <td><span class="tipo-badge" style="background:<?= e($cor) ?>;"><?= e($tipoLabel) ?></span></td>
                        <td><?= e($abrangLabel) ?></td>
                        <td><?= $s['comarca'] ? e($s['comarca']) : '<span style="color:#cbd5e1;">-</span>' ?></td>
                        <td><?= e($s['motivo']) ?></td>
                        <td style="font-size:0.85rem; color:#64748b;"><?= $s['ato_legislacao'] ? e($s['ato_legislacao']) : '<span style="color:#cbd5e1;">-</span>' ?></td>
                        <?php if (has_role('admin')): ?>
                        <td>
                            <form method="POST" action="<?= e(module_url('operacional', 'prazos_suspensoes.php?ano=' . $filtroAno)) ?>" style="display:inline;" onsubmit="return confirm('Excluir esta suspensao?');">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="excluir">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" class="btn-delete-susp" title="Excluir">&#128465;</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Visual calendar -->
<div class="card">
    <div class="card-header">
        <strong>Calendario de Suspensoes <?= $filtroAno ?></strong>
    </div>
    <div class="card-body">
        <div class="calendar-grid">
            <?php
            $mesAtual = (int)date('n');
            $anoAtual = (int)date('Y');
            for ($m = 1; $m <= 12; $m++):
                $isCurrent = ($m === $mesAtual && $filtroAno === $anoAtual);
                $dias = $calendarData[$m];
            ?>
            <div class="cal-month <?= $isCurrent ? 'current' : '' ?>">
                <div class="cal-name"><?= $meses[$m] ?></div>
                <div class="cal-count <?= $dias > 0 ? 'has-days' : 'zero' ?>"><?= $dias ?></div>
                <div class="cal-sub"><?= $dias === 1 ? 'dia suspenso' : 'dias suspensos' ?></div>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<script>
function toggleForm() {
    var el = document.getElementById('formCollapse');
    var icon = document.getElementById('toggleIcon');
    if (el.className.indexOf('open') !== -1) {
        el.className = el.className.replace(' open', '');
        icon.className = icon.className.replace(' open', '');
    } else {
        el.className += ' open';
        icon.className += ' open';
    }
}

function toggleImport() {
    var el = document.getElementById('importCollapse');
    var icon = document.getElementById('toggleImportIcon');
    if (el.style.display === 'none') {
        el.style.display = 'block';
        icon.innerHTML = '&#9660;';
    } else {
        el.style.display = 'none';
        icon.innerHTML = '&#9654;';
    }
}

function toggleComarca() {
    var sel = document.getElementById('selAbrangência');
    var wrapper = document.getElementById('comarcaWrapper');
    if (sel.value === 'comarca_especifica') {
        wrapper.style.display = '';
    } else {
        wrapper.style.display = 'none';
    }
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
