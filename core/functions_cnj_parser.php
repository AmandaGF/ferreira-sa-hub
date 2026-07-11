<?php
/**
 * Parser de numero CNJ (Amanda 10/07/2026).
 *
 * Formato oficial: NNNNNNN-DD.AAAA.J.TR.OOOO
 *   NNNNNNN — 7 digitos sequenciais (ano corrente do tribunal)
 *   DD      — 2 digitos verificadores
 *   AAAA    — 4 digitos do ano
 *   J       — 1 digito do SEGMENTO (1=STF, 2=CNJ, 3=STJ, 4=TRF,
 *             5=TJM Estadual Militar, 6=TRE, 7=Militar Estadual,
 *             8=ESTADUAL, 9=TRABALHISTA)
 *   TR      — 2 digitos do TRIBUNAL (dentro do segmento)
 *   OOOO    — 4 digitos da ORIGEM (vara/comarca)
 *
 * Uso principal: preencher UF (e futuramente comarca) automaticamente
 * quando o usuario cola o CNJ no formulario de cadastro/edicao de case.
 */

/**
 * @param string $cnj  numero CNJ em qualquer formatacao (aceita com/sem pontuacao)
 * @return array{
 *   ok:bool, uf:string, segmento:string, segmento_nome:string,
 *   tribunal_codigo:string, tribunal_nome:string,
 *   origem_codigo:string, comarca:string, erro:string
 * }
 */
function parse_cnj($cnj) {
    $out = array(
        'ok' => false, 'uf' => '', 'segmento' => '', 'segmento_nome' => '',
        'tribunal_codigo' => '', 'tribunal_nome' => '',
        'origem_codigo' => '', 'comarca' => '', 'erro' => '',
    );
    $digits = preg_replace('/\D/', '', (string)$cnj);
    if (strlen($digits) !== 20) {
        $out['erro'] = 'CNJ precisa ter 20 digitos (recebido: ' . strlen($digits) . ')';
        return $out;
    }

    $out['segmento']         = substr($digits, 13, 1);
    $out['tribunal_codigo']  = substr($digits, 14, 2);
    $out['origem_codigo']    = substr($digits, 16, 4);

    // Segmento 8 = Justica Estadual — TR mapeia direto pra UF
    $trUfEstadual = array(
        '01'=>'AC','02'=>'AL','03'=>'AP','04'=>'AM','05'=>'BA','06'=>'CE','07'=>'DF','08'=>'ES',
        '09'=>'GO','10'=>'MA','11'=>'MT','12'=>'MS','13'=>'MG','14'=>'PA','15'=>'PB','16'=>'PR',
        '17'=>'PE','18'=>'PI','19'=>'RJ','20'=>'RN','21'=>'RS','22'=>'RO','23'=>'RR','24'=>'SC',
        '25'=>'SP','26'=>'SE','27'=>'TO',
    );

    // Segmento 4 = Justica Federal — TR mapeia pra TRF (agrupa varias UFs)
    // TRF1: DF, AC, AM, AP, BA, GO, MA, MT, MG, PA, PI, RO, RR, TO
    // TRF2: RJ, ES
    // TRF3: SP, MS
    // TRF4: RS, PR, SC
    // TRF5: PE, AL, CE, PB, RN, SE
    // TRF6: MG (novo, 2022+)
    $trfPrincipal = array(
        '01' => array('nome' => 'TRF1', 'uf_principal' => 'DF'),
        '02' => array('nome' => 'TRF2', 'uf_principal' => 'RJ'),
        '03' => array('nome' => 'TRF3', 'uf_principal' => 'SP'),
        '04' => array('nome' => 'TRF4', 'uf_principal' => 'RS'),
        '05' => array('nome' => 'TRF5', 'uf_principal' => 'PE'),
        '06' => array('nome' => 'TRF6', 'uf_principal' => 'MG'),
    );

    // Segmento 5 = Justica do Trabalho — TR mapeia pra TRT que cobre 1 ou mais UFs
    $trtUf = array(
        '01' => 'RJ', '02' => 'SP', '03' => 'MG', '04' => 'RS', '05' => 'BA',
        '06' => 'PE', '07' => 'CE', '08' => 'PA/AP', '09' => 'PR', '10' => 'DF/TO',
        '11' => 'AM/RR', '12' => 'SC', '13' => 'PB', '14' => 'RO/AC', '15' => 'SP-Campinas',
        '16' => 'MA', '17' => 'ES', '18' => 'GO', '19' => 'AL', '20' => 'SE',
        '21' => 'RN', '22' => 'PI', '23' => 'MT', '24' => 'MS',
    );

    switch ($out['segmento']) {
        case '1':
            $out['segmento_nome'] = 'STF';
            $out['tribunal_nome'] = 'Supremo Tribunal Federal';
            $out['uf'] = 'DF';
            break;
        case '2':
            $out['segmento_nome'] = 'CNJ';
            $out['tribunal_nome'] = 'Conselho Nacional de Justiça';
            $out['uf'] = 'DF';
            break;
        case '3':
            $out['segmento_nome'] = 'STJ';
            $out['tribunal_nome'] = 'Superior Tribunal de Justiça';
            $out['uf'] = 'DF';
            break;
        case '4':
            $out['segmento_nome'] = 'Justiça Federal';
            $tr = $out['tribunal_codigo'];
            if (isset($trfPrincipal[$tr])) {
                $out['tribunal_nome'] = $trfPrincipal[$tr]['nome'];
                $out['uf'] = $trfPrincipal[$tr]['uf_principal']; // aproximacao — TRF cobre varias UFs
            }
            break;
        case '5':
            $out['segmento_nome'] = 'Justiça do Trabalho';
            $tr = $out['tribunal_codigo'];
            if (isset($trtUf[$tr])) {
                $out['tribunal_nome'] = 'TRT' . (int)$tr;
                $out['uf'] = $trtUf[$tr];
            }
            break;
        case '6':
            $out['segmento_nome'] = 'Justiça Eleitoral';
            $tr = $out['tribunal_codigo'];
            // TREs seguem mesma numeracao dos TJs estaduais
            if (isset($trUfEstadual[$tr])) {
                $out['tribunal_nome'] = 'TRE-' . $trUfEstadual[$tr];
                $out['uf'] = $trUfEstadual[$tr];
            }
            break;
        case '7':
            $out['segmento_nome'] = 'Justiça Militar da União';
            $out['tribunal_nome'] = 'STM';
            $out['uf'] = 'DF';
            break;
        case '8':
            $out['segmento_nome'] = 'Justiça Estadual';
            $tr = $out['tribunal_codigo'];
            if (isset($trUfEstadual[$tr])) {
                $out['uf'] = $trUfEstadual[$tr];
                $out['tribunal_nome'] = 'TJ-' . $trUfEstadual[$tr];
                // Comarca — so temos tabela do TJRJ por ora (Amanda usa >85% RJ)
                if ($out['uf'] === 'RJ') {
                    $out['comarca'] = _cnj_comarca_tjrj($out['origem_codigo']);
                }
            }
            break;
        case '9':
            $out['segmento_nome'] = 'Justiça Militar Estadual';
            $tr = $out['tribunal_codigo'];
            if (isset($trUfEstadual[$tr])) {
                $out['tribunal_nome'] = 'TJM-' . $trUfEstadual[$tr];
                $out['uf'] = $trUfEstadual[$tr];
            }
            break;
        default:
            $out['erro'] = 'Segmento desconhecido: ' . $out['segmento'];
            return $out;
    }

    $out['ok'] = ($out['uf'] !== '');
    if (!$out['ok'] && !$out['erro']) $out['erro'] = 'Nao foi possivel identificar a UF';
    return $out;
}

/**
 * Tabela parcial de codigos de origem (comarca/vara) do TJRJ.
 * Baseada nos codigos oficiais publicados pelo TJRJ. Incluidas apenas as
 * comarcas com mais processos no Hub (Capital + Baixada + principais interior).
 * Retorna string vazia se codigo nao mapeado (Amanda pode preencher manual).
 */
function _cnj_comarca_tjrj($codigo) {
    static $map = array(
        // Capital
        '0001' => 'Capital',
        '0209' => 'Regional da Barra da Tijuca (Capital)',
        '0212' => 'Regional de Jacarepaguá (Capital)',
        '0210' => 'Regional de Madureira (Capital)',
        '0211' => 'Regional de Bangu (Capital)',
        '0213' => 'Regional de Campo Grande (Capital)',
        '0208' => 'Regional de Santa Cruz (Capital)',
        '0202' => 'Regional da Ilha do Governador (Capital)',
        '0203' => 'Regional de Pavuna (Capital)',
        '0204' => 'Regional de Guaratiba (Capital)',
        '0207' => 'Regional de Méier (Capital)',

        // Baixada Fluminense e Grande Rio
        '0038' => 'Duque de Caxias',
        '0016' => 'Duque de Caxias',
        '0043' => 'Nova Iguaçu',
        '0018' => 'Nova Iguaçu',
        '0021' => 'São Gonçalo',
        '0052' => 'São Gonçalo',
        '0020' => 'São João de Meriti',
        '0019' => 'Nilópolis',
        '0026' => 'Belford Roxo',
        '0027' => 'Belford Roxo',
        '0029' => 'Queimados',
        '0022' => 'Itaguaí',
        '0023' => 'Mesquita',
        '0025' => 'Japeri',
        '0037' => 'Niterói',
        '0002' => 'Niterói',
        '0028' => 'Magé',
        '0035' => 'Magé',
        '0024' => 'Guapimirim',
        '0039' => 'Itaboraí',
        '0041' => 'Tanguá',
        '0042' => 'Rio Bonito',
        '0034' => 'Maricá',
        '0030' => 'São Pedro da Aldeia',
        '0031' => 'Cabo Frio',
        '0011' => 'Cabo Frio',

        // Regiao dos Lagos e Norte
        '0032' => 'Araruama',
        '0033' => 'Saquarema',
        '0036' => 'Iguaba Grande',
        '0004' => 'Campos dos Goytacazes',
        '0044' => 'Campos dos Goytacazes',
        '0045' => 'São Fidélis',
        '0046' => 'São Francisco de Itabapoana',
        '0047' => 'Macaé',
        '0048' => 'Rio das Ostras',
        '0049' => 'Casimiro de Abreu',
        '0050' => 'Silva Jardim',

        // Interior sul e centro
        '0010' => 'Petrópolis',
        '0056' => 'Petrópolis',
        '0015' => 'Teresópolis',
        '0057' => 'Teresópolis',
        '0007' => 'Nova Friburgo',
        '0058' => 'Nova Friburgo',
        '0008' => 'Cantagalo',
        '0009' => 'Cordeiro',
        '0012' => 'Volta Redonda',
        '0059' => 'Volta Redonda',
        '0013' => 'Barra Mansa',
        '0060' => 'Barra Mansa',
        '0014' => 'Resende',
        '0061' => 'Resende',
        '0017' => 'Angra dos Reis',
        '0062' => 'Angra dos Reis',
        '0006' => 'Barra do Piraí',
        '0063' => 'Barra do Piraí',
        '0064' => 'Vassouras',
        '0065' => 'Valença',
        '0066' => 'Três Rios',
        '0067' => 'Sapucaia',
        '0068' => 'Paraíba do Sul',
    );
    return isset($map[$codigo]) ? $map[$codigo] : '';
}
