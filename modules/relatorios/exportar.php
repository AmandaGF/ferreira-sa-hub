<?php
/**
 * Ferreira & Sá Hub — Exportar relatórios em CSV
 */

require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

$pdo = db();
$tipo = isset($_GET['tipo']) ? $_GET['tipo'] : '';
$de = isset($_GET['de']) ? $_GET['de'] : date('Y-m-01');
$ate = isset($_GET['ate']) ? $_GET['ate'] : date('Y-m-d');
$mes = isset($_GET['mes']) ? (int)$_GET['mes'] : (int)date('n');

$meses = array('','Janeiro','Fevereiro','Marco','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro');

header('Content-Type: text/csv; charset=utf-8');
header('Cache-Control: no-cache');
echo "\xEF\xBB\xBF"; // BOM UTF-8

switch ($tipo) {
    case 'aniversariantes':
        header('Content-Disposition: attachment; filename="aniversariantes_' . $meses[$mes] . '.csv"');

        $stmt = $pdo->prepare(
            "SELECT c.name, DAY(c.birth_date) as dia, c.birth_date, c.phone, c.email,
             TIMESTAMPDIFF(YEAR, c.birth_date, CURDATE()) as idade
             FROM clients c WHERE c.birth_date IS NOT NULL AND MONTH(c.birth_date) = ?
             ORDER BY DAY(c.birth_date) ASC"
        );
        $stmt->execute(array($mes));
        $rows = $stmt->fetchAll();

        $fp = fopen('php://output', 'w');
        fputcsv($fp, array('Dia', 'Nome', 'Idade', 'Nascimento', 'Telefone', 'E-mail'), ';');
        foreach ($rows as $r) {
            fputcsv($fp, array(
                str_pad($r['dia'], 2, '0', STR_PAD_LEFT),
                $r['name'],
                $r['idade'] . ' anos',
                date('d/m/Y', strtotime($r['birth_date'])),
                $r['phone'] ?: '',
                $r['email'] ?: ''
            ), ';');
        }
        fclose($fp);
        break;

    case 'leads_origem':
        header('Content-Disposition: attachment; filename="leads_origem_' . $de . '_' . $ate . '.csv"');

        $sourceLabels = array('calculadora'=>'Calculadora','landing'=>'Site','indicacao'=>'Indicacao','instagram'=>'Instagram','google'=>'Google','whatsapp'=>'WhatsApp','outro'=>'Outro');

        $stmt = $pdo->prepare(
            "SELECT pl.name, pl.phone, pl.email, pl.source, pl.stage, pl.case_type,
             DATE_FORMAT(pl.created_at, '%d/%m/%Y') as data_criacao,
             u.name as responsavel
             FROM pipeline_leads pl
             LEFT JOIN users u ON u.id = pl.assigned_to
             WHERE DATE(pl.created_at) BETWEEN ? AND ?
             ORDER BY pl.created_at DESC"
        );
        $stmt->execute(array($de, $ate));
        $rows = $stmt->fetchAll();

        $fp = fopen('php://output', 'w');
        fputcsv($fp, array('Nome', 'Telefone', 'E-mail', 'Origem', 'Estagio', 'Tipo', 'Data', 'Responsavel'), ';');
        foreach ($rows as $r) {
            fputcsv($fp, array(
                $r['name'],
                $r['phone'] ?: '',
                $r['email'] ?: '',
                isset($sourceLabels[$r['source']]) ? $sourceLabels[$r['source']] : $r['source'],
                $r['stage'],
                $r['case_type'] ?: '',
                $r['data_criacao'],
                $r['responsavel'] ?: ''
            ), ';');
        }
        fclose($fp);
        break;

    case 'produtividade':
        header('Content-Disposition: attachment; filename="produtividade_' . $de . '_' . $ate . '.csv"');

        $rows = $pdo->query(
            "SELECT u.name,
             COUNT(*) as total_casos,
             SUM(CASE WHEN cs.status = 'concluido' THEN 1 ELSE 0 END) as concluidos,
             SUM(CASE WHEN cs.status NOT IN ('concluido','arquivado') THEN 1 ELSE 0 END) as ativos,
             SUM(CASE WHEN cs.priority = 'urgente' AND cs.status NOT IN ('concluido','arquivado') THEN 1 ELSE 0 END) as urgentes
             FROM cases cs
             JOIN users u ON u.id = cs.responsible_user_id
             GROUP BY cs.responsible_user_id
             ORDER BY total_casos DESC"
        )->fetchAll();

        $fp = fopen('php://output', 'w');
        fputcsv($fp, array('Responsavel', 'Total Casos', 'Concluidos', 'Ativos', 'Urgentes'), ';');
        foreach ($rows as $r) {
            fputcsv($fp, array($r['name'], $r['total_casos'], $r['concluidos'], $r['ativos'], $r['urgentes']), ';');
        }
        fclose($fp);
        break;

    default:
        header('Content-Type: text/html');
        flash_set('error', 'Tipo de exportação inválido.');
        redirect(module_url('relatorios'));
}
