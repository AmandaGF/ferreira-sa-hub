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
$status = isset($_GET['status']) ? $_GET['status'] : '';

$meses = array('','Janeiro','Fevereiro','Marco','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro');

$stageLabels = array('cadastro_preenchido'=>'Cadastro Preenchido','elaboracao_docs'=>'Elaboracao Docs','link_enviados'=>'Link Enviados','contrato_assinado'=>'Contrato Assinado','agendado_docs'=>'Agendado/Docs','reuniao_cobranca'=>'Reuniao/Cobranca','doc_faltante'=>'Doc Faltante','pasta_apta'=>'Pasta Apta','finalizado'=>'Finalizado','perdido'=>'Perdido');
$statusLabels = array('aguardando_docs'=>'Aguardando Docs','em_elaboracao'=>'Pasta Apta','em_andamento'=>'Em Execucao','doc_faltante'=>'Doc Faltante','aguardando_prazo'=>'Aguardando Distribuicao','distribuido'=>'Processo Distribuido','concluido'=>'Concluido','arquivado'=>'Arquivado');

header('Content-Type: text/csv; charset=utf-8');
header('Cache-Control: no-cache');
echo "\xEF\xBB\xBF"; // BOM UTF-8

switch ($tipo) {

    // ═══════════════════════════════════════════════════════
    // 1. ANIVERSARIANTES
    // ═══════════════════════════════════════════════════════
    case 'aniversariantes':
        header('Content-Disposition: attachment; filename="aniversariantes_' . $meses[$mes] . '.csv"');
        $stmt = $pdo->prepare(
            "SELECT c.name, DAY(c.birth_date) as dia, c.birth_date, c.phone, c.email,
             TIMESTAMPDIFF(YEAR, c.birth_date, CURDATE()) as idade
             FROM clients c WHERE c.birth_date IS NOT NULL AND MONTH(c.birth_date) = ?
             ORDER BY DAY(c.birth_date) ASC"
        );
        $stmt->execute(array($mes));
        $fp = fopen('php://output', 'w');
        fputcsv($fp, array('Dia', 'Nome', 'Idade', 'Nascimento', 'Telefone', 'E-mail'), ';');
        foreach ($stmt->fetchAll() as $r) {
            fputcsv($fp, array(str_pad($r['dia'], 2, '0', STR_PAD_LEFT), $r['name'], $r['idade'] . ' anos', date('d/m/Y', strtotime($r['birth_date'])), $r['phone'] ?: '', $r['email'] ?: ''), ';');
        }
        fclose($fp);
        break;

    // ═══════════════════════════════════════════════════════
    // 2. LEADS POR ORIGEM
    // ═══════════════════════════════════════════════════════
    case 'leads_origem':
        header('Content-Disposition: attachment; filename="leads_' . $de . '_a_' . $ate . '.csv"');
        $sourceLabels = array('calculadora'=>'Calculadora','landing'=>'Site','indicacao'=>'Indicacao','instagram'=>'Instagram','google'=>'Google','whatsapp'=>'WhatsApp','outro'=>'Outro');
        $stmt = $pdo->prepare(
            "SELECT pl.name, pl.phone, pl.email, pl.source, pl.stage, pl.case_type,
             DATE_FORMAT(pl.created_at, '%d/%m/%Y') as data_criacao,
             DATE_FORMAT(pl.converted_at, '%d/%m/%Y') as data_conversao,
             DATEDIFF(COALESCE(pl.converted_at, NOW()), pl.created_at) as dias_funil,
             u.name as responsavel
             FROM pipeline_leads pl LEFT JOIN users u ON u.id = pl.assigned_to
             WHERE DATE(pl.created_at) BETWEEN ? AND ?
             ORDER BY pl.created_at DESC"
        );
        $stmt->execute(array($de, $ate));
        $fp = fopen('php://output', 'w');
        fputcsv($fp, array('Nome', 'Telefone', 'E-mail', 'Origem', 'Estagio', 'Tipo Acao', 'Data Entrada', 'Data Conversao', 'Dias no Funil', 'Responsavel'), ';');
        foreach ($stmt->fetchAll() as $r) {
            fputcsv($fp, array($r['name'], $r['phone'] ?: '', $r['email'] ?: '', isset($sourceLabels[$r['source']]) ? $sourceLabels[$r['source']] : $r['source'], isset($stageLabels[$r['stage']]) ? $stageLabels[$r['stage']] : $r['stage'], $r['case_type'] ?: '', $r['data_criacao'], $r['data_conversao'] ?: '', $r['dias_funil'], $r['responsavel'] ?: ''), ';');
        }
        fclose($fp);
        break;

    // ═══════════════════════════════════════════════════════
    // 3. PRODUTIVIDADE
    // ═══════════════════════════════════════════════════════
    case 'produtividade':
        header('Content-Disposition: attachment; filename="produtividade.csv"');
        $rows = $pdo->query(
            "SELECT u.name,
             COUNT(*) as total_casos,
             SUM(CASE WHEN cs.status = 'concluido' THEN 1 ELSE 0 END) as concluidos,
             SUM(CASE WHEN cs.status NOT IN ('concluido','arquivado') THEN 1 ELSE 0 END) as ativos,
             SUM(CASE WHEN cs.priority = 'urgente' AND cs.status NOT IN ('concluido','arquivado') THEN 1 ELSE 0 END) as urgentes,
             SUM(CASE WHEN cs.status = 'doc_faltante' THEN 1 ELSE 0 END) as doc_faltante
             FROM cases cs JOIN users u ON u.id = cs.responsible_user_id
             GROUP BY cs.responsible_user_id ORDER BY total_casos DESC"
        )->fetchAll();
        $fp = fopen('php://output', 'w');
        fputcsv($fp, array('Responsavel', 'Total Casos', 'Concluidos', 'Ativos', 'Urgentes', 'Doc Faltante'), ';');
        foreach ($rows as $r) {
            fputcsv($fp, array($r['name'], $r['total_casos'], $r['concluidos'], $r['ativos'], $r['urgentes'], $r['doc_faltante']), ';');
        }
        fclose($fp);
        break;

    // ═══════════════════════════════════════════════════════
    // 4. CLIENTES COMPLETO
    // ═══════════════════════════════════════════════════════
    case 'clientes':
        header('Content-Disposition: attachment; filename="clientes_completo.csv"');
        $rows = $pdo->query(
            "SELECT c.name, c.cpf, c.rg, DATE_FORMAT(c.birth_date, '%d/%m/%Y') as nascimento,
             c.phone, c.phone2, c.email, c.profession, c.marital_status,
             c.address_street, c.address_city, c.address_state, c.address_zip,
             c.gender, c.pix_key, c.source, c.client_status,
             DATE_FORMAT(c.created_at, '%d/%m/%Y') as data_cadastro
             FROM clients c ORDER BY c.name ASC"
        )->fetchAll();
        $fp = fopen('php://output', 'w');
        fputcsv($fp, array('Nome', 'CPF', 'RG', 'Nascimento', 'Telefone', 'Telefone 2', 'E-mail', 'Profissao', 'Estado Civil', 'Endereco', 'Cidade', 'UF', 'CEP', 'Genero', 'PIX', 'Origem', 'Status', 'Data Cadastro'), ';');
        foreach ($rows as $r) {
            fputcsv($fp, array($r['name'], $r['cpf'] ?: '', $r['rg'] ?: '', $r['nascimento'] ?: '', $r['phone'] ?: '', $r['phone2'] ?: '', $r['email'] ?: '', $r['profession'] ?: '', $r['marital_status'] ?: '', $r['address_street'] ?: '', $r['address_city'] ?: '', $r['address_state'] ?: '', $r['address_zip'] ?: '', $r['gender'] ?: '', $r['pix_key'] ?: '', $r['source'] ?: '', $r['client_status'] ?: 'ativo', $r['data_cadastro']), ';');
        }
        fclose($fp);
        break;

    // ═══════════════════════════════════════════════════════
    // 5. PIPELINE COMPLETO
    // ═══════════════════════════════════════════════════════
    case 'pipeline':
        header('Content-Disposition: attachment; filename="pipeline_completo.csv"');
        $rows = $pdo->query(
            "SELECT pl.name, pl.phone, pl.email, pl.source, pl.stage, pl.case_type,
             pl.lost_reason, pl.doc_faltante_motivo,
             DATE_FORMAT(pl.created_at, '%d/%m/%Y') as data_entrada,
             DATE_FORMAT(pl.converted_at, '%d/%m/%Y') as data_conversao,
             DATEDIFF(COALESCE(pl.converted_at, NOW()), pl.created_at) as dias_funil,
             u.name as responsavel, c.name as cliente_vinculado
             FROM pipeline_leads pl
             LEFT JOIN users u ON u.id = pl.assigned_to
             LEFT JOIN clients c ON c.id = pl.client_id
             ORDER BY pl.created_at DESC"
        )->fetchAll();
        $fp = fopen('php://output', 'w');
        fputcsv($fp, array('Nome', 'Telefone', 'E-mail', 'Origem', 'Estagio', 'Tipo Acao', 'Motivo Perda', 'Doc Faltante', 'Data Entrada', 'Data Conversao', 'Dias no Funil', 'Responsavel', 'Cliente Vinculado'), ';');
        foreach ($rows as $r) {
            fputcsv($fp, array($r['name'], $r['phone'] ?: '', $r['email'] ?: '', $r['source'] ?: '', isset($stageLabels[$r['stage']]) ? $stageLabels[$r['stage']] : $r['stage'], $r['case_type'] ?: '', $r['lost_reason'] ?: '', $r['doc_faltante_motivo'] ?: '', $r['data_entrada'], $r['data_conversao'] ?: '', $r['dias_funil'], $r['responsavel'] ?: '', $r['cliente_vinculado'] ?: ''), ';');
        }
        fclose($fp);
        break;

    // ═══════════════════════════════════════════════════════
    // 6. CASOS OPERACIONAIS
    // ═══════════════════════════════════════════════════════
    case 'casos':
        header('Content-Disposition: attachment; filename="casos_operacionais.csv"');
        $where = "1=1";
        if ($status) $where .= " AND cs.status = '" . addslashes($status) . "'";
        $rows = $pdo->query(
            "SELECT cs.title, cs.case_type, cs.case_number, cs.court, cs.status, cs.priority,
             DATE_FORMAT(cs.deadline, '%d/%m/%Y') as prazo,
             DATE_FORMAT(cs.opened_at, '%d/%m/%Y') as abertura,
             DATE_FORMAT(cs.closed_at, '%d/%m/%Y') as fechamento,
             cs.drive_folder_url,
             c.name as cliente, c.phone as telefone,
             u.name as responsavel,
             (SELECT COUNT(*) FROM case_tasks ct WHERE ct.case_id = cs.id AND ct.status = 'pendente') as tarefas_pendentes,
             (SELECT COUNT(*) FROM case_tasks ct WHERE ct.case_id = cs.id AND ct.status = 'feito') as tarefas_feitas
             FROM cases cs
             LEFT JOIN clients c ON c.id = cs.client_id
             LEFT JOIN users u ON u.id = cs.responsible_user_id
             WHERE $where
             ORDER BY cs.created_at DESC"
        )->fetchAll();
        $fp = fopen('php://output', 'w');
        fputcsv($fp, array('Titulo', 'Tipo', 'Num Processo', 'Vara', 'Status', 'Prioridade', 'Prazo', 'Abertura', 'Fechamento', 'Cliente', 'Telefone', 'Responsavel', 'Tarefas Pendentes', 'Tarefas Feitas', 'Pasta Drive'), ';');
        foreach ($rows as $r) {
            fputcsv($fp, array($r['title'], $r['case_type'] ?: '', $r['case_number'] ?: '', $r['court'] ?: '', isset($statusLabels[$r['status']]) ? $statusLabels[$r['status']] : $r['status'], $r['priority'], $r['prazo'] ?: '', $r['abertura'] ?: '', $r['fechamento'] ?: '', $r['cliente'] ?: '', $r['telefone'] ?: '', $r['responsavel'] ?: '', $r['tarefas_pendentes'], $r['tarefas_feitas'], $r['drive_folder_url'] ?: ''), ';');
        }
        fclose($fp);
        break;

    // ═══════════════════════════════════════════════════════
    // 7. CONTRATOS DO MÊS
    // ═══════════════════════════════════════════════════════
    case 'contratos':
        header('Content-Disposition: attachment; filename="contratos_' . $de . '_a_' . $ate . '.csv"');
        $stmt = $pdo->prepare(
            "SELECT pl.name, pl.phone, pl.email, pl.case_type, pl.source,
             DATE_FORMAT(pl.converted_at, '%d/%m/%Y') as data_contrato,
             DATEDIFF(pl.converted_at, pl.created_at) as dias_ate_contrato,
             u.name as responsavel
             FROM pipeline_leads pl
             LEFT JOIN users u ON u.id = pl.assigned_to
             WHERE pl.converted_at IS NOT NULL AND DATE(pl.converted_at) BETWEEN ? AND ?
             ORDER BY pl.converted_at DESC"
        );
        $stmt->execute(array($de, $ate));
        $fp = fopen('php://output', 'w');
        fputcsv($fp, array('Nome', 'Telefone', 'E-mail', 'Tipo Acao', 'Origem', 'Data Contrato', 'Dias ate Contrato', 'Responsavel'), ';');
        foreach ($stmt->fetchAll() as $r) {
            fputcsv($fp, array($r['name'], $r['phone'] ?: '', $r['email'] ?: '', $r['case_type'] ?: '', $r['source'] ?: '', $r['data_contrato'], $r['dias_ate_contrato'], $r['responsavel'] ?: ''), ';');
        }
        fclose($fp);
        break;

    // ═══════════════════════════════════════════════════════
    // 8. DOCUMENTOS FALTANTES
    // ═══════════════════════════════════════════════════════
    case 'docs_faltantes':
        header('Content-Disposition: attachment; filename="docs_faltantes.csv"');
        $rows = $pdo->query(
            "SELECT dp.descricao, dp.status,
             DATE_FORMAT(dp.solicitado_em, '%d/%m/%Y %H:%i') as data_solicitacao,
             DATE_FORMAT(dp.recebido_em, '%d/%m/%Y %H:%i') as data_recebimento,
             c.name as cliente, c.phone as telefone,
             us.name as solicitante, ur.name as receptor
             FROM documentos_pendentes dp
             LEFT JOIN clients c ON c.id = dp.client_id
             LEFT JOIN users us ON us.id = dp.solicitado_por
             LEFT JOIN users ur ON ur.id = dp.recebido_por
             ORDER BY dp.solicitado_em DESC"
        )->fetchAll();
        $fp = fopen('php://output', 'w');
        fputcsv($fp, array('Documento', 'Status', 'Cliente', 'Telefone', 'Solicitado por', 'Data Solicitacao', 'Recebido por', 'Data Recebimento'), ';');
        foreach ($rows as $r) {
            fputcsv($fp, array($r['descricao'], $r['status'] === 'pendente' ? 'PENDENTE' : 'Recebido', $r['cliente'] ?: '', $r['telefone'] ?: '', $r['solicitante'] ?: '', $r['data_solicitacao'], $r['receptor'] ?: '', $r['data_recebimento'] ?: ''), ';');
        }
        fclose($fp);
        break;

    // ═══════════════════════════════════════════════════════
    // 9. FORMULÁRIOS RECEBIDOS
    // ═══════════════════════════════════════════════════════
    case 'formularios':
        header('Content-Disposition: attachment; filename="formularios_' . $de . '_a_' . $ate . '.csv"');
        $stmt = $pdo->prepare(
            "SELECT fs.form_type, fs.protocol, fs.client_name, fs.client_phone, fs.client_email,
             fs.status, DATE_FORMAT(fs.created_at, '%d/%m/%Y %H:%i') as data_envio,
             c.name as cliente_vinculado
             FROM form_submissions fs
             LEFT JOIN clients c ON c.id = fs.linked_client_id
             WHERE DATE(fs.created_at) BETWEEN ? AND ?
             ORDER BY fs.created_at DESC"
        );
        $stmt->execute(array($de, $ate));
        $fp = fopen('php://output', 'w');
        fputcsv($fp, array('Tipo', 'Protocolo', 'Nome', 'Telefone', 'E-mail', 'Status', 'Data Envio', 'Cliente Vinculado'), ';');
        foreach ($stmt->fetchAll() as $r) {
            fputcsv($fp, array($r['form_type'], $r['protocol'], $r['client_name'] ?: '', $r['client_phone'] ?: '', $r['client_email'] ?: '', $r['status'], $r['data_envio'], $r['cliente_vinculado'] ?: ''), ';');
        }
        fclose($fp);
        break;

    default:
        header('Content-Type: text/html');
        flash_set('error', 'Tipo de exportação inválido.');
        redirect(module_url('relatorios'));
}
