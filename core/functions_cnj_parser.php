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
                // Comarca — TJRJ tem tabela propria com acentos + regionais.
                // As demais UFs vem do dataset forosCNJ (ABJ), sem acentos.
                if ($out['uf'] === 'RJ') {
                    $out['comarca'] = _cnj_comarca_tjrj($out['origem_codigo']);
                } else {
                    $out['comarca'] = _cnj_comarca_outros_tjs($out['uf'], $out['origem_codigo']);
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
 * Tabela OFICIAL de codigos de comarca/regional do TJRJ.
 *
 * Fonte: https://www.tjrj.jus.br/consultas/cod_serventias/cons_cod_serventias
 * Baixada em 10/07/2026 apos Amanda apontar que a tabela anterior (chutada)
 * tinha codigos errados (colei 0066=Tres Rios mas o certo e Volta Redonda —
 * na oficial 0063=Tres Rios e 0066=Volta Redonda).
 *
 * Cobre 88 comarcas + 11 regionais da Capital + 4 comarcas da Capital com
 * bairro (Copacabana/Lagoa/Tijuca/Vila Isabel) + varas especializadas.
 *
 * Codigos 0000 (2a instancia) e 9000 (Turmas Recursais) NAO tem comarca fisica.
 */
/**
 * Comarcas dos outros TJs estaduais (nao RJ).
 *
 * DESATIVADO em 10/07/2026 apos Amanda testar TJSC (codigo 0040 retornou
 * "Balneario Camboriu" mas o real era Laguna, confirmado via edital oficial
 * do TJSC 5001914-74.2025.8.24.0040). A tabela ABJ (forosCNJ) capturou uma
 * versao antiga antes de remapeamentos do TJSC — provavelmente vale pra
 * outros TJs tambem, mas nao da pra confiar sem verificar tribunal por
 * tribunal.
 *
 * Decisao Amanda: melhor NAO preencher do que preencher errado. So RJ
 * (que tem tabela oficial vinda do site do TJRJ direto) mantem comarca
 * automatica. Outros TJs: UF preenche (100% confiavel — vem do proprio
 * numero), comarca fica em branco pro usuario preencher manual.
 *
 * Pra reativar: buscar tabela OFICIAL do TJ desejado, adicionar em
 * core/data/comarcas_tj.php e remover ele da whitelist abaixo.
 */
function _cnj_comarca_outros_tjs($uf, $codigo) {
    // Whitelist vazia: nenhuma UF nao-RJ tem comarca automatica ativa.
    // Adicionar UFs aqui APENAS quando tabela for verificada contra fonte oficial.
    static $whitelist_verificada = array();
    if (!in_array($uf, $whitelist_verificada, true)) return '';
    static $mapa = null;
    if ($mapa === null) {
        $mapa = @include __DIR__ . '/data/comarcas_tj.php';
        if (!is_array($mapa)) $mapa = array();
    }
    return isset($mapa[$uf][$codigo]) ? $mapa[$uf][$codigo] : '';
}

function _cnj_comarca_tjrj($codigo) {
    static $map = array(
        // Comarcas (0001 a 0087) — municipios do RJ
        '0001' => 'Rio de Janeiro', // Capital pura — sem regional
        '0002' => 'Niterói',
        '0003' => 'Angra dos Reis',
        '0004' => 'São Gonçalo',
        '0005' => 'Arraial do Cabo',
        '0006' => 'Barra do Piraí',
        '0007' => 'Barra Mansa',
        '0008' => 'Belford Roxo',
        '0009' => 'Bom Jardim',
        '0010' => 'Bom Jesus de Itabapoana',
        '0011' => 'Cabo Frio',
        '0012' => 'Cachoeiras de Macacu',
        '0013' => 'Cambuci',
        '0014' => 'Campos dos Goytacazes',
        '0015' => 'Cantagalo',
        '0016' => 'Carmo',
        '0017' => 'Casimiro de Abreu',
        '0018' => 'Conceição de Macabu',
        '0019' => 'Cordeiro',
        '0020' => 'Duas Barras',
        '0021' => 'Duque de Caxias',
        '0022' => 'Engenheiro Paulo de Frontin',
        '0023' => 'Itaboraí',
        '0024' => 'Itaguaí',
        '0025' => 'Itaocara',
        '0026' => 'Itaperuna',
        '0027' => 'Laje do Muriaé',
        '0028' => 'Macaé',
        '0029' => 'Magé',
        '0030' => 'Mangaratiba',
        '0031' => 'Maricá',
        '0032' => 'Mendes',
        '0033' => 'Miguel Pereira',
        '0034' => 'Miracema',
        '0035' => 'Natividade',
        '0036' => 'Nilópolis',
        '0037' => 'Nova Friburgo',
        '0038' => 'Nova Iguaçu',
        '0039' => 'Paracambi',
        '0040' => 'Paraíba do Sul',
        '0041' => 'Paraty',
        '0042' => 'Petrópolis',
        '0043' => 'Piraí',
        '0044' => 'Porciúncula',
        '0045' => 'Resende',
        '0046' => 'Rio Bonito',
        '0047' => 'Rio Claro',
        '0048' => 'Rio das Flores',
        '0049' => 'Santa Maria Madalena',
        '0050' => 'Santo Antônio de Pádua',
        '0051' => 'São Fidelis',
        '0052' => 'Araruama',
        '0053' => 'São João da Barra',
        '0054' => 'São João de Meriti',
        '0055' => 'São Pedro da Aldeia',
        '0056' => 'São Sebastião do Alto',
        '0057' => 'Sapucaia',
        '0058' => 'Saquarema',
        '0059' => 'Silva Jardim',
        '0060' => 'Sumidouro',
        '0061' => 'Teresópolis',
        '0062' => 'Trajano de Moraes',
        '0063' => 'Três Rios',
        '0064' => 'Valença',
        '0065' => 'Vassouras',
        '0066' => 'Volta Redonda',
        '0067' => 'Queimados',
        '0068' => 'Rio das Ostras',
        '0069' => 'Iguaba Grande',
        '0070' => 'São Francisco do Itabapoana',
        '0071' => 'Porto Real - Quatis',
        '0072' => 'Paty do Alferes',
        '0073' => 'Guapimirim',
        '0075' => 'Magé - Regional de Inhomirim',
        '0076' => 'São José do Vale do Rio Preto',
        '0077' => 'Seropédica',
        '0078' => 'Búzios',
        '0079' => 'Itaipava (Regional)',
        '0080' => 'Italva',
        '0081' => 'Itatiaia',
        '0082' => 'Pinheiral',
        '0083' => 'Japeri',
        '0084' => 'Carapebus / Quissamã',
        '0087' => 'Alcântara (Regional)',

        // Regionais da Capital (0202 a 0212)
        '0202' => 'Rio de Janeiro (Regional de Madureira)',
        '0203' => 'Rio de Janeiro (Regional de Jacarepaguá)',
        '0204' => 'Rio de Janeiro (Regional de Bangu)',
        '0205' => 'Rio de Janeiro (Regional de Campo Grande)',
        '0206' => 'Rio de Janeiro (Regional de Santa Cruz)',
        '0207' => 'Rio de Janeiro (Regional da Ilha do Governador)',
        '0208' => 'Rio de Janeiro (Regional do Méier)',
        '0209' => 'Rio de Janeiro (Regional da Barra da Tijuca)',
        '0210' => 'Rio de Janeiro (Regional da Leopoldina)',
        '0211' => 'Rio de Janeiro (Regional da Pavuna)',
        '0212' => 'Rio de Janeiro (Regional da Região Oceânica)',

        // Comarcas da Capital com bairro (0251 a 0256)
        '0251' => 'Rio de Janeiro (Copacabana)',
        '0252' => 'Rio de Janeiro (Lagoa)',
        '0253' => 'Rio de Janeiro (Tijuca)',
        '0254' => 'Rio de Janeiro (Vila Isabel)',
        '0255' => 'Rio de Janeiro (1ª Vara Infância e Juventude Protetiva)',
        '0256' => 'Rio de Janeiro (2ª Vara Infância e Juventude Protetiva)',

        // Especiais (sem municipio)
        '0000' => '2ª Instância',
        '9000' => 'Turmas Recursais',
    );
    return isset($map[$codigo]) ? $map[$codigo] : '';
}
