<?php
/**
 * Exporta CSV das audiências de uma audiencista (filtro por mês/ano opcional).
 *
 * Uso: /modules/audiencistas/exportar_csv.php?id=AUDID[&mes=N&ano=AAAA]
 *
 * Pra envio contábil ou conferência manual do acerto financeiro.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('audiencistas');

$pdo = db();
$audId = (int)($_GET['id'] ?? 0);
if (!$audId) { http_response_code(404); die('Audiencista não informada.'); }

$st = $pdo->prepare("SELECT * FROM audiencistas WHERE id=?");
$st->execute(array($audId));
$aud = $st->fetch();
if (!$aud) { http_response_code(404); die('Audiencista não encontrada.'); }

$mes = (int)($_GET['mes'] ?? 0);
$ano = (int)($_GET['ano'] ?? 0);
$wherePeriodo = ''; $paramsPeriodo = array();
if ($mes && $ano) {
    $wherePeriodo = " AND YEAR(COALESCE(au.data_hora, au.created_at))=? AND MONTH(COALESCE(au.data_hora, au.created_at))=?";
    $paramsPeriodo = array($ano, $mes);
}

$sql = "SELECT au.*, cl.name AS client_name, c.case_number, c.title AS case_title
        FROM audiencias au
        LEFT JOIN clients cl ON cl.id = au.client_id
        LEFT JOIN cases c ON c.id = au.case_id
        WHERE au.audiencista_id = ? $wherePeriodo
        ORDER BY COALESCE(au.data_hora, au.created_at) ASC";
$st = $pdo->prepare($sql);
$st->execute(array_merge(array($audId), $paramsPeriodo));
$lista = $st->fetchAll();

$nomeSafe = preg_replace('/[^A-Za-z0-9]+/', '_', $aud['nome']);
$sufixo = $mes && $ano ? '_' . sprintf('%04d-%02d', $ano, $mes) : '_completo';
$fname = 'audiencias_' . $nomeSafe . $sufixo . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: no-store');

// BOM UTF-8 pra Excel abrir certo
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, array(
    'Data/Hora', 'Tipo', 'Comarca', 'Processo', 'Cliente', 'Status',
    'Valor a pagar (R$)', 'Pago em', 'Valor pago (R$)', 'Forma pagto',
    'Substab anexado', 'Avaliação', 'Comentário avaliação'
), ';');

$totalAPagar = 0; $totalPago = 0;
foreach ($lista as $a) {
    $proc = $a['case_number'] ?: ($a['processo_numero'] ?: ($a['case_title'] ?: ''));
    $v = $a['valor_cents'] !== null ? $a['valor_cents'] : (int)($aud['valor_medio_cents'] ?? 0);
    if ($a['status'] !== 'cancelada') {
        if ($a['pago_em']) $totalPago += (int)($a['pago_valor_cents'] !== null ? $a['pago_valor_cents'] : $v);
        else               $totalAPagar += (int)$v;
    }
    fputcsv($out, array(
        $a['data_hora'] ? date('d/m/Y H:i', strtotime($a['data_hora'])) : '',
        $a['tipo'],
        $a['comarca'],
        $proc,
        $a['client_name'],
        $a['status'],
        $v ? number_format($v / 100, 2, ',', '.') : '',
        $a['pago_em'] ? date('d/m/Y', strtotime($a['pago_em'])) : '',
        $a['pago_valor_cents'] !== null ? number_format($a['pago_valor_cents'] / 100, 2, ',', '.') : '',
        $a['pago_forma'] ?: '',
        $a['substab_path'] ? 'sim' : 'não',
        $a['avaliacao_nota'] ? $a['avaliacao_nota'] . '/5' : '',
        $a['avaliacao_comentario'] ?: ''
    ), ';');
}

// Linha em branco + totais
fputcsv($out, array(''), ';');
fputcsv($out, array('TOTAL A PAGAR', '', '', '', '', '', number_format($totalAPagar / 100, 2, ',', '.')), ';');
fputcsv($out, array('TOTAL JÁ PAGO', '', '', '', '', '', '', '', number_format($totalPago / 100, 2, ',', '.')), ';');
fclose($out);
exit;
