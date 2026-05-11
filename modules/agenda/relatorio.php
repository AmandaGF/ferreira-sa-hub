<?php
/**
 * Agenda — Relatorio de compromissos por periodo (imprimivel).
 * Filtros: periodo (esta/proxima semana/mes ou customizado) + tipos + responsavel.
 * Agrupa por dia, mostra modalidade (ONLINE/PRESENCIAL/HIBRIDA) + horario + local +
 * cliente/processo + responsavel + subtipo (pra audiencia). Otimizado pra impressao
 * via @media print (esconde form + header da pagina, formata em A4).
 * Pedido pela Amanda 11/05/2026.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$pageTitle = 'Relatorio da Agenda';

$tiposMapa = array(
    'audiencia'        => array('cor' => '#e67e22', 'icon' => '⚖', 'label' => 'Audiência'),
    'reuniao_cliente'  => array('cor' => '#B87333', 'icon' => '👤', 'label' => 'Reunião com cliente'),
    'prazo'            => array('cor' => '#CC0000', 'icon' => '⏰', 'label' => 'Prazo processual'),
    'onboarding'       => array('cor' => '#2D7A4F', 'icon' => '🎯', 'label' => 'Onboarding'),
    'reuniao_interna'  => array('cor' => '#1a3a7a', 'icon' => '👥', 'label' => 'Reunião interna'),
    'mediacao_cejusc'  => array('cor' => '#6B4C9A', 'icon' => '🤝', 'label' => 'Mediação / CEJUSC'),
    'balcao_virtual'   => array('cor' => '#0d9488', 'icon' => '🏛', 'label' => 'Balcão Virtual'),
    'ligacao'          => array('cor' => '#888880', 'icon' => '📞', 'label' => 'Ligação / Retorno'),
);

// ── Helpers de periodo ──
function _hoje() { return date('Y-m-d'); }
function _semana_atual() {
    // Segunda a domingo da semana atual
    $hoje = new DateTime(_hoje());
    $diaSemana = (int)$hoje->format('N'); // 1=seg, 7=dom
    $seg = clone $hoje; $seg->modify('-' . ($diaSemana - 1) . ' days');
    $dom = clone $seg;  $dom->modify('+6 days');
    return array($seg->format('Y-m-d'), $dom->format('Y-m-d'));
}
function _proxima_semana() {
    list($_s, $d) = _semana_atual();
    $seg = new DateTime($d); $seg->modify('+1 day');
    $dom = clone $seg;       $dom->modify('+6 days');
    return array($seg->format('Y-m-d'), $dom->format('Y-m-d'));
}
function _mes_atual() {
    $ini = new DateTime(_hoje()); $ini->modify('first day of this month');
    $fim = new DateTime(_hoje()); $fim->modify('last day of this month');
    return array($ini->format('Y-m-d'), $fim->format('Y-m-d'));
}
function _proximo_mes() {
    $ini = new DateTime(_hoje()); $ini->modify('first day of next month');
    $fim = new DateTime(_hoje()); $fim->modify('last day of next month');
    return array($ini->format('Y-m-d'), $fim->format('Y-m-d'));
}

// ── Le filtros (preset OU customizado) ──
$preset = $_GET['preset'] ?? 'mes_atual';
$dataIni = $_GET['data_ini'] ?? '';
$dataFim = $_GET['data_fim'] ?? '';

if ($preset === 'semana_atual')    list($dataIni, $dataFim) = _semana_atual();
elseif ($preset === 'proxima_semana') list($dataIni, $dataFim) = _proxima_semana();
elseif ($preset === 'mes_atual')   list($dataIni, $dataFim) = _mes_atual();
elseif ($preset === 'proximo_mes') list($dataIni, $dataFim) = _proximo_mes();
// 'custom' usa as datas do form direto

if (!$dataIni || !$dataFim) { list($dataIni, $dataFim) = _mes_atual(); $preset = 'mes_atual'; }

$tiposSel = $_GET['tipos'] ?? array_keys($tiposMapa); // default: todos
if (!is_array($tiposSel)) $tiposSel = explode(',', $tiposSel);
$tiposSel = array_values(array_intersect($tiposSel, array_keys($tiposMapa)));
if (!$tiposSel) $tiposSel = array_keys($tiposMapa);

$responsavel = (int)($_GET['responsavel'] ?? 0); // 0 = todos

$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

// ── Busca eventos do periodo ──
$tipoPlaceholders = implode(',', array_fill(0, count($tiposSel), '?'));
$params = array_merge(array($dataIni . ' 00:00:00', $dataFim . ' 23:59:59'), $tiposSel);
$sqlExtra = '';
if ($responsavel) { $sqlExtra = ' AND e.responsavel_id = ?'; $params[] = $responsavel; }

$sql = "SELECT e.*, u.name AS responsavel_name, cs.case_number, cs.title AS case_title, cs.comarca, cs.court,
               c.name AS client_name
        FROM agenda_eventos e
        LEFT JOIN users u    ON u.id = e.responsavel_id
        LEFT JOIN cases cs   ON cs.id = e.case_id
        LEFT JOIN clients c  ON c.id = cs.client_id
        WHERE e.data_inicio BETWEEN ? AND ?
          AND e.tipo IN ($tipoPlaceholders)
          AND e.status NOT IN ('cancelado')
          $sqlExtra
        ORDER BY e.data_inicio ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$eventos = $stmt->fetchAll();

// Agrupa por dia
$porDia = array();
$totaisPorTipo = array();
foreach ($eventos as $ev) {
    $d = substr($ev['data_inicio'], 0, 10);
    if (!isset($porDia[$d])) $porDia[$d] = array();
    $porDia[$d][] = $ev;
    $t = $ev['tipo'];
    $totaisPorTipo[$t] = ($totaisPorTipo[$t] ?? 0) + 1;
}

// Helper modalidade
function modalidadeBadge($ev) {
    $mod = $ev['modalidade'] ?? '';
    if (!$mod || $mod === 'nao_aplicavel') {
        if (!empty($ev['meet_link']) && !empty($ev['local'])) $mod = 'hibrida';
        elseif (!empty($ev['meet_link'])) $mod = 'online';
        elseif (!empty($ev['local'])) $mod = 'presencial';
    }
    if ($mod === 'online')     return array('label' => 'ONLINE',     'bg' => '#dbeafe', 'color' => '#1e40af', 'ico' => '🌐');
    if ($mod === 'presencial') return array('label' => 'PRESENCIAL', 'bg' => '#fef3c7', 'color' => '#92400e', 'ico' => '📍');
    if ($mod === 'hibrida')    return array('label' => 'HÍBRIDA',    'bg' => '#ede9fe', 'color' => '#6d28d9', 'ico' => '🔀');
    return null;
}

function diaSemanaBr($data) {
    $dias = array('Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado');
    return $dias[(int)date('w', strtotime($data))];
}
function formatarCnj($num) {
    $d = preg_replace('/\D/', '', (string)$num);
    if (strlen($d) !== 20) return $num;
    return substr($d,0,7).'-'.substr($d,7,2).'.'.substr($d,9,4).'.'.substr($d,13,1).'.'.substr($d,14,2).'.'.substr($d,16,4);
}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.rel-wrap { max-width: 950px; margin: 0 auto; padding: 1rem 1.5rem; }
.rel-form { background:#f9fafb; border:1px solid #e5e7eb; border-radius:10px; padding:1.25rem; margin-bottom:1.5rem; }
.rel-form h2 { margin:0 0 1rem; font-size:1.1rem; color:#0f2140; }
.rel-form-row { display:flex; gap:.75rem; flex-wrap:wrap; align-items:center; margin-bottom:.85rem; }
.rel-form label { font-size:.8rem; font-weight:600; color:#374151; }
.rel-form input[type=date], .rel-form select { padding:6px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:.85rem; }
.rel-presets { display:flex; gap:.4rem; flex-wrap:wrap; }
.rel-preset-btn { background:#fff; border:1.5px solid #e5e7eb; border-radius:18px; padding:5px 12px; font-size:.78rem; font-weight:600; cursor:pointer; color:#6b7280; }
.rel-preset-btn:hover { border-color:#6366f1; color:#4338ca; }
.rel-preset-btn.ativo { background:#6366f1; color:#fff; border-color:#6366f1; }
.rel-tipos { display:flex; gap:.4rem; flex-wrap:wrap; }
.rel-tipo-chip { display:inline-flex; align-items:center; gap:5px; padding:5px 10px; border:1.5px solid #e5e7eb; border-radius:18px; font-size:.78rem; cursor:pointer; background:#fff; user-select:none; }
.rel-tipo-chip input { margin:0; }
.rel-tipo-chip.ativo { background:#eef2ff; border-color:#6366f1; color:#4338ca; }
.rel-btn-gerar { background:#6366f1; color:#fff; border:none; border-radius:8px; padding:9px 22px; font-size:.9rem; font-weight:700; cursor:pointer; }
.rel-btn-print { background:#0f2140; color:#fff; border:none; border-radius:8px; padding:9px 18px; font-size:.85rem; font-weight:700; cursor:pointer; }

.rel-head-doc { display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #0f2140; padding-bottom:.75rem; margin-bottom:1.25rem; }
.rel-head-doc h1 { margin:0; font-family:'Playfair Display', serif; font-size:1.5rem; color:#0f2140; }
.rel-head-doc-info { font-size:.78rem; color:#6b7280; text-align:right; }

.rel-resumo { background:#f0f9ff; border:1px solid #bae6fd; border-left:4px solid #0ea5e9; border-radius:8px; padding:.75rem 1rem; margin-bottom:1.25rem; font-size:.85rem; }
.rel-resumo strong { color:#0c4a6e; }

.rel-dia { margin-bottom:1.25rem; page-break-inside:avoid; }
.rel-dia-h { background:#0f2140; color:#fff; padding:.5rem .85rem; border-radius:6px 6px 0 0; font-size:.92rem; font-weight:700; display:flex; justify-content:space-between; align-items:center; }
.rel-dia-h small { font-weight:400; opacity:.85; font-size:.75rem; }
.rel-dia-body { border:1px solid #e5e7eb; border-top:none; border-radius:0 0 6px 6px; padding:.4rem 0; }

.rel-ev { padding:.6rem .85rem; border-bottom:1px solid #f3f4f6; display:flex; gap:.85rem; }
.rel-ev:last-child { border-bottom:none; }
.rel-ev-hora { flex-shrink:0; width:100px; font-weight:700; color:#0f2140; font-size:.95rem; }
.rel-ev-corpo { flex:1; min-width:0; font-size:.83rem; line-height:1.5; }
.rel-ev-tit { font-weight:700; color:#0f2140; margin-bottom:.15rem; font-size:.92rem; }
.rel-badge-tipo { display:inline-block; padding:1px 8px; border-radius:4px; font-size:.7rem; font-weight:700; color:#fff; margin-right:6px; }
.rel-badge-mod { display:inline-block; padding:1px 8px; border-radius:4px; font-size:.68rem; font-weight:700; border:1px solid; margin-right:6px; }
.rel-ev-meta { color:#6b7280; font-size:.78rem; margin-top:.2rem; }
.rel-ev-meta strong { color:#374151; }
.rel-ev-cliente { font-size:.78rem; color:#0f2140; margin-top:.2rem; }
.rel-empty { text-align:center; padding:3rem; color:#9ca3af; font-size:.9rem; background:#fafafa; border-radius:8px; }

.rel-footer-tot { background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:.85rem 1rem; margin-top:1.5rem; font-size:.85rem; }
.rel-footer-tot strong { color:#0f2140; }
.rel-tot-chip { display:inline-block; padding:3px 10px; border-radius:14px; font-size:.75rem; font-weight:700; color:#fff; margin:2px 4px 2px 0; }

@media print {
    body { background:#fff !important; }
    .layout-sidebar, .layout-header, .rel-form, .no-print, header, .sidebar, nav { display:none !important; }
    .rel-wrap { max-width:none; padding:0; margin:0; }
    .rel-head-doc { margin-top:0; }
    .rel-dia { page-break-inside:avoid; }
    a { color:inherit; text-decoration:none; }
    .rel-ev { padding:.4rem .5rem; }
}
</style>

<div class="rel-wrap">
    <!-- Formulario de filtros (so visivel na tela, escondido no print) -->
    <form method="GET" class="rel-form no-print" id="relForm">
        <h2>📊 Relatório de Compromissos</h2>

        <div class="rel-form-row">
            <label>Período:</label>
            <div class="rel-presets">
                <?php foreach (array('semana_atual'=>'Esta semana','proxima_semana'=>'Próxima semana','mes_atual'=>'Este mês','proximo_mes'=>'Próximo mês','custom'=>'Personalizado') as $k => $lbl): ?>
                <button type="button" class="rel-preset-btn <?= $preset === $k ? 'ativo' : '' ?>" data-preset="<?= $k ?>" onclick="selPreset('<?= $k ?>')"><?= e($lbl) ?></button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="rel-form-row" id="customRow" style="<?= $preset === 'custom' ? '' : 'display:none;' ?>">
            <label>De:</label>
            <input type="date" name="data_ini" value="<?= e($dataIni) ?>" id="dataIni">
            <label>até:</label>
            <input type="date" name="data_fim" value="<?= e($dataFim) ?>" id="dataFim">
        </div>
        <input type="hidden" name="preset" id="presetInput" value="<?= e($preset) ?>">

        <div class="rel-form-row">
            <label>Tipos de compromisso:</label>
            <div class="rel-tipos">
                <?php foreach ($tiposMapa as $k => $t): $checado = in_array($k, $tiposSel, true); ?>
                <label class="rel-tipo-chip <?= $checado ? 'ativo' : '' ?>" data-tipo="<?= $k ?>">
                    <input type="checkbox" name="tipos[]" value="<?= $k ?>" <?= $checado ? 'checked' : '' ?> onchange="this.closest('label').classList.toggle('ativo', this.checked)">
                    <?= $t['icon'] ?> <?= e($t['label']) ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="rel-form-row">
            <label>Responsável:</label>
            <select name="responsavel">
                <option value="0">Todos</option>
                <?php foreach ($users as $u): ?>
                <option value="<?= $u['id'] ?>" <?= $responsavel === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="rel-btn-gerar">📊 Gerar relatório</button>
            <button type="button" class="rel-btn-print" onclick="window.print()">🖨 Imprimir</button>
        </div>
    </form>

    <!-- Cabecalho do documento (visivel na tela e na impressao) -->
    <div class="rel-head-doc">
        <div>
            <h1>📊 Relatório de Compromissos</h1>
            <div style="font-size:.85rem;color:#6b7280;margin-top:.2rem;">Ferreira & Sá Advocacia</div>
        </div>
        <div class="rel-head-doc-info">
            Período: <strong style="color:#0f2140;"><?= date('d/m/Y', strtotime($dataIni)) ?></strong> a <strong style="color:#0f2140;"><?= date('d/m/Y', strtotime($dataFim)) ?></strong><br>
            Gerado em: <?= date('d/m/Y \à\s H:i') ?>
        </div>
    </div>

    <div class="rel-resumo">
        <strong>Total: <?= count($eventos) ?> compromisso(s)</strong>
        <?php if ($responsavel): $rn = ''; foreach ($users as $u) if ((int)$u['id'] === $responsavel) $rn = $u['name']; ?>
            · Responsável: <strong><?= e($rn) ?></strong>
        <?php endif; ?>
    </div>

    <?php if (!$eventos): ?>
        <div class="rel-empty">📭 Nenhum compromisso no período e filtros selecionados.</div>
    <?php else: ?>
        <?php foreach ($porDia as $data => $evs): ?>
        <div class="rel-dia">
            <div class="rel-dia-h">
                <span><?= diaSemanaBr($data) ?>, <?= date('d/m/Y', strtotime($data)) ?></span>
                <small><?= count($evs) ?> compromisso(s)</small>
            </div>
            <div class="rel-dia-body">
                <?php foreach ($evs as $ev):
                    $tCfg = $tiposMapa[$ev['tipo']] ?? array('cor'=>'#888','icon'=>'📌','label'=>$ev['tipo']);
                    $mod = modalidadeBadge($ev);
                    $hIni = substr($ev['data_inicio'], 11, 5);
                    $hFim = !empty($ev['data_fim']) ? substr($ev['data_fim'], 11, 5) : '';
                ?>
                <div class="rel-ev">
                    <div class="rel-ev-hora"><?= $hIni ?><?= $hFim ? ' – ' . $hFim : '' ?></div>
                    <div class="rel-ev-corpo">
                        <div>
                            <span class="rel-badge-tipo" style="background:<?= $tCfg['cor'] ?>;"><?= $tCfg['icon'] ?> <?= e($tCfg['label']) ?></span>
                            <?php if ($mod): ?>
                            <span class="rel-badge-mod" style="background:<?= $mod['bg'] ?>;color:<?= $mod['color'] ?>;border-color:<?= $mod['color'] ?>;"><?= $mod['ico'] ?> <?= $mod['label'] ?></span>
                            <?php endif; ?>
                            <?php if (!empty($ev['subtipo']) && $ev['tipo'] === 'audiencia'): ?>
                            <span style="font-size:.72rem;color:#6b7280;">· <?= e(str_replace('_', ' ', $ev['subtipo'])) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="rel-ev-tit"><?= e($ev['titulo']) ?></div>

                        <?php if (!empty($ev['local'])): ?>
                        <div class="rel-ev-meta">📍 <strong>Local:</strong> <?= e($ev['local']) ?></div>
                        <?php endif; ?>

                        <?php if (!empty($ev['meet_link'])): ?>
                        <div class="rel-ev-meta">🎥 <strong>Link:</strong> <span style="word-break:break-all;"><?= e($ev['meet_link']) ?></span></div>
                        <?php endif; ?>

                        <?php if (!empty($ev['client_name']) || !empty($ev['case_title'])): ?>
                        <div class="rel-ev-cliente">
                            <?php if (!empty($ev['client_name'])): ?>👤 <strong><?= e($ev['client_name']) ?></strong><?php endif; ?>
                            <?php if (!empty($ev['case_title'])): ?> · 📂 <?= e($ev['case_title']) ?><?php endif; ?>
                            <?php if (!empty($ev['case_number'])): ?> · <span style="font-family:monospace;font-size:.75rem;color:#6b7280;"><?= e(formatarCnj($ev['case_number'])) ?></span><?php endif; ?>
                            <?php if (!empty($ev['comarca']) || !empty($ev['court'])): ?>
                                <div style="font-size:.73rem;color:#6b7280;margin-top:.15rem;">
                                    <?php if (!empty($ev['court'])): ?><?= e($ev['court']) ?><?php endif; ?>
                                    <?php if (!empty($ev['comarca'])): ?> · <?= e($ev['comarca']) ?><?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($ev['responsavel_name'])): ?>
                        <div class="rel-ev-meta">👤 Responsável: <strong><?= e($ev['responsavel_name']) ?></strong></div>
                        <?php endif; ?>

                        <?php if (!empty($ev['descricao'])): ?>
                        <div class="rel-ev-meta" style="font-style:italic;">"<?= e($ev['descricao']) ?>"</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="rel-footer-tot">
            <strong>Resumo por tipo:</strong>&nbsp;
            <?php foreach ($totaisPorTipo as $t => $n):
                $tCfg = $tiposMapa[$t] ?? array('cor'=>'#888','label'=>$t);
            ?>
            <span class="rel-tot-chip" style="background:<?= $tCfg['cor'] ?>;"><?= e($tCfg['label']) ?>: <?= $n ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function selPreset(p) {
    document.querySelectorAll('.rel-preset-btn').forEach(function(b){ b.classList.toggle('ativo', b.dataset.preset === p); });
    document.getElementById('presetInput').value = p;
    document.getElementById('customRow').style.display = (p === 'custom') ? 'flex' : 'none';
    if (p !== 'custom') {
        // Auto-submit nos presets fixos pra UX rapida
        document.getElementById('relForm').submit();
    }
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
