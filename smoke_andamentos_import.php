<?php
/**
 * Smoke test da importação em lote de andamentos.
 * Usa um case de teste temporário (criado no início do script, deletado no fim).
 *
 * Uso: curl "https://ferreiraesa.com.br/conecta/smoke_andamentos_import.php?key=fsa-hub-deploy-2026"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }

require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_utils.php';

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// ── 1) CASE DE TESTE (cria temporário, usa admin=1 como autor) ──
echo "=== PREPARANDO CASE DE TESTE ===\n";
ob_implicit_flush(true); @ob_end_flush();
try {
    $pdo->prepare("INSERT INTO cases (title, case_type, status, client_id, created_at) VALUES (?,?,?,NULL,NOW())")
        ->execute(array('[SMOKE TEST] Vanderleia x Alimentos', 'Alimentos', 'arquivado'));
    $testCaseId = (int)$pdo->lastInsertId();
    echo "Case ID de teste criado: $testCaseId\n\n";
} catch (Exception $e) {
    echo "FALHA ao criar case: " . $e->getMessage() . "\n";
    // Fallback: usa o caso mais recente arquivado como teste
    $testCaseId = (int)$pdo->query("SELECT id FROM cases WHERE status='arquivado' ORDER BY id DESC LIMIT 1")->fetchColumn();
    if (!$testCaseId) { echo "Nenhum case arquivado disponível. Abortando.\n"; exit; }
    echo "Usando case arquivado existente como teste: $testCaseId (limpeza só remove os andamentos novos)\n\n";
    $usouExistente = true;
}

// ── 2) BLOCO DE TESTE (o que a Amanda mandou) ──
$bloco = <<<BLOCO
DATA|HORA|TIPO|DESCRICAO
2026-02-22|16:25|PROTOCOLO|Protocolo da petição inicial da Ação de Alimentos com pedido de fixação de alimentos provisórios. Distribuição à Vara da Família e das Sucessões de Itaquaquecetuba/SP. Valor da causa: R$ 15.561,60.
2026-02-23|10:05|ATO_ORDINATORIO|Ato ordinatório do escrevente Everton Santos Reina determinando vista ao Ministério Público, com tramitação prioritária. Remessa ao Portal Eletrônico do MPSP.
2026-02-23|19:40|MANIFESTACAO_MP|Manifestação do Promotor Lucas Damasceno de Lima (2ª Promotoria de Justiça de Itaquaquecetuba) favorável à concessão dos alimentos provisórios (1/3 dos rendimentos líquidos em caso de vínculo empregatício ou 1/2 salário-mínimo nos demais casos) e concordando com a dispensa de audiência de conciliação.
2026-02-23|19:43|INTIMACAO|Ciência da intimação pelo MPSP via portal eletrônico, prazo de 10 dias.
2026-02-25|16:53|DECISAO|Decisão do Juiz Dr. Antenor da Silva Cápua: (a) deferida gratuidade de justiça; (b) determinada tramitação em segredo de justiça; (c) designada audiência de conciliação presencial para 13/05/2026 às 12:00h no CEJUSC de Itaquaquecetuba; (d) arbitrados alimentos provisórios em 30% dos rendimentos líquidos do réu (vínculo empregatício, incidindo sobre 13º, férias, horas extras e verbas rescisórias) ou 1/2 salário-mínimo (desemprego/autônomo/informal); (e) determinada citação do réu e intimação para audiência, prazo de contestação de 15 dias úteis contados da audiência. Decisão serve como mandado.
2026-02-25|21:42|CERTIDAO|Certidão de remessa da relação nº 0198/2026 para publicação via DJEN, em nome da Dra. Amanda Guedes Ferreira (OAB/RJ 163.260).
2026-02-26||PUBLICACAO_DJEN|Disponibilização da decisão de 25/02/2026 no DJEN (Certidão de Publicação 234037). Data de publicação (termo inicial do prazo, art. 224 CPC): 27/02/2026.
2026-03-15|08:47|PETICAO_PARTE_AUTORA|Protocolada petição da parte autora requerendo conversão da audiência presencial para formato remoto/híbrido, com fundamento na Resolução CNJ 345/2020.
2026-03-18|15:26|DECISAO|Decisão do Juiz Dr. Antenor da Silva Cápua acolhendo o pedido de fls. 37 e convertendo a audiência designada para 13/05/2026 às 12:00h para formato híbrido.
2026-03-18|20:03|CERTIDAO|Certidão de remessa da relação nº 0277/2026 para publicação via DJEN.
2026-03-19||PUBLICACAO_DJEN|Disponibilização da decisão de conversão da audiência para formato híbrido no DJEN (Certidão de Publicação 220474). Data de publicação: 20/03/2026.
2026-04-17|14:27|MANDADO_EXPEDIDO|Expedição do Mandado de Citação e Intimação nº 278.2026/012021-1 dirigido ao réu Wellington Honório Sobral Leite.
BLOCO;

// ── 3) PARSER (mesma lógica do andamentos_importar_analisar) ──
$mapaTipos = array(
    'decisao'=>'decisao','despacho'=>'despacho','sentenca'=>'sentenca','intimacao'=>'intimacao',
    'citacao'=>'citacao','recurso'=>'recurso','acordo'=>'acordo','diligencia'=>'diligencia',
    'movimentacao'=>'movimentacao','observacao'=>'observacao',
    'protocolo'=>'protocolo','distribuicao'=>'distribuicao','ato_ordinatorio'=>'ato_ordinatorio',
    'certidao'=>'certidao','publicacao_djen'=>'publicacao_djen','manifestacao_mp'=>'manifestacao_mp',
    'mandado_expedido'=>'mandado_expedido','acordao'=>'acordao',
    'audiencia_designada'=>'audiencia','audiencia_realizada'=>'audiencia','audiencia'=>'audiencia',
    'peticao_parte_autora'=>'peticao_juntada','peticao_parte_re'=>'peticao_juntada',
    'juntada_documento'=>'peticao_juntada','peticao_juntada'=>'peticao_juntada',
    'mandado_cumprido'=>'cumprimento','cumprimento'=>'cumprimento',
    'conclusao'=>'movimentacao','remessa'=>'movimentacao','baixa'=>'movimentacao',
    'outros'=>'observacao',
);

function parseLinha($linha, $n, $mapaTipos) {
    $partes = explode('|', $linha, 4);
    if (count($partes) < 4) return array('n'=>$n,'status'=>'erro','motivo'=>'Linha com menos de 4 campos','bruto'=>mb_substr($linha,0,150));
    list($dataRaw,$horaRaw,$tipoRaw,$descRaw) = array_map('trim', $partes);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataRaw)) return array('n'=>$n,'status'=>'erro','motivo'=>'Data inválida','bruto'=>mb_substr($linha,0,150));
    $ts = strtotime($dataRaw);
    if (!$ts || date('Y-m-d',$ts) !== $dataRaw) return array('n'=>$n,'status'=>'erro','motivo'=>'Data inexistente no calendário','bruto'=>mb_substr($linha,0,150));
    $horaOk = '';
    if ($horaRaw !== '' && $horaRaw !== '-' && $horaRaw !== 'NULL' && preg_match('/^\d{1,2}:\d{2}$/', $horaRaw)) {
        list($hh,$mm) = explode(':', $horaRaw);
        $hh=(int)$hh; $mm=(int)$mm;
        if ($hh>=0 && $hh<=23 && $mm>=0 && $mm<=59) $horaOk = sprintf('%02d:%02d',$hh,$mm);
    }
    if ($descRaw === '') return array('n'=>$n,'status'=>'erro','motivo'=>'Descrição vazia','bruto'=>mb_substr($linha,0,150));
    $tipoNorm = preg_replace('/\s+/','_', strtolower(trim($tipoRaw)));
    $aviso = null;
    if (isset($mapaTipos[$tipoNorm])) {
        $tipoFinal = $mapaTipos[$tipoNorm];
        if ($tipoFinal !== $tipoNorm) $aviso = 'Tipo "' . $tipoRaw . '" mapeado para "' . $tipoFinal . '"';
    } else {
        $tipoFinal = 'observacao';
        $aviso = 'Tipo "' . $tipoRaw . '" NÃO reconhecido — salvo como observacao (revisar)';
    }
    // Descrição limpa — hora vai em coluna própria
    $descFinal = preg_replace('/^\s*\[\d{1,2}:\d{2}\]\s*/', '', $descRaw);
    return array(
        'n'=>$n,'status'=>$aviso?'warn':'ok',
        'data'=>$dataRaw,'hora'=>$horaOk,'tipo_original'=>$tipoRaw,
        'tipo'=>$tipoFinal,'descricao'=>$descFinal,'aviso'=>$aviso,
    );
}

$linhas = preg_split("/\r\n|\r|\n/", $bloco);
$parseados = array();
$n = 0;
foreach ($linhas as $bruto) {
    $linha = trim($bruto);
    if ($linha === '') continue;
    if (strpos(mb_strtoupper($linha), 'DATA|HORA|TIPO|DESC') === 0) { echo "[cabeçalho ignorado] $linha\n\n"; continue; }
    $n++;
    $parseados[] = parseLinha($linha, $n, $mapaTipos);
}

// ── 4) OUTPUT 1: ARRAY PARSEADO ──
echo "=== 1. ARRAY PARSEADO COMPLETO ===\n\n";
$totalOk = 0; $totalWarn = 0; $totalErr = 0;
foreach ($parseados as $p) {
    $icon = $p['status'] === 'ok' ? '✓' : ($p['status'] === 'warn' ? '⚠' : '✗');
    echo "#" . str_pad($p['n'], 2, ' ', STR_PAD_LEFT) . " $icon [" . strtoupper($p['status']) . "]";
    if (isset($p['data'])) {
        echo " | " . $p['data'] . ($p['hora'] ? ' ' . $p['hora'] : ' (sem hora)');
        echo " | tipo_original=" . $p['tipo_original'];
        echo " → mapeado=" . $p['tipo'];
        echo "\n   desc: " . mb_substr($p['descricao'], 0, 100) . (mb_strlen($p['descricao']) > 100 ? '...' : '');
        if ($p['aviso']) echo "\n   ⚠️  aviso: " . $p['aviso'];
    } else {
        echo " | motivo=" . ($p['motivo'] ?? '?');
    }
    echo "\n\n";
    if ($p['status'] === 'ok') $totalOk++;
    elseif ($p['status'] === 'warn') $totalWarn++;
    else $totalErr++;
}
echo "TOTAIS: ok=$totalOk | warn=$totalWarn | erro=$totalErr (de " . count($parseados) . " linhas)\n\n";

// ── 5) GRAVAÇÃO em transação ──
echo "=== 2. GRAVAÇÃO NO BANCO (case_id=$testCaseId) ===\n\n";
$tiposPermitidos = array(
    'movimentacao','observacao','peticao_juntada','intimacao','decisao','sentenca',
    'audiencia','despacho','cumprimento','recurso','citacao','acordo','diligencia',
    'protocolo','distribuicao','ato_ordinatorio','certidao','publicacao_djen',
    'manifestacao_mp','mandado_expedido','acordao','chamado',
);
$aprovados = array_filter($parseados, function($p){ return in_array($p['status'], array('ok','warn'), true); });
$idsInseridos = array();
// Garante coluna hora_andamento
try { $pdo->exec("ALTER TABLE case_andamentos ADD COLUMN hora_andamento TIME NULL AFTER data_andamento"); } catch (Exception $e) {}
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO case_andamentos (case_id, data_andamento, hora_andamento, tipo, descricao, created_by, created_at, tipo_origem, visivel_cliente)
                           VALUES (?, ?, ?, ?, ?, 1, NOW(), 'importacao_lote', 1)");
    foreach ($aprovados as $p) {
        $tipoGravar = in_array($p['tipo'], $tiposPermitidos, true) ? $p['tipo'] : 'observacao';
        $horaSql = $p['hora'] ? $p['hora'] . ':00' : null;
        $stmt->execute(array($testCaseId, $p['data'], $horaSql, $tipoGravar, $p['descricao']));
        $idsInseridos[] = (int)$pdo->lastInsertId();
    }
    $pdo->commit();
    echo "✓ Transação commit — " . count($idsInseridos) . " registros gravados\n";
    echo "IDs inseridos: " . implode(', ', $idsInseridos) . "\n\n";
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "✗ ROLLBACK — Erro: " . $e->getMessage() . "\n";
    exit;
}

// ── 6) SELECT dos recém-inseridos ──
echo "=== SELECT dos recém-inseridos (via id IN ...) ===\n\n";
$ph = implode(',', array_fill(0, count($idsInseridos), '?'));
$stmt = $pdo->prepare("SELECT id, case_id, data_andamento, hora_andamento, tipo, LEFT(descricao,70) as desc_trunc, tipo_origem FROM case_andamentos WHERE id IN ($ph) ORDER BY data_andamento, hora_andamento, id");
$stmt->execute($idsInseridos);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo sprintf("  #%-5d | %s %s | %-20s | %s...\n",
        $r['id'], $r['data_andamento'], ($r['hora_andamento'] ?: '  --:--  '), $r['tipo'], $r['desc_trunc']
    );
}

// ── 7) OUTPUT 3: tipos que caíram em warn ──
echo "\n=== 3. TIPOS QUE GERARAM WARN ===\n\n";
$warns = array();
foreach ($parseados as $p) {
    if ($p['status'] === 'warn' && isset($p['tipo_original'])) {
        $key = $p['tipo_original'];
        if (!isset($warns[$key])) $warns[$key] = array('mapeado'=>$p['tipo'],'aviso'=>$p['aviso'],'qt'=>0);
        $warns[$key]['qt']++;
    }
}
if (empty($warns)) {
    echo "✓ Nenhum warn — todos os tipos foram reconhecidos diretamente.\n";
} else {
    foreach ($warns as $orig => $info) {
        echo "  [$orig] x" . $info['qt'] . " — virou \"" . $info['mapeado'] . "\"\n    aviso: " . $info['aviso'] . "\n";
    }
}

// ── 8) CLEANUP ──
echo "\n=== LIMPEZA ===\n";
// Deleta apenas os andamentos inseridos no teste (por ID), não tudo do case
$phDel = implode(',', array_fill(0, count($idsInseridos), '?'));
$pdo->prepare("DELETE FROM case_andamentos WHERE id IN ($phDel)")->execute($idsInseridos);
echo "✓ " . count($idsInseridos) . " andamento(s) de teste deletado(s).\n";
if (empty($usouExistente)) {
    $pdo->prepare("DELETE FROM cases WHERE id = ?")->execute(array($testCaseId));
    echo "✓ Case de teste #$testCaseId deletado.\n";
} else {
    echo "ℹ️  Case #$testCaseId preservado (era um arquivado existente).\n";
}
echo "\n=== FIM ===\n";
