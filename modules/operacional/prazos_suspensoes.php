<?php
/**
 * Ferreira & Sa Conecta — Suspensões de Prazos
 * Gerenciamento de feriados, recessos e suspensoes forenses.
 */

require_once __DIR__ . '/../../core/middleware.php';
require_access('operacional');

$pageTitle = 'Suspensões de Prazos';
$pdo = db();
$currentYear = (int)date('Y');
$filtroAno = (int)($_GET['ano'] ?? $currentYear);
$filtroMes = isset($_GET['mes']) ? (int)$_GET['mes'] : 0; // 0 = todos
$meses = array('','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro');

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
            // ═══ PARSER TJRJ v3 ═══
            // Estratégia: processar APENAS a Seção 1 (antes de "CONSULTA POR ASSUNTO")
            // que lista suspensões por mês, sem duplicação.
            // Cortar tudo após "CONSULTA POR ASSUNTO"
            $posCorte = strpos($texto, 'CONSULTA POR ASSUNTO');
            if ($posCorte !== false) $texto = substr($texto, 0, $posCorte);

            // Também cortar após "CONSULTA POR COMARCA"
            $posCorte2 = strpos($texto, 'CONSULTA POR COMARCA');
            if ($posCorte2 !== false) $texto = substr($texto, 0, $posCorte2);

            // Mapa de meses para resolver datas sem ano (dd/mm)
            $mesContexto = 0; // mês atual detectado pelo cabeçalho
            $mesesMap = array(
                'Janeiro'=>1,'Fevereiro'=>2,'Março'=>3,'Marco'=>3,'Abril'=>4,'Maio'=>5,
                'Junho'=>6,'Julho'=>7,'Agosto'=>8,'Setembro'=>9,'Outubro'=>10,
                'Novembro'=>11,'Dezembro'=>12
            );

            $linhas = preg_split('/\r?\n/', $texto);
            $blocoAtual = array();
            $resultados = array();

            foreach ($linhas as $l) {
                $l = trim($l);

                // Detectar mês do cabeçalho: "Abril Período Ato..." ou "Abril" isolado
                foreach ($mesesMap as $nomeMes => $numMes) {
                    if (strpos($l, $nomeMes) === 0 && (strlen($l) < 20 || strpos($l, 'Período') !== false)) {
                        $mesContexto = $numMes;
                        break;
                    }
                }

                // Ignorar linhas de lixo
                if ($l === '' || $l === 'Índice' || $l === 'PJERJ') continue;
                if (strpos($l, 'Todo conteúdo') !== false) continue;
                if (strpos($l, 'Portal do Conhecimento') !== false) continue;
                if (strpos($l, 'SUSPENSÃO DE PRAZOS') !== false) continue;
                if (strpos($l, 'CALENDÁRIO DE FERIADOS') !== false) continue;
                if (strpos($l, 'Data da atualização') !== false) continue;
                if (preg_match('/^SÁBADOS:/', $l) || preg_match('/^DOMINGOS:/', $l)) continue;
                if (preg_match('/^▪|^•/', $l)) continue;
                if (strpos($l, 'Período Ato') !== false) continue;
                if (preg_match('/^\d{4}$/', $l)) continue;
                if (strpos($l, 'Suspensão dos Prazos') !== false) continue;
                if (strpos($l, 'Consulta por') !== false || strpos($l, 'Consulta de') !== false) continue;
                // Meses isolados como cabeçalho
                if (isset($mesesMap[$l])) continue;
                if (preg_match('/^(Janeiro|Fevereiro|Março|Abril|Maio|Junho|Julho|Agosto|Setembro|Outubro|Novembro|Dezembro)\s+Período/', $l)) continue;

                // Detectar início de novo bloco: linha que COMEÇA com data dd/mm ou "Período de"
                $isNovaData = preg_match('/^(?:Período de\s+)?\d{2}\/\d{2}/', $l)
                    || preg_match('/^(?:Com início|Transfere)/', $l);

                if ($isNovaData && !empty($blocoAtual)) {
                    // Processar bloco anterior
                    $r = _parsear_bloco_tjrj($blocoAtual, $filtroAno, $mesContexto);
                    if ($r) $resultados[] = $r;
                    $blocoAtual = array();
                }

                $blocoAtual[] = $l;
            }
            // Último bloco
            if (!empty($blocoAtual)) {
                $r = _parsear_bloco_tjrj($blocoAtual, $filtroAno, $mesContexto);
                if ($r) $resultados[] = $r;
            }

            // Inserir resultados (com dedup)
            foreach ($resultados as $res) {
                foreach ($res['datas'] as $dataPar) {
                    $di = $dataPar[0];
                    $df = $dataPar[1];

                    // Verificar duplicidade por data + motivo similar
                    $dupCheck = $pdo->prepare("SELECT COUNT(*) FROM prazos_suspensoes WHERE data_inicio = ? AND data_fim = ?");
                    $dupCheck->execute(array($di, $df));
                    if ((int)$dupCheck->fetchColumn() > 0) continue;

                    try {
                        $pdo->prepare("INSERT INTO prazos_suspensoes (data_inicio, data_fim, tipo, abrangencia, comarca, motivo, ato_legislacao, fonte_pdf, criado_por) VALUES (?,?,?,?,?,?,?,?,?)")
                            ->execute(array($di, $df, $res['tipo'], $res['abrangencia'], $res['comarca'],
                                clean_str($res['motivo'], 300), $res['ato'] ? clean_str($res['ato'], 200) : null,
                                'Importação TJRJ', current_user_id()));
                        $importados++;
                    } catch (Exception $e) {
                        $erros[] = $di . ': ' . $e->getMessage();
                    }
                }
            }
        }

        if ($importados > 0) {
            flash_set('success', $importados . ' suspensão(ões) importada(s)!');
        }
        if (!empty($erros)) {
            flash_set('error', 'Erros: ' . implode('; ', array_slice($erros, 0, 3)));
        }
        if ($importados === 0 && empty($erros)) {
            flash_set('error', 'Nenhuma suspensão nova encontrada (podem já estar cadastradas).');
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

// ═══ Função auxiliar: parsear um bloco do TJRJ ═══
function _parsear_bloco_tjrj($linhas, $anoDefault, $mesContexto) {
    $bloco = implode("\n", $linhas);
    $primeiraLinha = $linhas[0];
    $datas = array();
    $ato = '';
    $motivo = '';
    $comarca = null;
    $abrangencia = 'todo_estado';

    // ── Extrair PERÍODO (datas de suspensão) da PRIMEIRA LINHA apenas ──
    // Padrão: "Período de dd/mm/aaaa a dd/mm/aaaa"
    if (preg_match('#Período de\s*(\d{2}/\d{2}/\d{4})\s*a\s*(\d{2}/\d{2}/\d{4})#', $primeiraLinha, $m)) {
        $d1 = DateTime::createFromFormat('d/m/Y', $m[1]);
        $d2 = DateTime::createFromFormat('d/m/Y', $m[2]);
        if ($d1 && $d2) $datas[] = array($d1->format('Y-m-d'), $d2->format('Y-m-d'));
    }
    // "dd/mm/aaaa a dd/mm/aaaa" (sem "Período de")
    elseif (preg_match('#^(\d{2}/\d{2}/\d{4})\s*a\s*(\d{2}/\d{2}/\d{4})#', $primeiraLinha, $m)) {
        $d1 = DateTime::createFromFormat('d/m/Y', $m[1]);
        $d2 = DateTime::createFromFormat('d/m/Y', $m[2]);
        if ($d1 && $d2) $datas[] = array($d1->format('Y-m-d'), $d2->format('Y-m-d'));
    }
    // "dd/mm a dd/mm" (sem ano — usar mesContexto)
    elseif (preg_match('#^(\d{2}/\d{2})\s*a\s*(\d{2}/\d{2})(?!\d)#', $primeiraLinha, $m)) {
        $d1 = DateTime::createFromFormat('d/m/Y', $m[1] . '/' . $anoDefault);
        $d2 = DateTime::createFromFormat('d/m/Y', $m[2] . '/' . $anoDefault);
        if ($d1 && $d2) $datas[] = array($d1->format('Y-m-d'), $d2->format('Y-m-d'));
    }
    // "dd/mm/aaaa, dd/mm/aaaa e dd/mm/aaaa" ou "dd/mm/aaaa e dd/mm/aaaa" (datas com ano na 1a linha)
    elseif (preg_match_all('#(\d{2}/\d{2}/\d{4})#', $primeiraLinha, $m) && count($m[1]) > 0) {
        foreach ($m[1] as $dStr) {
            $d = DateTime::createFromFormat('d/m/Y', $dStr);
            if ($d) $datas[] = array($d->format('Y-m-d'), $d->format('Y-m-d'));
        }
    }
    // "dd/mm, dd/mm, dd/mm e dd/mm" (sem ano) ou "dd/mm e dd/mm"
    elseif (preg_match('#^(\d{2}/\d{2}(?:[/,]\s*\d{2}/\d{2})*(?:\s*e\s+\d{2}/\d{2})?)#', $primeiraLinha, $m)) {
        preg_match_all('#(\d{2}/\d{2})(?!/\d)#', $m[1], $mDates);
        foreach ($mDates[1] as $dStr) {
            $mes = (int)substr($dStr, 3, 2);
            $ano = $anoDefault;
            $d = DateTime::createFromFormat('d/m/Y', $dStr . '/' . $ano);
            if ($d) $datas[] = array($d->format('Y-m-d'), $d->format('Y-m-d'));
        }
    }
    // "dd/mm" único (sem ano)
    elseif (preg_match('#^(\d{2}/\d{2})(?!\d|/)#', $primeiraLinha, $m)) {
        $d = DateTime::createFromFormat('d/m/Y', $m[1] . '/' . $anoDefault);
        if ($d) $datas[] = array($d->format('Y-m-d'), $d->format('Y-m-d'));
    }

    if (empty($datas)) return null;

    // Ignorar blocos que começam com "Com início ou vencimento" (são prorrogações, não suspensões)
    if (preg_match('/^Com início/i', $primeiraLinha)) return null;
    // Ignorar "Transfere do dia"
    if (preg_match('/^Transfere/i', $primeiraLinha)) return null;

    // ── Extrair ATO (ignorar datas que estão dentro do ato) ──
    foreach ($linhas as $lb) {
        if (preg_match('/^(ATO EXECUTIVO|LEI (?:FEDERAL|ESTADUAL)|ART\.\s*83|DECRETO|AVISO)/i', trim($lb))) {
            $ato = trim($lb);
            break;
        }
    }

    // ── Extrair MOTIVO (última linha significativa) ──
    foreach ($linhas as $lb) {
        $lb = trim($lb);
        // Motivos conhecidos do TJRJ
        if (preg_match('/^(Dia de|Tiradentes|Semana\s+(Santa|do Carnaval)|Confraternização|Carnaval|Natal|Feriado de)/i', $lb)) {
            $motivo = $lb;
            break;
        }
        if (preg_match('/^Resolve\s+(suspender|prorrogar)/i', $lb)) {
            $motivo = mb_substr($lb, 0, 250);
            break;
        }
        if (preg_match('/^Divulga decisão/i', $lb)) {
            $motivo = mb_substr($lb, 0, 250);
            break;
        }
        if (preg_match('/^Estabelece ponto facultativo/i', $lb)) {
            $motivo = mb_substr($lb, 0, 250);
            break;
        }
        if (preg_match('/^Regulamenta/i', $lb)) {
            $motivo = mb_substr($lb, 0, 250);
            break;
        }
    }
    if (!$motivo) $motivo = 'Suspensão de prazos';

    // ── Detectar COMARCA ──
    if (preg_match('/Comarca d[eao]\s+([A-ZÀ-Úa-záéíóúàãõâêîôû\s]+?)(?:,|\.|no dia|nos dias|\)|$)/i', $bloco, $mc)) {
        $comarca = trim($mc[1]);
        if (mb_strlen($comarca) > 3) $abrangencia = 'comarca_especifica';
    }
    if (preg_match('/Comarca da Capital/i', $bloco)) {
        $comarca = 'Capital (Rio de Janeiro)';
        $abrangencia = 'capital';
    }

    // ── Detectar TIPO ──
    $blocoLower = mb_strtolower($bloco);
    if (strpos($blocoLower, 'carnaval') !== false) $tipo = 'carnaval';
    elseif (strpos($blocoLower, 'semana santa') !== false) $tipo = 'semana_santa';
    elseif (strpos($blocoLower, 'recesso') !== false) $tipo = 'recesso';
    elseif (strpos($blocoLower, 'chuva') !== false) $tipo = 'suspensao_chuvas';
    elseif (strpos($blocoLower, 'energia') !== false) $tipo = 'suspensao_energia';
    elseif (strpos($blocoLower, 'normalização do serviço') !== false || strpos($blocoLower, 'indisponibilidade') !== false) $tipo = 'suspensao_sistema';
    elseif (strpos($blocoLower, 'ponto facultativo') !== false) $tipo = 'ponto_facultativo';
    elseif (strpos($blocoLower, 'confraternização') !== false || strpos($blocoLower, 'tiradentes') !== false || strpos($blocoLower, 'lei federal') !== false) $tipo = 'feriado_nacional';
    elseif (strpos($blocoLower, 'são jorge') !== false || strpos($blocoLower, 'lei estadual') !== false || strpos($blocoLower, 'consciência negra') !== false) $tipo = 'feriado_estadual';
    elseif (strpos($blocoLower, 'são sebastião') !== false || strpos($blocoLower, 'lei orgânica') !== false) $tipo = 'feriado_municipal';
    else $tipo = 'outros';

    return array(
        'datas' => $datas,
        'motivo' => $motivo,
        'ato' => $ato,
        'tipo' => $tipo,
        'abrangencia' => $abrangencia,
        'comarca' => $comarca,
    );
}

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
        <div class="stat-label">Suspensões</div>
    </div>
    <div class="susp-stat-card">
        <div class="stat-value"><?= $totalDias ?></div>
        <div class="stat-label">Dias suspensões</div>
    </div>
    <div class="susp-stat-card">
        <div class="stat-value"><?= $filtroMes > 0 ? e($meses[$filtroMes]) : 'Todos' ?></div>
        <div class="stat-label">Período filtrado</div>
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
            Acesse o <a href="https://www.tjrj.jus.br/web/guest/informativo-de-suspensao-dos-prazos-processuais-e-expediente-forense" target="_blank" style="color:var(--rose);font-weight:600;">site do TJRJ</a>, selecione todo o texto da página (Ctrl+A), copie (Ctrl+C) e cole abaixo.
            O sistema filtra automaticamente o lixo e extrai apenas as suspensões reais.
        </p>
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:var(--radius);padding:.6rem .8rem;margin-bottom:.75rem;font-size:.78rem;color:#166534;">
            <strong>O parser inteligente detecta:</strong><br>
            - Feriados nacionais e estaduais com ato legislativo<br>
            - Suspensões por comarca (chuvas, energia, sistema)<br>
            - Recesso forense, Carnaval, Semana Santa<br>
            - Ignora cabeçalhos, rodapés, índices e textos repetidos<br>
            - Verifica duplicidade (não importa o que já existe)
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
        <strong>Suspensões <?= $filtroAno ?></strong>
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
        <strong>Calendário de Suspensões <?= $filtroAno ?></strong>
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
