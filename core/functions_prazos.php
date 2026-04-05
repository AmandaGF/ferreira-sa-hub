<?php
/**
 * Ferreira & Sá Conecta — Calculadora de Prazos Processuais
 *
 * Regras:
 * 1. Disponibilização (D) → Publicação (D+1 útil) → Início contagem (D+2 útil)
 * 2. Dias úteis = exclui sábados, domingos e suspensões (feriados, recesso, etc.)
 * 3. Suspensões podem ser por comarca específica ou todo o estado
 * 4. Data fatal em dia não útil → avança para próximo dia útil
 * 5. Meses = soma calendário, se cair em não útil avança
 */

/**
 * Verifica se uma data é dia suspenso (feriado/recesso/suspensão)
 */
function is_dia_suspenso($data, $comarca = null)
{
    static $cache = array();
    $key = $data . '|' . ($comarca ?: '_');
    if (isset($cache[$key])) return $cache[$key];

    $pdo = db();
    $sql = "SELECT COUNT(*) FROM prazos_suspensoes
            WHERE ? BETWEEN data_inicio AND data_fim
            AND (abrangencia = 'todo_estado' OR abrangencia = 'capital'";
    $params = array($data);

    if ($comarca) {
        $sql .= " OR (abrangencia = 'comarca_especifica' AND comarca = ?)";
        $params[] = $comarca;
    }
    $sql .= ")";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result = (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        $result = false;
    }

    $cache[$key] = $result;
    return $result;
}

/**
 * Verifica se uma data é dia útil forense
 */
function is_dia_util($data, $comarca = null)
{
    $dt = new DateTime($data);
    $dow = (int)$dt->format('w');
    // Sábado (6) e Domingo (0)
    if ($dow === 0 || $dow === 6) return false;
    // Verificar suspensões
    return !is_dia_suspenso($data, $comarca);
}

/**
 * Avança para o próximo dia útil (se já for útil, retorna o mesmo)
 */
function proximo_dia_util($data, $comarca = null)
{
    $dt = new DateTime($data);
    $max = 60; $i = 0;
    while (!is_dia_util($dt->format('Y-m-d'), $comarca) && $i < $max) {
        $dt->modify('+1 day');
        $i++;
    }
    return $dt->format('Y-m-d');
}

/**
 * Calcula data de publicação (D+1, se útil; senão próximo útil)
 */
function calcular_data_publicacao($data_disponibilizacao)
{
    $pub = new DateTime($data_disponibilizacao);
    $pub->modify('+1 day');
    return proximo_dia_util($pub->format('Y-m-d'));
}

/**
 * Calcula data de início da contagem (primeiro dia útil após publicação)
 */
function calcular_inicio_contagem($data_publicacao)
{
    $inicio = new DateTime($data_publicacao);
    $inicio->modify('+1 day');
    return proximo_dia_util($inicio->format('Y-m-d'));
}

/**
 * Calcula prazo em DIAS ÚTEIS
 */
function calcular_prazo_dias($data_inicio, $quantidade, $comarca = null)
{
    $atual = new DateTime($data_inicio);
    $dias_contados = 0;
    $max = 500; $i = 0;

    while ($dias_contados < $quantidade && $i < $max) {
        $atual->modify('+1 day');
        $i++;
        if (is_dia_util($atual->format('Y-m-d'), $comarca)) {
            $dias_contados++;
        }
    }

    return $atual->format('Y-m-d');
}

/**
 * Calcula prazo em MESES (calendário, avança se cair em não útil)
 */
function calcular_prazo_meses($data_inicio, $quantidade, $comarca = null)
{
    $fatal = new DateTime($data_inicio);
    $fatal->modify("+{$quantidade} months");
    return proximo_dia_util($fatal->format('Y-m-d'), $comarca);
}

/**
 * Cálculo completo: disponibilização → data fatal
 */
function calcular_prazo_completo($data_disponibilizacao, $quantidade, $unidade = 'dias', $comarca = null)
{
    $publicacao = calcular_data_publicacao($data_disponibilizacao);
    $inicio = calcular_inicio_contagem($publicacao);

    if ($unidade === 'meses') {
        $fatal = calcular_prazo_meses($inicio, $quantidade, $comarca);
    } else {
        $fatal = calcular_prazo_dias($inicio, $quantidade, $comarca);
    }

    // Contar dias suspensos no período
    $suspensoes = get_suspensoes_periodo($inicio, $fatal, $comarca);

    // Dias corridos e úteis até o prazo
    $hoje = new DateTime();
    $fatalDt = new DateTime($fatal);
    $diasAte = (int)$hoje->diff($fatalDt)->format('%r%a');

    return array(
        'disponibilizacao' => $data_disponibilizacao,
        'publicacao'       => $publicacao,
        'inicio_contagem'  => $inicio,
        'quantidade'       => $quantidade,
        'unidade'          => $unidade,
        'comarca'          => $comarca,
        'data_fatal'       => $fatal,
        'dia_semana_fatal' => _dia_semana_pt($fatal),
        'dias_ate_prazo'   => $diasAte,
        'suspensoes'       => $suspensoes,
    );
}

/**
 * Retorna suspensões que afetam um período
 */
function get_suspensoes_periodo($data_inicio, $data_fim, $comarca = null)
{
    $pdo = db();
    $sql = "SELECT * FROM prazos_suspensoes
            WHERE data_inicio <= ? AND data_fim >= ?
            AND (abrangencia = 'todo_estado' OR abrangencia = 'capital'";
    $params = array($data_fim, $data_inicio);

    if ($comarca) {
        $sql .= " OR (abrangencia = 'comarca_especifica' AND comarca = ?)";
        $params[] = $comarca;
    }
    $sql .= ") ORDER BY data_inicio";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return array();
    }
}

/**
 * Retorna todos os dias suspensos expandidos no período (para calendário)
 */
function get_dias_suspensos_expandidos($data_inicio, $data_fim, $comarca = null)
{
    $suspensoes = get_suspensoes_periodo($data_inicio, $data_fim, $comarca);
    $dias = array();

    foreach ($suspensoes as $s) {
        $dt = new DateTime($s['data_inicio']);
        $fimDt = new DateTime($s['data_fim']);
        while ($dt <= $fimDt) {
            $d = $dt->format('Y-m-d');
            if ($d >= $data_inicio && $d <= $data_fim) {
                $dias[$d] = $s['motivo'];
            }
            $dt->modify('+1 day');
        }
    }

    return $dias;
}

/**
 * Dia da semana em português
 */
function _dia_semana_pt($data)
{
    $dias = array('Domingo','Segunda-feira','Terca-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sabado');
    $dt = new DateTime($data);
    return $dias[(int)$dt->format('w')];
}

/**
 * Lista de comarcas do RJ
 */
function comarcas_rj()
{
    return array(
        'Angra dos Reis','Araruama','Arraial do Cabo','Barra do Pirai','Barra Mansa',
        'Belford Roxo','Cabo Frio','Cachoeiras de Macacu','Campos dos Goytacazes',
        'Capital (Rio de Janeiro)','Duque de Caxias','Iguaba Grande','Itaborai',
        'Itaguai','Itaocara','Itaperuna','Laje de Muriae','Macae','Mage',
        'Mangaratiba','Marica','Mesquita','Miguel Pereira','Nilopolis','Niteroi',
        'Nova Friburgo','Nova Iguacu','Paraty','Paty do Alferes','Petropolis',
        'Queimados','Resende','Rio Bonito','Rio das Ostras','Sao Fidelis',
        'Sao Goncalo','Sao Joao de Meriti','Sao Pedro da Aldeia',
        'Sao Sebastiao do Alto','Saquarema','Seropedica','Silva Jardim',
        'Teresopolis','Tres Rios','Valenca','Vassouras','Volta Redonda',
    );
}

/**
 * Tipos de prazo processual
 */
function tipos_prazo()
{
    return array(
        'Contestacao', 'Replica', 'Memoriais / Alegacoes Finais',
        'Apelacao', 'Embargos de Declaracao', 'Contrarrazoes',
        'Agravo de Instrumento', 'Impugnacao ao Cumprimento',
        'Embargos a Execucao', 'Manifestacao', 'Juntada de Documentos',
        'Recurso Inominado', 'Cumprimento de Sentenca',
        'Tutela de Urgencia', 'Outro',
    );
}
