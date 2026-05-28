<?php
/**
 * Ferreira & Sa Hub -- Migracao das 51 demandas previdenciarias da planilha.
 *
 * Le os registros (constante PHP abaixo), faz match de cliente por nome
 * (case-insensitive, sem acentos), cria cases + cases_previdenciario.
 * Casos sem cliente ou com cliente ambiguo vao para prev_migracao_pendente
 * (revisao manual).
 *
 * URL:
 *   /migrar_demandas_prev.php?key=fsa-hub-deploy-2026&modo=simular   (default)
 *   /migrar_demandas_prev.php?key=fsa-hub-deploy-2026&modo=executar
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL);
ini_set('display_errors', '1');
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
        @http_response_code(500);
        echo '<pre style="color:#7f1d1d;background:#fee2e2;padding:1rem;">FATAL: ' . htmlspecialchars($e['message']) . "\nem " . htmlspecialchars($e['file']) . ':' . $e['line'] . '</pre>';
    }
});

require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_utils.php';
header('Content-Type: text/html; charset=utf-8');

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$modo = ($_GET['modo'] ?? 'simular') === 'executar' ? 'executar' : 'simular';
$assumirAmanda = function_exists('current_user_id') ? current_user_id() : null;
if (!$assumirAmanda) $assumirAmanda = 1; // fallback Amanda#1 (importacao admin via curl)
$_uid_audit = $assumirAmanda; // usar nas chamadas de audit
function _audit_safe($acao, $entidade, $eid, $msg) {
    if (function_exists('audit_log')) { try { audit_log($acao, $entidade, $eid, $msg); } catch (Exception $e) {} }
}

echo '<!doctype html><meta charset="utf-8">';
echo '<style>
body{font-family:system-ui,sans-serif;background:#f8f4ef;color:#052228;padding:1.5rem;max-width:1100px;margin:0 auto;}
h1{color:#052228;border-bottom:3px solid #B87333;padding-bottom:.5rem;}
h2{color:#6a3c2c;margin-top:1.5rem;}
.box{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1rem 1.2rem;margin:.7rem 0;}
.ok{background:#d1fae5;color:#065f46;padding:.6rem .9rem;border-radius:8px;margin:.5rem 0;border-left:4px solid #10b981;font-weight:600;}
.erro{background:#fee2e2;color:#7f1d1d;padding:.6rem .9rem;border-radius:8px;margin:.5rem 0;border-left:4px solid #dc2626;}
.warn{background:#fef3c7;color:#92400e;padding:.6rem .9rem;border-radius:8px;margin:.5rem 0;border-left:4px solid #f59e0b;}
.info{background:#eff6ff;color:#1e40af;padding:.6rem .9rem;border-radius:8px;margin:.5rem 0;border-left:4px solid #3b82f6;}
table{width:100%;border-collapse:collapse;font-size:.82rem;background:#fff;margin:.5rem 0;}
th{background:#052228;color:#fff;padding:.4rem .6rem;text-align:left;font-size:.7rem;text-transform:uppercase;}
td{padding:.35rem .6rem;border-bottom:1px solid #e5e7eb;vertical-align:top;}
.match-ok{color:#065f46;font-weight:600;}
.match-fail{color:#7f1d1d;font-weight:600;}
.match-amb{color:#9a3412;font-weight:600;}
code{background:#e5e7eb;padding:1px 5px;border-radius:3px;font-size:.78rem;}
.modo-banner{padding:.8rem 1.2rem;border-radius:8px;margin-bottom:1rem;font-weight:600;text-align:center;}
.modo-simular{background:#fef3c7;color:#92400e;border:2px solid #f59e0b;}
.modo-executar{background:#fee2e2;color:#7f1d1d;border:2px solid #dc2626;}
</style>';

echo '<h1>Migracao 51 demandas previdenciarias</h1>';
echo '<div class="modo-banner modo-' . $modo . '">';
echo $modo === 'executar'
    ? '&#x26A0;&#xFE0F; MODO EXECUTAR — vai persistir tudo no banco. SEM volta atras.'
    : '&#x1F4CB; MODO SIMULAR — nada vai pro banco. Use &amp;modo=executar para aplicar.';
echo '</div>';

// ============================================================================
// 51 REGISTROS NORMALIZADOS (planilha 27/05/2026)
// ============================================================================
$REGISTROS = json_decode(<<<'JSON'
[
  {"cliente_nome":"Andrezza Alves da Silva de Oliveira","demanda_original":"Auxílio Maternidade","especie_normalizada":"salario_maternidade","codigo_b":"B80","fase":"adm","status_original":"FINALIZADO","status_normalizado":"finalizado","observacoes":"Indeferido.","profissional":"Simone"},
  {"cliente_nome":"Bruna Costa de Asevedo","demanda_original":"Auxílio Maternidade","especie_normalizada":"salario_maternidade","codigo_b":"B80","fase":"adm","status_original":"FINALIZADO","status_normalizado":"finalizado","observacoes":"Sem direito","profissional":"Simone"},
  {"cliente_nome":"Diego Souza Villarinho","demanda_original":"Benefício por incapacidade","especie_normalizada":"beneficio_incapacidade","codigo_b":"B31","fase":"adm","status_original":"FINALIZADO","status_normalizado":"finalizado","observacoes":"ÊXITO.","profissional":"Simone"},
  {"cliente_nome":"Eliane Rosalina","demanda_original":"LOAS Felipe","especie_normalizada":"bpc_loas","codigo_b":"B87","fase":"adm","status_original":"FINALIZADO","status_normalizado":"finalizado","observacoes":"INDEFERIDO, CONFORME PARECER TÉCNICO.","profissional":"Simone"},
  {"cliente_nome":"Gabriela Cristina de Oliveira Gonçalves","demanda_original":"Auxílio Maternidade","especie_normalizada":"salario_maternidade","codigo_b":"B80","fase":"adm","status_original":"FINALIZADO","status_normalizado":"finalizado","observacoes":"Indeferido. Cad desatualizado na data da contribuição.","profissional":"Simone"},
  {"cliente_nome":"Jose Herickson","demanda_original":"Benefício por incapacidade ADM","especie_normalizada":"beneficio_incapacidade","codigo_b":"B31","fase":"adm","status_original":"FINALIZADO","status_normalizado":"finalizado","observacoes":"NÃO COMPARECEU À PERÍCIA.","profissional":"Simone"},
  {"cliente_nome":"Liliane Barranco de Oliveira","demanda_original":"Auxílio Maternidade","especie_normalizada":"salario_maternidade","codigo_b":"B80","fase":"adm","status_original":"FINALIZADO","status_normalizado":"finalizado","observacoes":"Demitida","profissional":"Simone"},
  {"cliente_nome":"Lucilene Nogueira da Silva","demanda_original":"Auxílio Maternidade","especie_normalizada":"salario_maternidade","codigo_b":"B80","fase":"adm","status_original":"FINALIZADO","status_normalizado":"finalizado","observacoes":"Demitida","profissional":"Simone"},
  {"cliente_nome":"Milena Freitas Silva","demanda_original":"Auxílio Maternidade","especie_normalizada":"salario_maternidade","codigo_b":"B80","fase":"adm","status_original":"FINALIZADO","status_normalizado":"finalizado","observacoes":"Indeferido. Trabalhista.","profissional":"Simone"},
  {"cliente_nome":"Monique de Souza Mano Haubrich","demanda_original":"Auxílio Maternidade","especie_normalizada":"salario_maternidade","codigo_b":"B80","fase":"adm","status_original":"FINALIZADO","status_normalizado":"finalizado","observacoes":"Sem direito","profissional":"Simone"},
  {"cliente_nome":"Monique Elaine Oliveira Mateus","demanda_original":"LOAS Marcela","especie_normalizada":"bpc_loas","codigo_b":"B87","fase":"adm","status_original":"FINALIZADO","status_normalizado":"finalizado","observacoes":"INDEFERIDO. SEM CADASTRO BIOMÉTRICO.","profissional":"Simone"},
  {"cliente_nome":"Rafaella de Lima Benedito","demanda_original":"Auxílio Maternidade","especie_normalizada":"salario_maternidade","codigo_b":"B80","fase":"adm","status_original":"FINALIZADO","status_normalizado":"finalizado","observacoes":"Indeferido / Sem direito","profissional":"Simone"},
  {"cliente_nome":"Raiane dos Santos Sá Braga","demanda_original":"Auxílio Maternidade","especie_normalizada":"salario_maternidade","codigo_b":"B80","fase":"adm","status_original":"FINALIZADO","status_normalizado":"finalizado","observacoes":"ÊXITO","profissional":"Simone"},
  {"cliente_nome":"Valéria de Souza Falcão","demanda_original":"INSS","especie_normalizada":"inss_generico","codigo_b":null,"fase":"adm","status_original":"FINALIZADO","status_normalizado":"finalizado","observacoes":"ÊXITO","profissional":"Simone"},
  {"cliente_nome":"Dalízio Antonio Rezende Costa","demanda_original":"Aposentadoria","especie_normalizada":"aposentadoria","codigo_b":null,"fase":"adm","status_original":"CANCELADO / MONITORAR","status_normalizado":"cancelado_monitorar","observacoes":"Junho/2026","profissional":"Simone"},
  {"cliente_nome":"Danilo Curvelo do Nascimento","demanda_original":"Auxílio Doença","especie_normalizada":"auxilio_doenca","codigo_b":"B31","fase":"adm","status_original":"CANCELADO / MONITORAR","status_normalizado":"cancelado_monitorar","observacoes":"Ausência de documentos","profissional":"Simone"},
  {"cliente_nome":"Ana Cláudia dos Santos (Asllan)","demanda_original":"Pensão por morte","especie_normalizada":"pensao_por_morte","codigo_b":"B21","fase":"adm","status_original":"EM ANDAMENTO","status_normalizado":"em_andamento","observacoes":"","profissional":"Simone"},
  {"cliente_nome":"Ana Paula Bernardo dos Reis","demanda_original":"Auxílio Doença","especie_normalizada":"auxilio_doenca","codigo_b":"B31","fase":"adm","status_original":"FINALIZADO","status_normalizado":"finalizado","observacoes":"ÊXITO. Aguardando implementação. Benefício concedido de março a outubro/2026 - deixar no radar para implementação.","profissional":"Simone"},
  {"cliente_nome":"Anderson de Oliveira Silva","demanda_original":"Benefício por incapacidade","especie_normalizada":"beneficio_incapacidade","codigo_b":"B31","fase":"adm","status_original":"EM ANDAMENTO","status_normalizado":"em_andamento","observacoes":"AG. PERÍCIA EM JUNHO.","profissional":"Simone"},
  {"cliente_nome":"CASSIANE DIAS DE SOUZA","demanda_original":"LOAS","especie_normalizada":"bpc_loas","codigo_b":"B87","fase":"adm","status_original":"CANCELADO / MONITORAR","status_normalizado":"cancelado_monitorar","observacoes":"Demitida / sem retorno.","profissional":"Simone"},
  {"cliente_nome":"Dalízio Antonio Rezende Costa","demanda_original":"Auxílio Doença","especie_normalizada":"auxilio_doenca","codigo_b":"B31","fase":"adm","status_original":"FINALIZADO","status_normalizado":"finalizado","observacoes":"NÃO COMPARECEU À PERÍCIA.","profissional":"Simone"},
  {"cliente_nome":"DAYANA CABRAL FERNANDES","demanda_original":"LOAS (Clara)","especie_normalizada":"bpc_loas","codigo_b":"B87","fase":"adm","status_original":"FINALIZADO","status_normalizado":"finalizado","observacoes":"ÊXITO. Aguardando implementação.","profissional":"Simone"},
  {"cliente_nome":"Edilaine Ferreira Alves","demanda_original":"Auxílio Maternidade","especie_normalizada":"salario_maternidade","codigo_b":"B80","fase":"adm","status_original":"FINALIZADO","status_normalizado":"finalizado","observacoes":"ÊXITO.","profissional":"Simone"},
  {"cliente_nome":"Joice Correa Cezario","demanda_original":"Conversão Auxílio Doença","especie_normalizada":"auxilio_doenca_conversao","codigo_b":"B31","fase":"adm","status_original":"EM ANDAMENTO","status_normalizado":"em_andamento","observacoes":"ADM.","profissional":"Simone"},
  {"cliente_nome":"Jose Herickson","demanda_original":"Benefício por incapacidade JUD","especie_normalizada":"beneficio_incapacidade","codigo_b":"B31","fase":"jud","status_original":"EM ANDAMENTO","status_normalizado":"em_andamento","observacoes":"Perícia Realizada","profissional":"Simone"},
  {"cliente_nome":"José Vicente Falcão","demanda_original":"Aposentadoria","especie_normalizada":"aposentadoria","codigo_b":null,"fase":"adm","status_original":"EM ANDAMENTO","status_normalizado":"em_andamento","observacoes":"AG. CONTESTAÇÃO.","profissional":"Simone"},
  {"cliente_nome":"Luciana","demanda_original":"BPC Kaio","especie_normalizada":"bpc_loas","codigo_b":"B87","fase":"adm","status_original":"EM ANDAMENTO","status_normalizado":"em_andamento","observacoes":"2° requerimento","profissional":"Luiz Eduardo"},
  {"cliente_nome":"MARIA APARECIDA DE LIMA CASSIMIRO","demanda_original":"PM","especie_normalizada":"pensao_por_morte","codigo_b":"B21","fase":"adm","status_original":"EM ANDAMENTO","status_normalizado":"em_andamento","observacoes":"EXIGÊNCIA ABERTA. AG RETORNO DA CLIENTE.","profissional":"Simone"},
  {"cliente_nome":"Maria Gilandia Barbosa Gomes","demanda_original":"INSS","especie_normalizada":"inss_generico","codigo_b":null,"fase":"adm","status_original":"EM ANDAMENTO","status_normalizado":"em_andamento","observacoes":"ÊXITO ADM. JUDICIAL EM ANDAMENTO.","profissional":"Simone"},
  {"cliente_nome":"Miguel Justo da Silva","demanda_original":"INSS","especie_normalizada":"inss_generico","codigo_b":null,"fase":"adm","status_original":"EM ANDAMENTO","status_normalizado":"em_andamento","observacoes":"AG. CONTESTAÇÃO.","profissional":"Simone"},
  {"cliente_nome":"Regina Célia Caetano Alves","demanda_original":"Conversão de AD","especie_normalizada":"auxilio_doenca_conversao","codigo_b":"B31","fase":"adm","status_original":"EM ANDAMENTO","status_normalizado":"em_andamento","observacoes":"","profissional":"Simone"},
  {"cliente_nome":"Regina Célia Caetano Alves","demanda_original":"Prorrogação Auxílio Doença","especie_normalizada":"auxilio_doenca_prorrogacao","codigo_b":"B31","fase":"adm","status_original":"EM ANDAMENTO","status_normalizado":"em_andamento","observacoes":"","profissional":"Simone"},
  {"cliente_nome":"Veronica da Costa Nunes","demanda_original":"Auxílio Doença","especie_normalizada":"auxilio_doenca","codigo_b":"B31","fase":"adm","status_original":"EM ANDAMENTO","status_normalizado":"em_andamento","observacoes":"JUD.","profissional":"Simone"},
  {"cliente_nome":"Elaine Cristina Bilha","demanda_original":"Auxílio Doença","especie_normalizada":"auxilio_doenca","codigo_b":"B31","fase":"adm","status_original":"AGUARDANDO OPERAÇÃO","status_normalizado":"aguardando_operacao","observacoes":"","profissional":"Simone"},
  {"cliente_nome":"Elaine Cristina Bilha","demanda_original":"Conversão de AD","especie_normalizada":"auxilio_doenca_conversao","codigo_b":"B31","fase":"adm","status_original":"AGUARDANDO OPERAÇÃO","status_normalizado":"aguardando_operacao","observacoes":"Aguardando resultado da perícia acima.","profissional":"Simone"},
  {"cliente_nome":"GUSTAVO PEREIRA DA SILVA DO ROSARIO","demanda_original":"Auxílio Doença","especie_normalizada":"auxilio_doenca","codigo_b":"B31","fase":"adm","status_original":"FINALIZADO","status_normalizado":"finalizado","observacoes":"Indeferido. Laudos de 2024.","profissional":"Simone"},
  {"cliente_nome":"Joice Correa Cezario","demanda_original":"Prorrogação Auxílio Doença","especie_normalizada":"auxilio_doenca_prorrogacao","codigo_b":"B31","fase":"adm","status_original":"AGUARDANDO OPERAÇÃO","status_normalizado":"aguardando_operacao","observacoes":"","profissional":"Simone"},
  {"cliente_nome":"Luciana Berteges","demanda_original":"Auxílio Doença","especie_normalizada":"auxilio_doenca","codigo_b":"B31","fase":"adm","status_original":"CANCELADO / MONITORAR","status_normalizado":"cancelado_monitorar","observacoes":"cliente pediu cancelamento após morosidade interna.","profissional":"Simone"},
  {"cliente_nome":"Monique Elaine Oliveira Mateus","demanda_original":"LOAS","especie_normalizada":"bpc_loas","codigo_b":"B87","fase":"adm","status_original":"AGUARDANDO OPERAÇÃO","status_normalizado":"aguardando_operacao","observacoes":"Aguardando laudos recentes","profissional":"Simone"},
  {"cliente_nome":"GISELE SILVA MACHADO AMARO CARDOZO","demanda_original":"LOAS","especie_normalizada":"bpc_loas","codigo_b":"B87","fase":"adm","status_original":"EM ANDAMENTO","status_normalizado":"em_andamento","observacoes":"","profissional":"Luiz Eduardo"},
  {"cliente_nome":"THAYNA DE CASTRO DA SILVA","demanda_original":"Auxílio Maternidade","especie_normalizada":"salario_maternidade","codigo_b":"B80","fase":"adm","status_original":"AGUARDANDO OPERAÇÃO","status_normalizado":"aguardando_operacao","observacoes":"Gestante - Parto previsto para julho - Precisa fazer a contribuição","profissional":"Luiz Eduardo"},
  {"cliente_nome":"Beatriz Elias de Peñaranda","demanda_original":"LOAS","especie_normalizada":"bpc_loas","codigo_b":"B87","fase":"adm","status_original":"AGUARDANDO OPERAÇÃO","status_normalizado":"aguardando_operacao","observacoes":"Com parceiro(a)","profissional":"Rejane"},
  {"cliente_nome":"Jorge Antônio Peñaranda Panda","demanda_original":"LOAS","especie_normalizada":"bpc_loas","codigo_b":"B87","fase":"adm","status_original":"AGUARDANDO OPERAÇÃO","status_normalizado":"aguardando_operacao","observacoes":"Com parceiro(a)","profissional":"Rejane"},
  {"cliente_nome":"Enayle Garcia Fontes","demanda_original":"PM","especie_normalizada":"pensao_por_morte","codigo_b":"B21","fase":"adm","status_original":"EM ANDAMENTO","status_normalizado":"em_andamento","observacoes":"Judicial - 5000781-30.2026.4.02.5109","profissional":"Luiz Eduardo"},
  {"cliente_nome":"Nilceia Henrique de Andrade Marchiori","demanda_original":"AIT conversão para Aposentadoria Invalidez","especie_normalizada":"aposentadoria_invalidez_conversao","codigo_b":"B32","fase":"jud","status_original":"EM ANDAMENTO","status_normalizado":"em_andamento","observacoes":"Judicial - 5000933-78.2026.4.02.5109","profissional":"Luiz Eduardo"},
  {"cliente_nome":"Deise Cristina Ramos de Oliveira","demanda_original":"Auxílio Maternidade","especie_normalizada":"salario_maternidade","codigo_b":"B80","fase":"adm","status_original":"EM ANDAMENTO","status_normalizado":"em_andamento","observacoes":"Recurso Administrativo","profissional":"Luiz Eduardo"},
  {"cliente_nome":"Joselaine da Silva de Oliveira","demanda_original":"Auxílio Maternidade","especie_normalizada":"salario_maternidade","codigo_b":"B80","fase":"adm","status_original":"EM ANDAMENTO","status_normalizado":"em_andamento","observacoes":"Via Adm","profissional":"Luiz Eduardo"},
  {"cliente_nome":"SILZY FRANCY AMARO DE SOUZA RAMOS","demanda_original":"Auxílio Maternidade","especie_normalizada":"salario_maternidade","codigo_b":"B80","fase":"adm","status_original":"EM ANDAMENTO","status_normalizado":"em_andamento","observacoes":"Via Adm","profissional":"Luiz Eduardo"},
  {"cliente_nome":"Natan Francisco Dourado","demanda_original":"Aposentadoria","especie_normalizada":"aposentadoria","codigo_b":null,"fase":"adm","status_original":"EM ANDAMENTO","status_normalizado":"em_andamento","observacoes":"Via Adm","profissional":"Luiz Eduardo"},
  {"cliente_nome":"SUELEN RIBEIRO DA SILVA","demanda_original":"INDENIZAÇÃO - MICROCEFALIA","especie_normalizada":"pensao_zika_lei_15156","codigo_b":"B87","fase":"adm","status_original":"AGUARDANDO OPERAÇÃO","status_normalizado":"aguardando_operacao","observacoes":"Aguardando processo judicial da vara de família - Requerimento pronto na pasta","profissional":"Luiz Eduardo"}
]
JSON
, true);

echo '<p><strong>' . count($REGISTROS) . ' registros</strong> na planilha.</p>';

// ============================================================================
// HELPERS
// ============================================================================

function _normalizar_nome($s) {
    $s = mb_strtolower(trim((string)$s), 'UTF-8');
    $tr = array(
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n',
    );
    $s = strtr($s, $tr);
    // Remove parenteses "(Asllan)" e tudo dentro
    $s = preg_replace('/\([^)]*\)/', '', $s);
    // Espaços duplos -> simples
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

// Carrega clientes uma vez na memoria
$clientesCache = $pdo->query("SELECT id, name FROM clients")->fetchAll();
$mapNomeNorm = array();           // nome_normalizado => [client_id, ...]
$mapPrimUlt = array();             // primeiro+ultimo nome => [client_id, ...]  (fallback fuzzy)
foreach ($clientesCache as $c) {
    $n = _normalizar_nome($c['name']);
    if (!isset($mapNomeNorm[$n])) $mapNomeNorm[$n] = array();
    $mapNomeNorm[$n][] = (int)$c['id'];

    $partes = preg_split('/\s+/', $n);
    if (count($partes) >= 2) {
        $primUlt = $partes[0] . ' ' . end($partes);
        if (!isset($mapPrimUlt[$primUlt])) $mapPrimUlt[$primUlt] = array();
        $mapPrimUlt[$primUlt][] = (int)$c['id'];
    }
}

// Mapeamento profissional -> user_id (busca por primeiro nome)
$profMap = array();
foreach (array('Simone', 'Luiz Eduardo', 'Luiz', 'Rejane') as $p) {
    try {
        $u = $pdo->prepare("SELECT id FROM users WHERE is_active=1 AND name LIKE ? ORDER BY id LIMIT 1");
        $u->execute(array($p . '%'));
        $uid = (int)$u->fetchColumn();
        if ($uid) $profMap[$p] = $uid;
    } catch (Exception $e) {}
}
echo '<div class="info">Profissionais resolvidos: ' . htmlspecialchars(json_encode($profMap)) . '</div>';

// Mapa status_normalizado -> cases.status + outras flags
function _mapear_status($statusNorm, $obs) {
    $obsLower = mb_strtolower((string)$obs);
    $hasExito = strpos($obsLower, 'êxito') !== false || strpos($obsLower, 'exito') !== false;
    $hasIndef = strpos($obsLower, 'indeferid') !== false || strpos($obsLower, 'sem direito') !== false || strpos($obsLower, 'demitid') !== false;
    $hasFalta = strpos($obsLower, 'não compareceu à perícia') !== false || strpos($obsLower, 'nao compareceu') !== false;
    $hasAgImpl = strpos($obsLower, 'aguardando implement') !== false || strpos($obsLower, 'aguardando implant') !== false;

    switch ($statusNorm) {
        case 'finalizado':
            $result = array(
                'cases_status' => 'concluido',
                'resultado_adm' => $hasExito ? 'deferido' : ($hasIndef || $hasFalta ? 'indeferido' : 'pendente'),
                'monitorar_radar' => $hasAgImpl ? 1 : 0,
                'kanban_oculto' => $hasAgImpl ? 0 : 0, // mantem visivel se aguardando impl
                'falta_pericia' => $hasFalta,
            );
            break;
        case 'cancelado_monitorar':
            $result = array(
                'cases_status' => 'cancelado',
                'resultado_adm' => 'pendente',
                'monitorar_radar' => 1,
                'kanban_oculto' => 0,
                'falta_pericia' => false,
            );
            break;
        case 'aguardando_operacao':
        case 'em_andamento':
        default:
            $result = array(
                'cases_status' => 'em_andamento',
                'resultado_adm' => 'pendente',
                'monitorar_radar' => 0,
                'kanban_oculto' => 0,
                'falta_pericia' => false,
            );
            break;
    }
    return $result;
}

// Detecta CNJ na observacao
function _extrair_cnj($obs) {
    if (preg_match('/(\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4})/', (string)$obs, $m)) {
        return $m[1];
    }
    return null;
}

// ============================================================================
// PROCESSAMENTO
// ============================================================================

$processar = array(
    'match_unico'    => array(),
    'sem_cliente'    => array(),
    'ambiguo'        => array(),
);

foreach ($REGISTROS as $idx => $reg) {
    $nomeNorm = _normalizar_nome($reg['cliente_nome']);
    $candidatos = isset($mapNomeNorm[$nomeNorm]) ? $mapNomeNorm[$nomeNorm] : array();
    $matchTipo = 'exato';

    // Fallback fuzzy: primeiro + ultimo nome
    if (empty($candidatos)) {
        $partes = preg_split('/\s+/', $nomeNorm);
        if (count($partes) >= 2) {
            $chaveFuzzy = $partes[0] . ' ' . end($partes);
            if (isset($mapPrimUlt[$chaveFuzzy])) {
                $candidatos = $mapPrimUlt[$chaveFuzzy];
                $matchTipo = 'fuzzy_prim_ult';
            }
        }
    }

    $reg['_idx'] = $idx;
    $reg['_nome_norm'] = $nomeNorm;
    $reg['_candidatos'] = $candidatos;
    $reg['_match_tipo'] = $matchTipo;
    $reg['_cnj'] = _extrair_cnj($reg['observacoes']);
    $reg['_status_map'] = _mapear_status($reg['status_normalizado'], $reg['observacoes']);

    if (count($candidatos) === 1) {
        $reg['_client_id'] = $candidatos[0];
        $processar['match_unico'][] = $reg;
    } elseif (count($candidatos) > 1) {
        $processar['ambiguo'][] = $reg;
    } else {
        $processar['sem_cliente'][] = $reg;
    }
}

echo '<h2>RESUMO</h2>';
echo '<div class="ok">';
echo '<strong>Match unico:</strong> ' . count($processar['match_unico']) . ' (vai criar cases + cases_previdenciario)<br>';
echo '<strong>Ambiguos:</strong> ' . count($processar['ambiguo']) . ' (vai pra prev_migracao_pendente)<br>';
echo '<strong>Sem cliente cadastrado:</strong> ' . count($processar['sem_cliente']) . ' (vai pra prev_migracao_pendente)';
echo '</div>';

// ============================================================================
// EXECUCAO (so se modo=executar)
// ============================================================================

if ($modo === 'executar') {
    $criados = 0; $pendentes = 0; $erros = 0;
    $pdo->beginTransaction();
    try {
        foreach ($processar['match_unico'] as $reg) {
            $sm = $reg['_status_map'];
            $clientId = (int)$reg['_client_id'];
            $titulo = trim($reg['demanda_original']);
            $respId = $profMap[$reg['profissional']] ?? null;

            // Rejane = parceira externa (sem user no Hub) -> case marcado is_parceria=1
            $ehParceriaRejane = (mb_strtolower($reg['profissional']) === 'rejane');

            // 1) INSERT cases
            $pdo->prepare(
                "INSERT INTO cases
                  (client_id, title, case_type, category, status, kanban_prev, kanban_oculto,
                   responsible_user_id, case_number, opened_at, created_at, notes,
                   is_parceria, parceria_executor)
                 VALUES (?, ?, 'previdenciario', 'previdenciario', ?, 1, ?, ?, ?, CURDATE(), NOW(), ?, ?, ?)"
            )->execute(array(
                $clientId, $titulo,
                $sm['cases_status'], $sm['kanban_oculto'],
                $respId, $reg['_cnj'],
                "Importado da planilha em " . date('d/m/Y') . ". Observação original: " . $reg['observacoes'],
                $ehParceriaRejane ? 1 : 0,
                $ehParceriaRejane ? 'Rejane (parceira externa de PREV)' : null
            ));
            $caseId = (int)$pdo->lastInsertId();

            // 2) INSERT cases_previdenciario
            $pdo->prepare(
                "INSERT INTO cases_previdenciario
                  (case_id, especie, codigo_b, fase, resultado_adm, monitorar_radar, radar_observacao, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute(array(
                $caseId,
                $reg['especie_normalizada'],
                $reg['codigo_b'],
                $reg['fase'],
                $sm['resultado_adm'],
                $sm['monitorar_radar'],
                $sm['monitorar_radar'] ? trim($reg['observacoes']) : null,
                $assumirAmanda,
            ));

            // 3) Andamento (observacoes -> case_andamentos)
            if (!empty($reg['observacoes'])) {
                try {
                    $pdo->prepare(
                        "INSERT INTO case_andamentos (case_id, data_andamento, descricao, tipo_origem, visivel_cliente, created_at)
                         VALUES (?, CURDATE(), ?, 'migracao_planilha', 0, NOW())"
                    )->execute(array($caseId, '[Importado da planilha 27/05/2026] ' . $reg['observacoes']));
                } catch (Exception $e) { /* nao bloqueia */ }
            }

            // 4) Se obs indica "não compareceu à pericia", cria registro em prev_pericias
            if (!empty($sm['falta_pericia'])) {
                try {
                    $pdo->prepare(
                        "INSERT INTO prev_pericias (case_id, tipo, data_realizada, cliente_compareceu, motivo_falta, resultado, observacoes, created_by)
                         VALUES (?, 'medica_adm', NULL, 0, 'Cliente nao compareceu (importado da planilha)', 'aguardando', ?, ?)"
                    )->execute(array($caseId, $reg['observacoes'], $assumirAmanda));
                } catch (Exception $e) {}
            }

            // 5) Se obs menciona EXIGÊNCIA ABERTA, cria exigencia
            if (stripos($reg['observacoes'], 'exigência aberta') !== false || stripos($reg['observacoes'], 'exigencia aberta') !== false) {
                try {
                    $pdo->prepare(
                        "INSERT INTO prev_exigencias (case_id, data_abertura, descricao, status, aguardando_cliente, created_by)
                         VALUES (?, CURDATE(), ?, 'aberta', 1, ?)"
                    )->execute(array($caseId, $reg['observacoes'], $assumirAmanda));
                } catch (Exception $e) {}
            }

            $criados++;
        }

        // Pendentes
        foreach ($processar['sem_cliente'] as $reg) {
            $pdo->prepare(
                "INSERT INTO prev_migracao_pendente
                  (cliente_nome, demanda_original, especie_normalizada, codigo_b, fase,
                   status_normalizado, observacoes, profissional, motivo_pendencia)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'sem_cliente')"
            )->execute(array(
                $reg['cliente_nome'], $reg['demanda_original'], $reg['especie_normalizada'],
                $reg['codigo_b'], $reg['fase'], $reg['status_normalizado'],
                $reg['observacoes'], $reg['profissional']
            ));
            $pendentes++;
        }

        foreach ($processar['ambiguo'] as $reg) {
            $pdo->prepare(
                "INSERT INTO prev_migracao_pendente
                  (cliente_nome, demanda_original, especie_normalizada, codigo_b, fase,
                   status_normalizado, observacoes, profissional, motivo_pendencia, candidatos_client_ids)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'cliente_ambiguo', ?)"
            )->execute(array(
                $reg['cliente_nome'], $reg['demanda_original'], $reg['especie_normalizada'],
                $reg['codigo_b'], $reg['fase'], $reg['status_normalizado'],
                $reg['observacoes'], $reg['profissional'],
                implode(',', $reg['_candidatos'])
            ));
            $pendentes++;
        }

        $pdo->commit();
        _audit_safe('PREV_MIGRACAO_EXECUTADA', 'cases', null, "Migracao planilha 27/05/2026: $criados cases criados, $pendentes pendencias");
        echo '<div class="ok"><strong>&#x2705; EXECUTADO!</strong> ' . $criados . ' cases criados, ' . $pendentes . ' pendencias em prev_migracao_pendente.</div>';
    } catch (Exception $e) {
        $pdo->rollBack();
        echo '<div class="erro">ERRO: ' . htmlspecialchars($e->getMessage()) . ' — rollback completo, nada foi gravado.</div>';
        exit;
    }
}

// ============================================================================
// LISTAGENS
// ============================================================================

echo '<h2>1) Match unico (' . count($processar['match_unico']) . ')</h2>';
echo '<table><tr><th>Cliente</th><th>client_id</th><th>Demanda</th><th>Especie / B</th><th>Fase</th><th>Status</th><th>Obs (snippet)</th><th>Resp.</th></tr>';
foreach ($processar['match_unico'] as $reg) {
    $cnjBadge = $reg['_cnj'] ? ' <code>' . htmlspecialchars($reg['_cnj']) . '</code>' : '';
    $matchBadge = $reg['_match_tipo'] === 'fuzzy_prim_ult' ? ' <span style="background:#fef3c7;color:#92400e;padding:1px 4px;border-radius:3px;font-size:.65rem;">fuzzy</span>' : '';
    echo '<tr>';
    echo '<td class="match-ok">' . htmlspecialchars($reg['cliente_nome']) . $matchBadge . '</td>';
    echo '<td><code>#' . $reg['_client_id'] . '</code></td>';
    echo '<td>' . htmlspecialchars($reg['demanda_original']) . $cnjBadge . '</td>';
    echo '<td>' . htmlspecialchars($reg['especie_normalizada']) . ($reg['codigo_b'] ? ' / <strong>' . $reg['codigo_b'] . '</strong>' : '') . '</td>';
    echo '<td>' . htmlspecialchars($reg['fase']) . '</td>';
    echo '<td>' . htmlspecialchars($reg['_status_map']['cases_status']) . ' / ' . htmlspecialchars($reg['_status_map']['resultado_adm']);
    if ($reg['_status_map']['monitorar_radar']) echo ' <span style="background:#fef3c7;padding:1px 5px;border-radius:3px;font-size:.7rem;">RADAR</span>';
    echo '</td>';
    echo '<td>' . htmlspecialchars(mb_substr($reg['observacoes'], 0, 60)) . '</td>';
    echo '<td>' . htmlspecialchars($reg['profissional']) . '</td>';
    echo '</tr>';
}
echo '</table>';

if (!empty($processar['ambiguo'])) {
    echo '<h2>2) Cliente ambiguo (multiplos matches) — ' . count($processar['ambiguo']) . '</h2>';
    echo '<table><tr><th>Cliente da planilha</th><th>Candidatos</th><th>Demanda</th><th>Obs</th></tr>';
    foreach ($processar['ambiguo'] as $reg) {
        echo '<tr>';
        echo '<td class="match-amb">' . htmlspecialchars($reg['cliente_nome']) . '</td>';
        echo '<td><code>' . implode(', ', array_map(function($id){ return '#'.$id; }, $reg['_candidatos'])) . '</code></td>';
        echo '<td>' . htmlspecialchars($reg['demanda_original']) . '</td>';
        echo '<td>' . htmlspecialchars(mb_substr($reg['observacoes'], 0, 70)) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
}

if (!empty($processar['sem_cliente'])) {
    echo '<h2>3) Sem cliente cadastrado — ' . count($processar['sem_cliente']) . '</h2>';
    echo '<table><tr><th>Cliente da planilha</th><th>Demanda</th><th>Obs</th><th>Resp.</th></tr>';
    foreach ($processar['sem_cliente'] as $reg) {
        echo '<tr>';
        echo '<td class="match-fail">' . htmlspecialchars($reg['cliente_nome']) . '</td>';
        echo '<td>' . htmlspecialchars($reg['demanda_original']) . '</td>';
        echo '<td>' . htmlspecialchars(mb_substr($reg['observacoes'], 0, 70)) . '</td>';
        echo '<td>' . htmlspecialchars($reg['profissional']) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
}

if ($modo === 'simular') {
    echo '<h2>Proximo passo</h2>';
    echo '<div class="warn">Conferiu os matches acima? Se OK, rode com <code>?modo=executar</code> pra aplicar de verdade. Os pendentes ficam em <code>prev_migracao_pendente</code> pra revisao manual depois.</div>';
}
