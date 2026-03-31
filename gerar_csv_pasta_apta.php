<?php
/**
 * Gera CSV com casos em "Pasta Apta" (em_elaboracao) no Operacional
 * e identifica quais poderiam ser movidos para "Processo Distribuído"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$format = isset($_GET['format']) ? $_GET['format'] : 'csv';

// Buscar todos os casos em_elaboracao (Pasta Apta no Operacional)
$sql = "SELECT
    c.id,
    c.title,
    c.case_number,
    c.court,
    c.case_type,
    c.distribution_date,
    c.opened_at,
    c.created_at,
    c.drive_folder_url,
    c.notes,
    u.name as responsavel,
    cl.name as cliente_nome,
    cl.phone as cliente_telefone
FROM cases c
LEFT JOIN users u ON u.id = c.responsible_user_id
LEFT JOIN clients cl ON cl.id = c.client_id
WHERE c.status = 'em_elaboracao'
ORDER BY c.created_at ASC";

$cases = $pdo->query($sql)->fetchAll();

// Verificar se existe tabela processos para cruzamento
$temProcessos = false;
try {
    $pdo->query("SELECT 1 FROM processos LIMIT 1");
    $temProcessos = true;
} catch (Exception $e) {}

// Cruzar com tabela processos (importada) se existir
$processosMap = array();
if ($temProcessos) {
    $rows = $pdo->query("SELECT nome_pasta, numero_processo, vara_juizo, data_distribuicao FROM processos WHERE numero_processo IS NOT NULL AND numero_processo != ''")->fetchAll();
    foreach ($rows as $r) {
        $key = mb_strtolower(trim($r['nome_pasta']));
        $processosMap[$key] = $r;
    }
}

// Analisar cada caso e gerar motivo
$resultado = array();
foreach ($cases as $c) {
    $motivos = array();
    $recomenda = false;

    // Critério 1: Tem numero_processo preenchido no próprio caso
    if ($c['case_number'] && trim($c['case_number']) !== '') {
        $motivos[] = 'Tem numero_processo preenchido: ' . $c['case_number'];
        $recomenda = true;
    }

    // Critério 2: Tem data_distribuicao preenchida
    if ($c['distribution_date']) {
        $motivos[] = 'Tem data_distribuicao: ' . $c['distribution_date'];
        $recomenda = true;
    }

    // Critério 3: Tem vara/juízo preenchido
    if ($c['court'] && trim($c['court']) !== '') {
        $motivos[] = 'Tem vara/juizo: ' . $c['court'];
    }

    // Critério 4: Cruzar com tabela processos (importada)
    $titleLower = mb_strtolower(trim($c['title']));
    if (isset($processosMap[$titleLower])) {
        $proc = $processosMap[$titleLower];
        $motivos[] = 'Encontrado na tabela processos: ' . $proc['numero_processo'] . ' (' . $proc['vara_juizo'] . ', dist: ' . $proc['data_distribuicao'] . ')';
        $recomenda = true;
    }

    // Se não tem nenhum motivo
    if (empty($motivos)) {
        $motivos[] = 'Sem indicadores de distribuicao';
    }

    $resultado[] = array(
        'id' => $c['id'],
        'titulo' => $c['title'],
        'tipo_acao' => $c['case_type'],
        'cliente' => $c['cliente_nome'],
        'data_cadastro' => $c['opened_at'] ?: substr($c['created_at'], 0, 10),
        'executante' => $c['responsavel'] ?: 'Nao atribuido',
        'numero_processo' => $c['case_number'] ?: '',
        'vara_juizo' => $c['court'] ?: '',
        'data_distribuicao' => $c['distribution_date'] ?: '',
        'recomenda_mover' => $recomenda ? 'SIM' : 'NAO',
        'motivo' => implode(' | ', $motivos),
    );
}

// Contar resumo
$totalCasos = count($resultado);
$totalRecomendados = 0;
foreach ($resultado as $r) {
    if ($r['recomenda_mover'] === 'SIM') $totalRecomendados++;
}

if ($format === 'csv') {
    // Gerar CSV para download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pasta_apta_revisao_' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    // BOM para Excel reconhecer UTF-8
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, array('ID', 'Titulo do Caso', 'Tipo Acao', 'Cliente', 'Data Cadastro', 'Executante', 'Numero Processo', 'Vara/Juizo', 'Data Distribuicao', 'Recomenda Mover?', 'Motivo'), ';');

    foreach ($resultado as $r) {
        fputcsv($out, array_values($r), ';');
    }

    fclose($out);
} else {
    // Formato texto para visualização rápida
    header('Content-Type: text/plain; charset=utf-8');

    echo "=== CASOS EM PASTA APTA (em_elaboracao) ===\n";
    echo "Total: $totalCasos casos\n";
    echo "Recomendados para mover para Processo Distribuido: $totalRecomendados\n\n";

    echo "--- RECOMENDADOS (SIM) ---\n\n";
    foreach ($resultado as $r) {
        if ($r['recomenda_mover'] !== 'SIM') continue;
        echo "#{$r['id']} | {$r['titulo']}\n";
        echo "   Cliente: {$r['cliente']} | Tipo: {$r['tipo_acao']} | Cadastro: {$r['data_cadastro']}\n";
        echo "   Executante: {$r['executante']}\n";
        echo "   Processo: {$r['numero_processo']} | Vara: {$r['vara_juizo']} | Dist: {$r['data_distribuicao']}\n";
        echo "   Motivo: {$r['motivo']}\n\n";
    }

    echo "--- NAO RECOMENDADOS ---\n\n";
    foreach ($resultado as $r) {
        if ($r['recomenda_mover'] !== 'NAO') continue;
        echo "#{$r['id']} | {$r['titulo']} | {$r['executante']} | Cadastro: {$r['data_cadastro']}\n";
    }
}
