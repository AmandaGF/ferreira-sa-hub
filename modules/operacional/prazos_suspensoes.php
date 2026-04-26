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

    // ═══ PREVIEW XHR: parseia o texto colado do TJRJ e devolve a lista
    //     de suspensões detectadas pra Amanda revisar antes de salvar ═══
    if ($action === 'preview_tjrj') {
        header('Content-Type: application/json; charset=utf-8');
        $texto = $_POST['texto_pdf'] ?? '';
        if (trim($texto) === '') {
            echo json_encode(array('ok' => false, 'erro' => 'Cole o texto antes de analisar.'));
            exit;
        }

        $resultados = _parsear_suspensoes_tjrj_completo($texto, $filtroAno);

        // Achata em linhas individuais (1 par data_inicio/data_fim por item) e marca dup
        $itens = array();
        $stmtChk = $pdo->prepare("SELECT id FROM prazos_suspensoes WHERE data_inicio = ? AND data_fim = ? LIMIT 1");
        foreach ($resultados as $res) {
            foreach ($res['datas'] as $par) {
                $di = $par[0]; $df = $par[1];
                $stmtChk->execute(array($di, $df));
                $jaExiste = !!$stmtChk->fetchColumn();
                $itens[] = array(
                    'data_inicio' => $di,
                    'data_fim'    => $df,
                    'tipo'        => $res['tipo'],
                    'abrangencia' => $res['abrangencia'],
                    'comarca'     => $res['comarca'],
                    'motivo'      => $res['motivo'],
                    'ato'         => $res['ato'],
                    'ja_existe'   => $jaExiste,
                );
            }
        }

        echo json_encode(array('ok' => true, 'itens' => $itens));
        exit;
    }

    // ═══ SALVAR seleção do preview ═══
    if ($action === 'salvar_suspensoes_selecionadas') {
        header('Content-Type: application/json; charset=utf-8');
        $payload = json_decode($_POST['items'] ?? '[]', true);
        if (!is_array($payload)) $payload = array();

        $importados = 0;
        $duplicatas = 0;
        $erros      = 0;
        $stmtChk = $pdo->prepare("SELECT id FROM prazos_suspensoes WHERE data_inicio = ? AND data_fim = ? LIMIT 1");
        $stmtIns = $pdo->prepare("INSERT INTO prazos_suspensoes (data_inicio, data_fim, tipo, abrangencia, comarca, motivo, ato_legislacao, fonte_pdf, criado_por) VALUES (?,?,?,?,?,?,?,?,?)");
        $userId  = current_user_id();
        foreach ($payload as $it) {
            $di = isset($it['data_inicio']) ? trim($it['data_inicio']) : '';
            $df = isset($it['data_fim'])    ? trim($it['data_fim'])    : '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $di) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $df)) continue;
            $stmtChk->execute(array($di, $df));
            if ($stmtChk->fetchColumn()) { $duplicatas++; continue; }
            try {
                $stmtIns->execute(array(
                    $di, $df,
                    isset($it['tipo'])        ? clean_str($it['tipo'], 50)         : 'outros',
                    isset($it['abrangencia']) ? clean_str($it['abrangencia'], 30)  : 'todo_estado',
                    !empty($it['comarca'])    ? clean_str($it['comarca'], 100)     : null,
                    isset($it['motivo'])      ? clean_str($it['motivo'], 300)      : 'Suspensão de prazos',
                    !empty($it['ato'])        ? clean_str($it['ato'], 200)         : null,
                    'Importação TJRJ',
                    $userId,
                ));
                $importados++;
            } catch (Exception $e) {
                $erros++;
            }
        }

        // Atualiza o timestamp da última importação TJRJ
        try {
            $stmtCfg = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('suspensoes_tjrj_atualizado_em', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
            $stmtCfg->execute(array(date('Y-m-d H:i:s')));
        } catch (Exception $e) { /* tabela pode não existir em ambientes velhos */ }

        echo json_encode(array(
            'ok'         => true,
            'importados' => $importados,
            'duplicatas' => $duplicatas,
            'erros'      => $erros,
        ));
        exit;
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

// Última atualização do TJRJ (se já foi importado alguma vez)
$ultimaAtualizTjrj = '';
try {
    $stmtLast = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'suspensoes_tjrj_atualizado_em' LIMIT 1");
    $stmtLast->execute();
    $rowLast = $stmtLast->fetchAll();
    if (!empty($rowLast)) $ultimaAtualizTjrj = (string)$rowLast[0]['valor'];
} catch (Exception $e) { /* tabela configuracoes pode não existir */ }
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

// ═══ Pré-processamento: PDFs colados do TJRJ costumam vir como 1 parágrafo
//     gigante (sem quebras de linha reais). Inserimos \n antes dos padrões
//     que marcam INÍCIO de entrada pra o parser linha-por-linha funcionar.
function _tjrj_normalizar_texto($texto) {
    // Normaliza whitespace (substitui qualquer combinação de \r\n\t e espaços por 1 espaço)
    $texto = preg_replace('/[\r\n\t ]+/u', ' ', $texto);

    // Quebras de linha antes de cada padrão que inicia uma nova entrada:
    $patterns = array(
        // Data(s) com ou sem ano (suporta múltiplas: "13/02, 16/02 e 18/02") seguida de palavra-chave de ato
        '/ (?=\d{1,2}\/\d{1,2}(?:\/\d{4})?(?:[,\s]+(?:e\s+)?\d{1,2}\/\d{1,2}(?:\/\d{4})?)*\s+(?:ATO\s+EXECUTIVO|ATO\s+ADMINISTRATIVO|LEI\s+(?:FEDERAL|ESTADUAL|ORGÂNICA)|DECRETO\s+(?:Nº|N°|ESTADUAL)|DECRETO|AVISO|ART\.\s*\d+|Lei\s+(?:Federal|Estadual|Orgânica)))/u',
        // "Período de DD/MM..."
        '/ (?=Período\s+de\s+\d{2}\/\d{2})/u',
        // "Período de recesso forense..."
        '/ (?=Período\s+de\s+recesso)/u',
        // "Com início ou vencimento..."
        '/ (?=Com\s+início\s+ou\s+vencimento)/u',
        // "Transfere do dia..."
        '/ (?=Transfere\s+do\s+dia)/u',
        // Cabeçalhos de mês "Janeiro Período", "Fevereiro Período" etc.
        '/ (?=(?:Janeiro|Fevereiro|Março|Marco|Abril|Maio|Junho|Julho|Agosto|Setembro|Outubro|Novembro|Dezembro)\s+Período)/u',
    );
    foreach ($patterns as $p) {
        $texto = preg_replace($p, "\n", $texto);
    }
    return $texto;
}

// ═══ Parser principal: recebe o texto bruto colado da página TJRJ e
//     retorna array de blocos parseados (cada um com `datas`, `motivo`,
//     `abrangencia`, `comarca`, `tipo`, `ato`). NÃO insere nada — devolve
//     pra o caller decidir se mostra preview ou insere direto.
function _parsear_suspensoes_tjrj_completo($texto, $anoDefault) {
    // Pré-processa pra inserir \n nos pontos certos (PDF copy vem como
    // 1 parágrafo gigante)
    $texto = _tjrj_normalizar_texto($texto);

    // Cortar tudo após "CONSULTA POR ASSUNTO" — daqui pra frente as suspensões
    // se repetem agrupadas por categoria, gerando duplicatas no parse.
    $posCorte = strpos($texto, 'CONSULTA POR ASSUNTO');
    if ($posCorte !== false) $texto = substr($texto, 0, $posCorte);
    $posCorte2 = strpos($texto, 'CONSULTA POR COMARCA');
    if ($posCorte2 !== false) $texto = substr($texto, 0, $posCorte2);

    $mesesMap = array(
        'Janeiro'=>1,'Fevereiro'=>2,'Março'=>3,'Marco'=>3,'Abril'=>4,'Maio'=>5,
        'Junho'=>6,'Julho'=>7,'Agosto'=>8,'Setembro'=>9,'Outubro'=>10,
        'Novembro'=>11,'Dezembro'=>12
    );

    $linhas = preg_split('/\r?\n/', $texto);
    $blocoAtual = array();
    $resultados = array();
    $mesContexto = 0;

    foreach ($linhas as $l) {
        $l = trim($l);
        foreach ($mesesMap as $nomeMes => $numMes) {
            if (strpos($l, $nomeMes) === 0 && (strlen($l) < 20 || strpos($l, 'Período') !== false)) {
                $mesContexto = $numMes; break;
            }
        }
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
        if (isset($mesesMap[$l])) continue;
        if (preg_match('/^(Janeiro|Fevereiro|Março|Abril|Maio|Junho|Julho|Agosto|Setembro|Outubro|Novembro|Dezembro)\s+Período/', $l)) continue;

        $isNovaData = preg_match('/^(?:Período de\s+)?\d{2}\/\d{2}/', $l)
            || preg_match('/^(?:Com início|Transfere)/', $l);

        if ($isNovaData && !empty($blocoAtual)) {
            $r = _parsear_bloco_tjrj($blocoAtual, $anoDefault, $mesContexto);
            if ($r) $resultados[] = $r;
            $blocoAtual = array();
        }
        $blocoAtual[] = $l;
    }
    if (!empty($blocoAtual)) {
        $r = _parsear_bloco_tjrj($blocoAtual, $anoDefault, $mesContexto);
        if ($r) $resultados[] = $r;
    }
    return $resultados;
}

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

<!-- Voltar pra Calculadora -->
<div style="margin-bottom:1rem;">
    <a href="<?= e(module_url('operacional', 'prazos_calc.php')) ?>" class="btn btn-outline btn-sm" style="font-size:.82rem;">&larr; Voltar à Calculadora de Prazos</a>
</div>

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
        <strong>🔄 Atualizar suspensões do TJRJ</strong>
        <?php if ($ultimaAtualizTjrj): ?>
            <span style="margin-left:auto;font-size:.74rem;color:#0369a1;background:#e0f2fe;padding:3px 10px;border-radius:99px;font-weight:600;">
                Última atualização: <?= e(date('d/m/Y H:i', strtotime($ultimaAtualizTjrj))) ?>
            </span>
        <?php else: ?>
            <span style="margin-left:auto;font-size:.74rem;color:#92400e;background:#fef3c7;padding:3px 10px;border-radius:99px;font-weight:600;">
                Nunca atualizado pelo TJRJ
            </span>
        <?php endif; ?>
    </div>
    <div class="card-body" id="importCollapse" style="display:none;">
        <ol style="font-size:.85rem;color:var(--text);margin:0 0 1rem;padding-left:1.4rem;line-height:1.7;">
            <li><strong>Abra o portal oficial do TJRJ</strong> em outra aba:
                <a href="https://www.tjrj.jus.br/web/portal-conhecimento/feriados-locais-e-suspensao-de-prazos"
                   target="_blank" rel="noopener"
                   style="display:inline-block;background:#0369a1;color:#fff;padding:4px 12px;border-radius:6px;font-size:.78rem;font-weight:700;text-decoration:none;margin-left:6px;">
                   🔗 tjrj.jus.br/feriados-locais-e-suspensao-de-prazos
                </a>
            </li>
            <li><strong>Selecione todo o conteúdo do PDF</strong> que abrir (Ctrl+A) e copie (Ctrl+C).</li>
            <li><strong>Cole no campo abaixo</strong> e clique em "🔍 Analisar texto".</li>
            <li>O sistema mostra um <strong>preview com as suspensões detectadas</strong> — você revisa, marca/desmarca e salva só o que estiver correto.</li>
        </ol>

        <textarea id="textoPdfTjrj" class="form-textarea" rows="10" style="font-size:.82rem;font-family:monospace;width:100%;" placeholder="Cole aqui o conteúdo da página do TJRJ (Ctrl+V)..."></textarea>
        <div style="margin-top:.5rem;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
            <button type="button" id="btnAnalisarTjrj" onclick="suspensoesAnalisarTexto()" class="btn btn-primary">🔍 Analisar texto</button>
            <button type="button" onclick="document.getElementById('textoPdfTjrj').value=''; document.getElementById('previewSuspensoes').style.display='none';" class="btn btn-outline btn-sm">Limpar</button>
            <span id="suspensoesStatus" style="font-size:.78rem;color:var(--text-muted);"></span>
        </div>

        <!-- Preview (popula via JS após análise) -->
        <div id="previewSuspensoes" style="display:none;margin-top:1.2rem;padding:1rem;background:#fffbeb;border:1.5px solid #fde68a;border-radius:8px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.6rem;flex-wrap:wrap;gap:.5rem;">
                <strong style="color:#92400e;">📋 Preview — revise antes de salvar</strong>
                <span id="previewResumo" style="font-size:.78rem;color:#78350f;"></span>
            </div>
            <div style="overflow-x:auto;">
                <table id="previewTabela" style="width:100%;border-collapse:collapse;font-size:.78rem;background:#fff;">
                    <thead>
                        <tr style="background:#fef3c7;color:#78350f;">
                            <th style="padding:6px 8px;text-align:center;width:40px;">
                                <input type="checkbox" id="previewSelTodos" onchange="suspensoesToggleTodos(this)" checked>
                            </th>
                            <th style="padding:6px 8px;text-align:left;">Início</th>
                            <th style="padding:6px 8px;text-align:left;">Fim</th>
                            <th style="padding:6px 8px;text-align:left;">Tipo</th>
                            <th style="padding:6px 8px;text-align:left;">Abrangência</th>
                            <th style="padding:6px 8px;text-align:left;">Comarca</th>
                            <th style="padding:6px 8px;text-align:left;">Motivo</th>
                            <th style="padding:6px 8px;text-align:left;">Status</th>
                        </tr>
                    </thead>
                    <tbody id="previewLinhas"></tbody>
                </table>
            </div>
            <div style="margin-top:.8rem;display:flex;gap:.5rem;">
                <button type="button" id="btnSalvarSelecionadas" onclick="suspensoesSalvarSelecionadas()" class="btn btn-primary">✅ Salvar selecionadas</button>
                <button type="button" onclick="document.getElementById('previewSuspensoes').style.display='none';" class="btn btn-outline btn-sm">Cancelar</button>
            </div>
        </div>
    </div>
</div>

<script>
var SUSPENSOES_CSRF = <?= json_encode($csrf ?? generate_csrf_token()) ?>;
var SUSPENSOES_URL  = window.location.pathname;

function suspensoesAnalisarTexto() {
    var textarea = document.getElementById('textoPdfTjrj');
    var status   = document.getElementById('suspensoesStatus');
    var btn      = document.getElementById('btnAnalisarTjrj');
    if (!textarea.value.trim()) { alert('Cole o texto do TJRJ antes de analisar.'); return; }

    btn.disabled = true;
    status.textContent = 'Analisando texto...';

    var fd = new FormData();
    fd.append('action', 'preview_tjrj');
    fd.append('csrf_token', SUSPENSOES_CSRF);
    fd.append('texto_pdf', textarea.value);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', SUSPENSOES_URL, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onload = function() {
        btn.disabled = false;
        status.textContent = '';
        var r;
        try { r = JSON.parse(xhr.responseText); } catch (e) { alert('Resposta inválida do servidor.'); return; }
        if (!r.ok) { alert('Erro: ' + (r.erro || 'desconhecido')); return; }
        suspensoesRenderPreview(r.itens || []);
    };
    xhr.onerror = function() { btn.disabled = false; status.textContent = ''; alert('Erro de rede.'); };
    xhr.send(fd);
}

function suspensoesRenderPreview(itens) {
    var tbody = document.getElementById('previewLinhas');
    var resumo = document.getElementById('previewResumo');
    var preview = document.getElementById('previewSuspensoes');
    if (!itens.length) {
        tbody.innerHTML = '<tr><td colspan="8" style="padding:1rem;text-align:center;color:#94a3b8;">Nenhuma suspensão detectada no texto.</td></tr>';
        resumo.textContent = '';
        preview.style.display = 'block';
        return;
    }
    var novas = 0, dups = 0;
    var html = '';
    for (var i = 0; i < itens.length; i++) {
        var it = itens[i];
        if (it.ja_existe) dups++; else novas++;
        var rowBg = it.ja_existe ? 'background:#f1f5f9;color:#94a3b8;' : '';
        var checked = it.ja_existe ? '' : 'checked';
        var status = it.ja_existe
            ? '<span style="background:#e2e8f0;color:#475569;padding:2px 8px;border-radius:4px;font-size:.7rem;font-weight:700;">JÁ CADASTRADO</span>'
            : '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;font-size:.7rem;font-weight:700;">NOVO</span>';

        html += '<tr style="' + rowBg + '" data-idx="' + i + '">';
        html += '<td style="padding:5px 8px;text-align:center;border-bottom:1px solid #f3f4f6;"><input type="checkbox" class="prev-chk" ' + checked + ' data-idx="' + i + '"></td>';
        html += '<td style="padding:5px 8px;border-bottom:1px solid #f3f4f6;font-family:monospace;">' + suspensoesFmtData(it.data_inicio) + '</td>';
        html += '<td style="padding:5px 8px;border-bottom:1px solid #f3f4f6;font-family:monospace;">' + suspensoesFmtData(it.data_fim) + '</td>';
        html += '<td style="padding:5px 8px;border-bottom:1px solid #f3f4f6;">' + suspensoesEsc(it.tipo) + '</td>';
        html += '<td style="padding:5px 8px;border-bottom:1px solid #f3f4f6;">' + suspensoesEsc(it.abrangencia) + '</td>';
        html += '<td style="padding:5px 8px;border-bottom:1px solid #f3f4f6;">' + suspensoesEsc(it.comarca || '—') + '</td>';
        html += '<td style="padding:5px 8px;border-bottom:1px solid #f3f4f6;max-width:340px;">' + suspensoesEsc(it.motivo) + '</td>';
        html += '<td style="padding:5px 8px;border-bottom:1px solid #f3f4f6;text-align:center;">' + status + '</td>';
        html += '</tr>';
    }
    tbody.innerHTML = html;
    resumo.textContent = (novas + dups) + ' detectada(s): ' + novas + ' nova(s), ' + dups + ' já cadastrada(s).';
    preview.style.display = 'block';
    window._suspensoesItens = itens;
    preview.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function suspensoesToggleTodos(checkbox) {
    var chks = document.querySelectorAll('.prev-chk');
    for (var i = 0; i < chks.length; i++) chks[i].checked = checkbox.checked;
}

function suspensoesSalvarSelecionadas() {
    var itens = window._suspensoesItens || [];
    var marcados = [];
    var chks = document.querySelectorAll('.prev-chk');
    for (var i = 0; i < chks.length; i++) {
        if (chks[i].checked) {
            var idx = parseInt(chks[i].getAttribute('data-idx'), 10);
            if (!isNaN(idx) && itens[idx]) marcados.push(itens[idx]);
        }
    }
    if (!marcados.length) { alert('Marque pelo menos uma suspensão pra salvar.'); return; }
    if (!confirm('Salvar ' + marcados.length + ' suspensão(ões) no banco?')) return;

    var btn = document.getElementById('btnSalvarSelecionadas');
    btn.disabled = true;
    btn.textContent = 'Salvando...';

    var fd = new FormData();
    fd.append('action', 'salvar_suspensoes_selecionadas');
    fd.append('csrf_token', SUSPENSOES_CSRF);
    fd.append('items', JSON.stringify(marcados));

    var xhr = new XMLHttpRequest();
    xhr.open('POST', SUSPENSOES_URL, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onload = function() {
        btn.disabled = false;
        btn.textContent = '✅ Salvar selecionadas';
        var r;
        try { r = JSON.parse(xhr.responseText); } catch (e) { alert('Resposta inválida.'); return; }
        if (!r.ok) { alert('Erro: ' + (r.erro || 'desconhecido')); return; }
        alert('✓ ' + r.importados + ' suspensão(ões) importada(s).' +
              (r.duplicatas > 0 ? '\n' + r.duplicatas + ' já existiam.' : '') +
              (r.erros > 0 ? '\n' + r.erros + ' com erro.' : ''));
        window.location.reload();
    };
    xhr.onerror = function() { btn.disabled = false; btn.textContent = '✅ Salvar selecionadas'; alert('Erro de rede.'); };
    xhr.send(fd);
}

function suspensoesEsc(s) { if (!s) return ''; var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
function suspensoesFmtData(s) { if (!s || s.length < 10) return s || ''; var p = s.substr(0,10).split('-'); return p[2] + '/' + p[1] + '/' + p[0]; }
</script>
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
