<?php
/**
 * Conversas Novas (WhatsApp) — quantas conversas foram ABERTAS pela 1ª vez
 * por período, separadas por canal (21 Comercial / 24 CX/Operacional).
 * Base: zapi_conversas.created_at (data de cadastro do registro).
 * Granularidade: diário / semanal / mensal, com intervalo configurável.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('gestao')) { flash_set('error', 'Acesso restrito.'); redirect(url('modules/whatsapp/')); }

$pdo = db();
$pageTitle = 'Relatórios - CAC';

$gran = $_GET['gran'] ?? 'dia';
if (!in_array($gran, array('dia', 'semana', 'mes'), true)) $gran = 'dia';
$incluirGrupos = !empty($_GET['grupos']);
// Default: conta só conversas iniciadas pelo CLIENTE (1ª msg recebida) = leads reais.
// ?todas=1 inclui também as que NÓS iniciamos (follow-up, clientes existentes…).
$soLeads = !isset($_GET['todas']);

// ── Investimento em anúncios (pra CAC) — self-heal + salvar ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS marketing_investimento (
        ano_mes VARCHAR(7) NOT NULL, canal VARCHAR(5) NOT NULL,
        valor_cents INT NOT NULL DEFAULT 0, updated_by INT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (ano_mes, canal)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_invest') {
    validate_csrf();
    $ym = trim($_POST['inv_mes'] ?? '');
    if (preg_match('/^\d{4}-\d{2}$/', $ym)) {
        // CAC só no canal 21 (o 24 recebe leads por erro de campanha — fora do CAC por ora)
        $up = $pdo->prepare("INSERT INTO marketing_investimento (ano_mes, canal, valor_cents, updated_by)
                             VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE valor_cents=VALUES(valor_cents), updated_by=VALUES(updated_by)");
        $cents = (int) round(((float)($_POST['inv_21'] ?? 0)) * 100);
        $up->execute(array($ym, '21', max(0, $cents), current_user_id()));
        audit_log('marketing_invest_salvar', 'configuracoes', 0, $ym);
        flash_set('success', 'Investimento de ' . $ym . ' salvo.');
    }
    redirect(module_url('whatsapp', 'conversas_novas.php') . '?gran=' . urlencode($gran) . ($incluirGrupos ? '&grupos=1' : '') . '&invmes=' . urlencode($ym) . '#invest');
}

// ── Intervalo ────────────────────────────────────────────
$hojeD = new DateTime('today');
$fim   = clone $hojeD;
if ($gran === 'dia')        $iniDefault = (clone $hojeD)->modify('-29 days');
elseif ($gran === 'semana') $iniDefault = (clone $hojeD)->modify('-11 weeks');
else                        $iniDefault = (clone $hojeD)->modify('-11 months');

$de  = trim($_GET['de'] ?? '');
$ate = trim($_GET['ate'] ?? '');
$ini = ($de && preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) ? new DateTime($de) : $iniDefault;
if ($ate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) $fim = new DateTime($ate);
if ($ini > $fim) { $tmp = $ini; $ini = $fim; $fim = $tmp; }

// Normaliza o início conforme a granularidade (semana=segunda, mês=dia 1)
if ($gran === 'semana') $ini->modify('-' . ((int)$ini->format('N') - 1) . ' days');
if ($gran === 'mes')    $ini->modify('first day of this month');

$iniStr = $ini->format('Y-m-d 00:00:00');
$fimStr = (clone $fim)->modify('+1 day')->format('Y-m-d 00:00:00'); // exclusivo

// Expressão da chave do período no SQL
if ($gran === 'dia')         $keyExpr = "DATE(created_at)";
elseif ($gran === 'semana')  $keyExpr = "DATE(DATE_SUB(created_at, INTERVAL WEEKDAY(created_at) DAY))"; // segunda da semana
else                         $keyExpr = "DATE_FORMAT(created_at, '%Y-%m')";

$where  = "created_at >= ? AND created_at < ?";
$params = array($iniStr, $fimStr);
if (!$incluirGrupos) $where .= " AND COALESCE(eh_grupo,0) = 0";
// Só leads = só conversas cuja 1ª mensagem foi RECEBIDA (o contato falou primeiro)
if ($soLeads) $where .= " AND (SELECT mm.direcao FROM zapi_mensagens mm WHERE mm.conversa_id = zapi_conversas.id ORDER BY mm.id ASC LIMIT 1) = 'recebida'";

$rows = array();
try {
    $st = $pdo->prepare("SELECT $keyExpr k, canal, COUNT(*) c FROM zapi_conversas WHERE $where GROUP BY k, canal ORDER BY k");
    $st->execute($params);
    foreach ($st->fetchAll() as $r) { $rows[$r['k']][$r['canal']] = (int)$r['c']; }
} catch (Exception $e) {}

// ── Buckets ordenados (preenche zeros nos períodos sem conversa) ──
$mesesAbr = array(1=>'jan',2=>'fev',3=>'mar',4=>'abr',5=>'mai',6=>'jun',7=>'jul',8=>'ago',9=>'set',10=>'out',11=>'nov',12=>'dez');
$diasSemAbr = array(1=>'Seg',2=>'Ter',3=>'Qua',4=>'Qui',5=>'Sex',6=>'Sáb',7=>'Dom'); // N: 1=segunda
$buckets = array();      // rótulo curto (eixo do gráfico)
$bucketsFull = array();  // rótulo completo (tabela) — na semana mostra o intervalo de datas
$cur = clone $ini;
if ($gran === 'dia') {
    while ($cur <= $fim) { $k = $cur->format('Y-m-d'); $buckets[$k] = $cur->format('d/m'); $bucketsFull[$k] = $cur->format('d/m/Y') . ' · ' . $diasSemAbr[(int)$cur->format('N')]; $cur->modify('+1 day'); }
} elseif ($gran === 'semana') {
    while ($cur <= $fim) { $k = $cur->format('Y-m-d'); $fimSem = (clone $cur)->modify('+6 days'); $buckets[$k] = $cur->format('d/m'); $bucketsFull[$k] = $cur->format('d/m') . ' a ' . $fimSem->format('d/m'); $cur->modify('+7 days'); }
} else {
    while ($cur <= $fim) { $k = $cur->format('Y-m'); $lbl = $mesesAbr[(int)$cur->format('n')] . '/' . $cur->format('y'); $buckets[$k] = $lbl; $bucketsFull[$k] = $lbl; $cur->modify('first day of next month'); }
}

// ── Investimento (CAC): carrega por mês e rateia por dia em cada bucket ──
$investMes = array(); // 'YYYY-MM' => ['21'=>cents,'24'=>cents]
try {
    $im = $pdo->prepare("SELECT ano_mes, canal, valor_cents FROM marketing_investimento WHERE ano_mes BETWEEN ? AND ?");
    $im->execute(array($ini->format('Y-m'), $fim->format('Y-m')));
    foreach ($im->fetchAll() as $r) $investMes[$r['ano_mes']][$r['canal']] = (int)$r['valor_cents'];
} catch (Exception $e) {}

$bucketInvest = array(); // key => ['21'=>float cents, '24'=>float cents]
$cur3 = clone $ini;
while ($cur3 <= $fim) {
    $ym3 = $cur3->format('Y-m');
    $dim = (int)$cur3->format('t'); // dias no mês
    if ($gran === 'dia')        $bk = $cur3->format('Y-m-d');
    elseif ($gran === 'semana') { $mon = clone $cur3; $mon->modify('-' . ((int)$cur3->format('N') - 1) . ' days'); $bk = $mon->format('Y-m-d'); }
    else                        $bk = $ym3;
    foreach (array('21','24') as $cc) {
        $mInv = isset($investMes[$ym3][$cc]) ? $investMes[$ym3][$cc] : 0;
        if (!isset($bucketInvest[$bk][$cc])) $bucketInvest[$bk][$cc] = 0;
        $bucketInvest[$bk][$cc] += ($dim > 0 ? $mInv / $dim : 0);
    }
    $cur3->modify('+1 day');
}

$labels = array(); $labelsTabela = array(); $keysArr = array(); $d21 = array(); $d24 = array(); $inv21Arr = array(); $inv24Arr = array(); $cacArr = array();
$tot21 = 0; $tot24 = 0; $totInv21 = 0; $totInv24 = 0; $best = array('lbl' => '—', 'v' => 0);
foreach ($buckets as $k => $lbl) {
    $keysArr[] = $k;
    $v21 = isset($rows[$k]['21']) ? $rows[$k]['21'] : 0;
    $v24 = isset($rows[$k]['24']) ? $rows[$k]['24'] : 0;
    $i21 = isset($bucketInvest[$k]['21']) ? $bucketInvest[$k]['21'] : 0;
    $i24 = isset($bucketInvest[$k]['24']) ? $bucketInvest[$k]['24'] : 0;
    $labels[] = $lbl; $labelsTabela[] = isset($bucketsFull[$k]) ? $bucketsFull[$k] : $lbl; $d21[] = $v21; $d24[] = $v24; $inv21Arr[] = $i21; $inv24Arr[] = $i24;
    $cacArr[] = ($v21 > 0 && $i21 > 0) ? round($i21 / $v21 / 100, 2) : null; // CAC 21 em R$ (null = sem dado)
    $tot21 += $v21; $tot24 += $v24; $totInv21 += $i21; $totInv24 += $i24;
    if (($v21 + $v24) > $best['v']) $best = array('lbl' => $lbl, 'v' => $v21 + $v24);
}
$totGeral  = $tot21 + $tot24;
$nPeriodos = max(1, count($buckets));
$media     = round($totGeral / $nPeriodos, 1);
$granLabel = array('dia' => 'dia', 'semana' => 'semana', 'mes' => 'mês');

// CAC = investimento ÷ conversas (em centavos por conversa)
$cnFmt = function ($cents) { return 'R$ ' . number_format($cents / 100, 2, ',', '.'); };
$cac21 = $tot21 > 0 ? $totInv21 / $tot21 : null;
$cac24 = $tot24 > 0 ? $totInv24 / $tot24 : null;
$totInvGeral = $totInv21 + $totInv24;
$cacGeral = $totGeral > 0 && $totInvGeral > 0 ? $totInvGeral / $totGeral : null;

// Editor de investimento: mês escolhido (?invmes) ou o corrente; + lista de TODOS os lançamentos
$invEditMes = (isset($_GET['invmes']) && preg_match('/^\d{4}-\d{2}$/', $_GET['invmes'])) ? $_GET['invmes'] : date('Y-m');
$invEdit = array('21' => '', '24' => '');
$invAll = array();
try {
    $ie = $pdo->prepare("SELECT canal, valor_cents FROM marketing_investimento WHERE ano_mes = ?");
    $ie->execute(array($invEditMes));
    foreach ($ie->fetchAll() as $r) $invEdit[$r['canal']] = number_format($r['valor_cents'] / 100, 2, '.', '');

    foreach ($pdo->query("SELECT ano_mes, canal, valor_cents FROM marketing_investimento ORDER BY ano_mes DESC")->fetchAll() as $r) {
        $invAll[$r['ano_mes']][$r['canal']] = (int)$r['valor_cents'];
    }
} catch (Exception $e) {}

// ── Detalhe de um dia: leads, 1ª msg, resposta + tempo, follow-up, conversão ──
if (!function_exists('cnDiff')) {
function cnDiff($a, $b) {
    if (!$a || !$b) return null;
    $s = strtotime($b) - strtotime($a);
    if ($s < 0) return null;
    if ($s < 60) return $s . 's';
    $m = floor($s / 60);
    if ($m < 60) return $m . ' min';
    $h = floor($m / 60); $mm = $m % 60;
    if ($h < 24) return $h . 'h' . ($mm ? ' ' . $mm . 'min' : '');
    $d = floor($h / 24); $hh = $h % 24;
    return $d . 'd' . ($hh ? ' ' . $hh . 'h' : '');
}
}
$diaDetalhe = (isset($_GET['dia']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['dia'])) ? $_GET['dia'] : null;
$leadsDia = array();
if ($diaDetalhe) {
    try {
        $stD = $pdo->prepare("SELECT id, canal, telefone, client_id, lead_id, nome_contato FROM zapi_conversas WHERE DATE(created_at)=? AND COALESCE(eh_grupo,0)=0 ORDER BY created_at ASC");
        $stD->execute(array($diaDetalhe));
        foreach ($stD->fetchAll() as $c) {
            $cid = (int)$c['id'];
            $stF = $pdo->prepare("SELECT direcao, created_at FROM zapi_mensagens WHERE conversa_id=? ORDER BY id ASC LIMIT 1");
            $stF->execute(array($cid)); $fm = $stF->fetch();
            $firstDir = $fm ? $fm['direcao'] : null;
            $firstAt  = $fm ? $fm['created_at'] : null;
            if ($soLeads && $firstDir !== 'recebida') continue; // só leads = 1ª msg do cliente
            // 1ª resposta nossa após a 1ª mensagem do lead
            $resp = null;
            if ($firstAt) { $st2 = $pdo->prepare("SELECT MIN(created_at) FROM zapi_mensagens WHERE conversa_id=? AND direcao='enviada' AND created_at >= ?"); $st2->execute(array($cid, $firstAt)); $resp = $st2->fetchColumn() ?: null; }
            // follow-up = equipe enviou em 2+ dias distintos
            $st3 = $pdo->prepare("SELECT COUNT(DISTINCT DATE(created_at)) FROM zapi_mensagens WHERE conversa_id=? AND direcao='enviada'");
            $st3->execute(array($cid)); $diasEnv = (int)$st3->fetchColumn();
            // conversão = lead vinculado convertido (por lead_id ou telefone)
            $conv = false;
            try {
                if (!empty($c['lead_id'])) { $sc = $pdo->prepare("SELECT converted_at, stage FROM pipeline_leads WHERE id=?"); $sc->execute(array($c['lead_id'])); $lr = $sc->fetch(); if ($lr && (!empty($lr['converted_at']) || in_array($lr['stage'], array('contrato_assinado','finalizado'), true))) $conv = true; }
                if (!$conv) { $tel8 = substr(preg_replace('/\D/', '', $c['telefone']), -8); if (strlen($tel8) >= 8) { $sc = $pdo->prepare("SELECT 1 FROM pipeline_leads WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone,'(',''),')',''),'-',''),' ','') LIKE ? AND (converted_at IS NOT NULL OR stage IN ('contrato_assinado','finalizado')) LIMIT 1"); $sc->execute(array('%' . $tel8)); if ($sc->fetchColumn()) $conv = true; } }
            } catch (Exception $e) {}
            $leadsDia[] = array(
                'id' => $cid, 'canal' => $c['canal'], 'nome' => ($c['nome_contato'] ?: $c['telefone']), 'tel' => $c['telefone'],
                'firstAt' => $firstAt, 'resp' => $resp, 'diff' => cnDiff($firstAt, $resp),
                'followup' => ($diasEnv > 1), 'conv' => $conv,
            );
        }
    } catch (Exception $e) {}
}

// ── Exportar CSV (respeita os filtros atuais) ──
if (($_GET['export'] ?? '') === 'csv') {
    $fn = $diaDetalhe ? ('leads_' . $diaDetalhe) : ('conversas_novas_' . $gran . '_' . $ini->format('Ymd') . '-' . $fim->format('Ymd'));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fn . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM utf-8 (Excel abre acentos certo)
    $out = fopen('php://output', 'w');
    if ($diaDetalhe) {
        fputcsv($out, array('Canal', 'Lead', 'Telefone', '1a msg (lead)', 'Nossa resposta', 'Tempo resposta', 'Follow-up', 'Conversao'), ';');
        foreach ($leadsDia as $L) {
            fputcsv($out, array(
                $L['canal'], $L['nome'], $L['tel'],
                $L['firstAt'] ? date('d/m/Y H:i', strtotime($L['firstAt'])) : '',
                $L['resp'] ? date('d/m/Y H:i', strtotime($L['resp'])) : 'sem resposta',
                $L['diff'] ?: '', $L['followup'] ? 'Sim' : 'Nao', $L['conv'] ? 'Sim' : 'Nao'
            ), ';');
        }
    } else {
        fputcsv($out, array('Periodo', 'Comercial (21)', 'CX/Operac (24)', 'Total', 'Investido 21 (R$)', 'CAC 21 (R$)'), ';');
        for ($i = 0; $i < count($labels); $i++) {
            $iv = $inv21Arr[$i];
            $cacv = ($d21[$i] > 0 && $iv > 0) ? $iv / $d21[$i] : null;
            fputcsv($out, array(
                $labelsTabela[$i], $d21[$i], $d24[$i], $d21[$i] + $d24[$i],
                $iv > 0 ? number_format($iv / 100, 2, ',', '') : '',
                $cacv !== null ? number_format($cacv / 100, 2, ',', '') : ''
            ), ';');
        }
        fputcsv($out, array('TOTAL', $tot21, $tot24, $totGeral,
            $totInv21 > 0 ? number_format($totInv21 / 100, 2, ',', '') : '',
            $cac21 !== null ? number_format($cac21 / 100, 2, ',', '') : ''), ';');
    }
    fclose($out);
    exit;
}

require_once APP_ROOT . '/templates/layout_start.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
.cn-head{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-bottom:1rem;}
.cn-tabs{display:flex;gap:.3rem;}
.cn-tab{padding:.45rem 1rem;border-radius:8px;border:1.5px solid var(--border);background:#fff;text-decoration:none;color:#052228;font-weight:700;font-size:.85rem;}
.cn-tab.on{background:#052228;color:#fff;border-color:#052228;}
.cn-filtros{background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:.7rem 1rem;margin-bottom:1rem;display:flex;gap:.8rem;align-items:flex-end;flex-wrap:wrap;}
.cn-filtros label{display:flex;flex-direction:column;gap:2px;font-size:.7rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;}
.cn-filtros input[type=date]{padding:5px 8px;border:1.5px solid var(--border);border-radius:6px;font-size:.82rem;}
.cn-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.8rem;margin-bottom:1rem;}
.cn-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:1rem;text-align:center;}
.cn-card .num{font-size:1.8rem;font-weight:900;color:var(--petrol-900);line-height:1;}
.cn-card .lbl{font-size:.72rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;margin-top:.3rem;font-weight:700;}
.cn-card.c21{border-top:4px solid #0d9488;}
.cn-card.c24{border-top:4px solid #B87333;}
.cn-card.cac{border-top:4px solid #6366f1;}
.cn-card .num.money{font-size:1.3rem;}
.cn-invest{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:.2rem 1rem;margin-bottom:1rem;}
.cn-invest summary{cursor:pointer;font-weight:700;color:var(--petrol-900);font-size:.86rem;padding:.6rem 0;}
.cn-invest form{display:flex;gap:.8rem;align-items:flex-end;flex-wrap:wrap;padding:.2rem 0 .7rem;}
.cn-invest label{display:flex;flex-direction:column;gap:2px;font-size:.7rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;}
.cn-invest input{padding:5px 8px;border:1.5px solid var(--border);border-radius:6px;font-size:.85rem;}
.cn-chartbox{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:1rem 1.2rem;margin-bottom:1rem;}
.cn-table{width:100%;border-collapse:collapse;font-size:.82rem;background:var(--bg-card);border-radius:10px;overflow:hidden;}
.cn-table th{background:var(--petrol-900);color:#fff;padding:.5rem .7rem;text-align:right;font-size:.68rem;text-transform:uppercase;}
.cn-table th:first-child{text-align:left;}
.cn-table td{padding:.45rem .7rem;border-bottom:1px solid var(--border);text-align:right;}
.cn-table td:first-child{text-align:left;font-weight:600;}
.cn-table tr:hover td{background:rgba(184,115,51,.06);}
.cn-table .tot td{background:#052228;color:#fff;font-weight:800;border:none;}
@media print {
    .cn-head, .cn-filtros, .cn-invest, .btn, a.btn { display:none !important; }
    .cn-chartbox, .cn-table { break-inside: avoid; }
    .cn-cards { grid-template-columns: repeat(4, 1fr); }
}
</style>

<a href="<?= module_url('whatsapp') ?>" class="btn btn-outline btn-sm mb-2">&larr; Voltar ao WhatsApp</a>
<h1 style="margin:.2rem 0 1rem;">📈 Relatórios - CAC</h1>
<p class="text-sm text-muted" style="margin-top:-.6rem;margin-bottom:1rem;">
    <?= $soLeads ? '<strong>Leads novos</strong> = conversas iniciadas pelo cliente (1ª mensagem recebida).' : '<strong>Todas</strong> as conversas novas (inclui as que vocês iniciaram).' ?>
    Por canal, granularidade <strong><?= $granLabel[$gran] ?></strong>.
</p>

<div class="cn-head">
    <div class="cn-tabs">
        <?php foreach (array('dia'=>'Diário','semana'=>'Semanal','mes'=>'Mensal') as $g=>$lbl):
            $qs = $_GET; $qs['gran']=$g; unset($qs['de']); unset($qs['ate']); // troca de granularidade reseta o range pro default
        ?>
            <a href="?<?= http_build_query($qs) ?>" class="cn-tab <?= $gran===$g?'on':'' ?>"><?= $lbl ?></a>
        <?php endforeach; ?>
    </div>
    <div style="margin-left:auto;display:flex;gap:.4rem;">
        <?php $qsCsv = $_GET; unset($qsCsv['dia']); $qsCsv['export'] = 'csv'; ?>
        <a href="?<?= http_build_query($qsCsv) ?>" class="btn btn-outline btn-sm" title="Baixar a tabela em CSV (abre no Excel)">⬇️ CSV</a>
        <button type="button" onclick="window.print()" class="btn btn-outline btn-sm">🖨️ Imprimir / PDF</button>
    </div>
</div>

<form method="GET" class="cn-filtros">
    <input type="hidden" name="gran" value="<?= e($gran) ?>">
    <label>De <input type="date" name="de" value="<?= e($ini->format('Y-m-d')) ?>"></label>
    <label>Até <input type="date" name="ate" value="<?= e($fim->format('Y-m-d')) ?>"></label>
    <label style="flex-direction:row;align-items:center;gap:6px;text-transform:none;font-size:.8rem;color:var(--text);" title="Por padrão conta só quem iniciou o contato (lead real). Marque para incluir também as conversas que vocês iniciaram (follow-up, clientes existentes).">
        <input type="checkbox" name="todas" value="1" <?= !$soLeads?'checked':'' ?>> Incluir conversas que nós iniciamos
    </label>
    <label style="flex-direction:row;align-items:center;gap:6px;text-transform:none;font-size:.8rem;color:var(--text);">
        <input type="checkbox" name="grupos" value="1" <?= $incluirGrupos?'checked':'' ?>> Incluir grupos
    </label>
    <button type="submit" class="btn btn-primary btn-sm">🔍 Aplicar</button>
    <a href="?gran=<?= e($gran) ?>" class="btn btn-outline btn-sm">Limpar</a>
</form>

<div class="cn-cards">
    <div class="cn-card"><div class="num"><?= $totGeral ?></div><div class="lbl">Total no período</div></div>
    <div class="cn-card c21"><div class="num"><?= $tot21 ?></div><div class="lbl">💬 Comercial (21)</div></div>
    <div class="cn-card c24"><div class="num"><?= $tot24 ?></div><div class="lbl">💬 CX/Operac. (24)</div></div>
    <div class="cn-card"><div class="num"><?= $media ?></div><div class="lbl">Média / <?= $granLabel[$gran] ?></div></div>
    <div class="cn-card"><div class="num"><?= (int)$best['v'] ?></div><div class="lbl">Pico (<?= e($best['lbl']) ?>)</div></div>
    <div class="cn-card"><div class="num money"><?= $totInv21 > 0 ? $cnFmt($totInv21) : '—' ?></div><div class="lbl">💰 Investido (21)</div></div>
    <div class="cn-card cac"><div class="num money"><?= $cac21 !== null ? $cnFmt($cac21) : '—' ?></div><div class="lbl">CAC — Comercial (21)</div></div>
</div>

<details id="invest" class="cn-invest" <?= isset($_GET['invmes']) ? 'open' : '' ?>>
    <summary>💰 Investimento em anúncios (lançar / editar / ver histórico)</summary>
    <form method="POST">
        <?= csrf_input() ?>
        <input type="hidden" name="acao" value="salvar_invest">
        <input type="hidden" name="gran" value="<?= e($gran) ?>">
        <label>Mês <input type="month" name="inv_mes" value="<?= e($invEditMes) ?>" required></label>
        <label>Investimento em anúncios — Comercial (21) — R$ <input type="number" step="0.01" min="0" name="inv_21" value="<?= e($invEdit['21']) ?>" placeholder="0,00" style="width:140px;"></label>
        <button type="submit" class="btn btn-primary btn-sm">Salvar</button>
    </form>
    <p class="text-sm text-muted" style="margin:0 0 .6rem;">Lance o gasto com anúncios do mês no <strong>Comercial (21)</strong>. <strong>CAC = investimento ÷ conversas novas do 21.</strong> O canal 24 não entra no CAC por enquanto (recebe leads por erro de campanha). Para comparar mês a mês e decidir aumentar/diminuir o gasto, use a aba <strong>Mensal</strong>. Nas visões diária/semanal o investimento é rateado por dia.</p>

    <?php if (!empty($invAll)): ?>
    <div style="overflow-x:auto;margin-bottom:.7rem;">
        <div class="text-sm" style="font-weight:700;color:var(--petrol-900);margin-bottom:.3rem;">Lançamentos registrados</div>
        <table class="cn-table" style="font-size:.78rem;">
            <thead><tr><th>Mês</th><th>Investido — Comercial (21)</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($invAll as $ym => $vals):
                    $i21 = isset($vals['21']) ? $vals['21'] : 0;
                    if ($i21 <= 0) continue; // só mostra meses com investimento no 21
                    $yy = explode('-', $ym);
                    $mlbl = $mesesAbr[(int)$yy[1]] . '/' . $yy[0];
                    $qsE = $_GET; $qsE['invmes'] = $ym;
                ?>
                <tr>
                    <td><?= e($mlbl) ?></td>
                    <td><strong><?= $cnFmt($i21) ?></strong></td>
                    <td style="text-align:center;"><a href="?<?= http_build_query($qsE) ?>#invest" style="font-size:.72rem;font-weight:700;color:#6366f1;text-decoration:none;">✏️ editar</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</details>

<div class="cn-chartbox">
    <div class="text-sm" style="font-weight:700;color:var(--petrol-900);margin-bottom:.4rem;">Conversas novas por período</div>
    <div style="position:relative;height:260px;"><canvas id="cnChart"></canvas></div>
</div>

<?php if ($totInv21 > 0): ?>
<div class="cn-chartbox">
    <div class="text-sm" style="font-weight:700;color:var(--petrol-900);margin-bottom:.4rem;">📉 CAC — Comercial (21) por período (R$ por lead)</div>
    <div style="position:relative;height:200px;"><canvas id="cnCac"></canvas></div>
</div>
<?php endif; ?>

<div style="overflow-x:auto;">
<table class="cn-table">
    <thead><tr><th>Período</th><th>Comercial (21)</th><th>CX/Operac. (24)</th><th>Total</th><th>Investido (21)</th><th>CAC (21)</th></tr></thead>
    <tbody>
        <?php for ($i = count($labels)-1; $i >= 0; $i--): // mais recente primeiro
            $tt = $d21[$i] + $d24[$i];
            $iv = $inv21Arr[$i];                                 // CAC só do canal 21
            $cac = ($d21[$i] > 0 && $iv > 0) ? $iv / $d21[$i] : null;
        ?>
            <tr><td><?php if ($gran === 'dia'): $qsD = $_GET; $qsD['dia'] = $keysArr[$i]; ?><a href="?<?= http_build_query($qsD) ?>#leads" title="Ver os leads deste dia" style="color:#052228;font-weight:700;text-decoration:none;border-bottom:1px dashed #B87333;"><?= e($labelsTabela[$i]) ?></a><?php else: ?><?= e($labelsTabela[$i]) ?><?php endif; ?></td><td><?= $d21[$i] ?></td><td><?= $d24[$i] ?></td><td><strong><?= $tt ?></strong></td><td><?= $iv > 0 ? $cnFmt($iv) : '—' ?></td><td><?= $cac !== null ? $cnFmt($cac) : '—' ?></td></tr>
        <?php endfor; ?>
        <tr class="tot"><td>TOTAL</td><td><?= $tot21 ?></td><td><?= $tot24 ?></td><td><?= $totGeral ?></td><td><?= $totInv21 > 0 ? $cnFmt($totInv21) : '—' ?></td><td><?= $cac21 !== null ? $cnFmt($cac21) : '—' ?></td></tr>
    </tbody>
</table>
</div>
<?php if ($gran === 'dia'): ?><p class="text-sm text-muted" style="margin:.4rem 0 0;">💡 Clique na data (coluna Período) para ver os leads daquele dia, com horário da mensagem, tempo de resposta, follow-up e conversão.</p><?php endif; ?>

<?php if ($diaDetalhe): $dd = explode('-', $diaDetalhe); ?>
<div id="leads" class="cn-chartbox" style="margin-top:1rem;border:2px solid #6366f1;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;margin-bottom:.6rem;">
        <div style="font-weight:800;color:var(--petrol-900);font-size:1rem;">🔎 Leads de <?= e($dd[2] . '/' . $dd[1] . '/' . $dd[0]) ?> <span class="text-sm text-muted" style="font-weight:600;">(<?= count($leadsDia) ?>)</span></div>
        <div style="display:flex;gap:.4rem;">
            <?php $qsL = $_GET; $qsL['export'] = 'csv'; ?>
            <a href="?<?= http_build_query($qsL) ?>" class="btn btn-outline btn-sm" title="Baixar os leads deste dia em CSV">⬇️ CSV</a>
            <?php $qsF = $_GET; unset($qsF['dia']); ?>
            <a href="?<?= http_build_query($qsF) ?>" class="btn btn-outline btn-sm">✖ Fechar</a>
        </div>
    </div>
    <?php if (empty($leadsDia)): ?>
        <p class="text-muted text-sm">Nenhum lead nesse dia (com o filtro atual).</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="cn-table" style="font-size:.8rem;">
        <thead><tr><th>Canal</th><th>Lead</th><th>1ª msg (lead)</th><th>Nossa resposta</th><th>Tempo resp.</th><th>Follow-up</th><th>Conversão</th></tr></thead>
        <tbody>
            <?php foreach ($leadsDia as $L): ?>
            <tr>
                <td><?= e($L['canal']) ?></td>
                <td style="text-align:left;"><a href="<?= url('modules/whatsapp/?canal=' . urlencode($L['canal']) . '&abrir=' . (int)$L['id']) ?>" target="_blank" rel="noopener" title="Abrir conversa no Hub" style="color:#052228;font-weight:600;text-decoration:none;border-bottom:1px dashed #0d9488;"><?= e($L['nome']) ?></a></td>
                <td><?= $L['firstAt'] ? date('H:i', strtotime($L['firstAt'])) : '—' ?></td>
                <td><?= $L['resp'] ? date('d/m H:i', strtotime($L['resp'])) : '<span style="color:#dc2626;font-weight:700;">sem resposta</span>' ?></td>
                <td><?= $L['diff'] ? e($L['diff']) : '—' ?></td>
                <td style="text-align:center;"><?= $L['followup'] ? '✅' : '—' ?></td>
                <td style="text-align:center;"><?= $L['conv'] ? '<span style="color:#15803d;font-weight:800;">✓ sim</span>' : '—' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="text-sm text-muted" style="margin-top:.5rem;"><strong>1ª msg</strong> = quando o lead mandou a 1ª mensagem · <strong>Resposta</strong> = 1ª resposta de vocês · <strong>Tempo resp.</strong> = quanto demorou · <strong>Follow-up</strong> = vocês voltaram a falar em outro dia · <strong>Conversão</strong> = lead com contrato assinado.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
new Chart(document.getElementById('cnChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($labels) ?>,
        datasets: [
            { label: 'Comercial (21)', data: <?= json_encode($d21) ?>, backgroundColor: '#0d9488', borderRadius: 4 },
            { label: 'CX/Operac. (24)', data: <?= json_encode($d24) ?>, backgroundColor: '#B87333', borderRadius: 4 }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { position: 'top' },
            tooltip: { mode: 'index', intersect: false,
                callbacks: { footer: function(items){ var t=0; items.forEach(function(i){t+=i.parsed.y;}); return 'Total: '+t; } } }
        },
        scales: { x: { stacked: false, grid:{display:false} }, y: { beginAtZero: true, ticks: { precision: 0 } } }
    }
});
<?php if ($totInv21 > 0): ?>
new Chart(document.getElementById('cnCac').getContext('2d'), {
    type: 'line',
    data: { labels: <?= json_encode($labels) ?>, datasets: [{
        label: 'CAC (21)', data: <?= json_encode($cacArr) ?>,
        borderColor: '#6366f1', backgroundColor: 'rgba(99,102,241,.15)',
        spanGaps: true, tension: .3, fill: true, pointRadius: 3, pointBackgroundColor: '#6366f1'
    }] },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false },
            tooltip: { callbacks: { label: function(c){ return c.parsed.y != null ? 'CAC: R$ ' + Number(c.parsed.y).toLocaleString('pt-BR', {minimumFractionDigits:2}) : 'sem dado'; } } } },
        scales: { x: { grid:{display:false} }, y: { beginAtZero: true, ticks: { callback: function(v){ return 'R$ ' + v; } } } }
    }
});
<?php endif; ?>
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
