<?php
/**
 * Templates dos documentos — textos reais do escritório
 * Cada função retorna o HTML do corpo do documento
 */

// Dados fixos do escritório
function escritorioData() {
    return array(
        'cnpj' => '51.294.223/0001-40',
        'oab_sociedade' => '5.987/2023',
        'endereco' => 'Avenida Albino de Almeida, n. 119, salas 201 e 202, 2º andar – Campos Elíseos – Resende – RJ',
        'email' => 'contato@ferreiraesa.com.br',
        'whatsapp' => '(24) 99205-0096',
        'website' => 'www.ferreiraesa.com.br',
        'pix' => '51.294.223/0001-40',
        'adv1_nome' => 'AMANDA GUEDES FERREIRA',
        'adv1_oab' => '163.260',
        'adv2_nome' => 'LUIZ EDUARDO DE SÁ SILVA MARCELINO',
        'adv2_oab' => '248.755',
    );
}

function f($v, $ph = '_______________') { return $v ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : $ph; }

// ═══════════════════════════════════════════════════════
// PROCURAÇÃO
// ═══════════════════════════════════════════════════════
function template_procuracao($d) {
    $esc = escritorioData();
    $acaoTexto = $d['acao_texto'] ?: '________________________________';
    $isMenor = ($d['outorgante'] === 'menor');
    $isDefesa = ($d['outorgante'] === 'defesa');

    $html = '<div class="doc-title">PROCURAÇÃO <em>AD JUDICIA ET EXTRA</em></div>';

    // OUTORGANTE
    if ($isMenor) {
        $filhos = $d['child_names'] ?: f('', '{{NOME DO(A) FILHO(A)}}');
        $html .= '<p><strong>OUTORGANTE: ' . $filhos . '</strong>, representado(a)/assistido(a) por <strong>' . f($d['nome']) . '</strong>, brasileiro(a), CPF n. <strong>' . f($d['cpf'], '___.___.___-__') . '</strong>, residente e domiciliada(o) na ' . f($d['endereco']) . ', e-mail: ' . f($d['email']) . ', telefone n. ' . f($d['phone']) . '.</p>';
    } elseif ($isDefesa) {
        $html .= '<p><strong>OUTORGANTE: ' . f($d['nome']) . '</strong>, ' . f($d['estado_civil']) . ', ' . f($d['profissao']) . ', CPF n. <strong>' . f($d['cpf'], '___.___.___-__') . '</strong>.</p>';
    } else {
        $html .= '<p><strong>OUTORGANTE: ' . f($d['nome']) . '</strong>, CPF n. <strong>' . f($d['cpf'], '___.___.___-__') . '</strong>, residente e domiciliada(o) na ' . f($d['endereco']) . ', e-mail n. ' . f($d['email']) . ' e telefone n. ' . f($d['phone']) . '.</p>';
    }

    // OUTORGADA
    $html .= '<p><strong>OUTORGADA: FERREIRA &amp; SÁ ADVOCACIA</strong>, inscrita no CNPJ sob o n. ' . $esc['cnpj'] . ', Registro da Sociedade OAB ' . $esc['oab_sociedade'] . ', e-mail: ' . $esc['email'] . ', whatsapp ' . $esc['whatsapp'] . ', com escritório profissional localizado na ' . $esc['endereco'] . ', neste ato representada por seus advogados sócios-administradores, <strong>' . $esc['adv1_nome'] . '</strong>, inscrita na OAB-RJ sob o n. ' . $esc['adv1_oab'] . ' e <strong>' . $esc['adv2_nome'] . '</strong>, inscrito na OAB-RJ sob o n. ' . $esc['adv2_oab'] . '.</p>';

    // PODERES GERAIS
    $html .= '<p><strong>PODERES GERAIS:</strong> pelo presente documento, a parte outorgante designa e confia à parte outorgada a função de sua procuradora <u>judicial e extrajudicial</u>, concedendo-lhe plenos, gerais e ilimitados poderes para representá-la adequadamente em todas as instâncias judiciais e extrajudiciais, conforme estabelecido na cláusula <em>ad judicia et extra</em> e <em>ad negocia</em>, especialmente para atuar em <strong style="text-decoration:underline;">' . $acaoTexto . '</strong>. Isso inclui a autorização para subestabelecer esses poderes, com ou sem reserva, conforme necessário, possibilitando a realização de todos os atos essenciais para o desenvolvimento e execução eficazes deste mandato, em conformidade com o artigo 105 do Código de Processo Civil.</p>';

    $html .= '<p>Entre os poderes atribuídos, estão a capacidade recorrer, negociar acordos, contestar, receber notificações (<strong>EXCETO CITAÇÃO</strong>), assinar documentos variados, promover medidas cautelares, produzir provas, examinar processos, lidar com custas e despesas processuais, efetuar defesas e alegações, organizar documentos, solicitar perícias, entre outras atividades necessárias à representação efetiva perante qualquer esfera do Judiciário, órgãos públicos e entidades da administração direta ou indireta, em todos os níveis governamentais, garantindo o cumprimento integral deste mandato.</p>';

    // PODERES ESPECIAIS
    $html .= '<p><strong>PODERES ESPECIAIS:</strong> esse instrumento também confere poderes específicos para atos como <strong>confessar, admitir</strong> a procedência de pedidos, <strong>negociar (acordar), desistir, renunciar</strong> a direitos subjacentes à ação, <strong>receber valores, emitir recibos e dar quitação, representar em audiência de conciliação e sessão de mediação, solicitar isenção de custas judiciais (gratuidade de justiça) e renunciar a valores excedentes (JEF)</strong>.</p>';

    // LOCAL E DATA
    $html .= '<div class="local-data">' . f($d['cidade_data']) . '</div>';

    // ASSINATURA
    $html .= '<div class="assinatura"><div class="linha"></div><div class="nome-ass">' . f($d['nome']) . '</div>';
    if ($isMenor) {
        $html .= '<div style="font-size:11px;color:#6b7280;">REPRESENTANTE LEGAL</div>';
    }
    $html .= '</div>';

    return $html;
}

// ═══════════════════════════════════════════════════════
// CONTRATO
// ═══════════════════════════════════════════════════════
function template_contrato($d) {
    $esc = escritorioData();

    $html = '<div class="doc-title">CONTRATO DE HONORÁRIOS ADVOCATÍCIOS</div>';

    // CONTRATANTE E CONTRATADA (lado a lado no CSS)
    $html .= '<div style="display:flex;gap:1.5rem;margin-bottom:1.5rem;">';
    $html .= '<div style="flex:1;border:1.5px solid #d7ab90;border-radius:12px;padding:1rem;"><div style="background:#052228;color:#fff;display:inline-block;padding:.2rem .7rem;border-radius:6px;font-size:11px;font-weight:700;margin-bottom:.5rem;">CONTRATANTE</div>';
    $html .= '<p style="font-size:12px;text-indent:0;">• <strong>' . f($d['nome']) . '</strong>, CPF n. ' . f($d['cpf'], '___.___.___-__') . ', endereço: ' . f($d['endereco']) . ', e-mail: ' . f($d['email']) . ', telefone: ' . f($d['phone']) . '</p></div>';

    $html .= '<div style="flex:1;border:1.5px solid #d7ab90;border-radius:12px;padding:1rem;"><div style="background:#d7ab90;color:#052228;display:inline-block;padding:.2rem .7rem;border-radius:6px;font-size:11px;font-weight:700;margin-bottom:.5rem;">CONTRATADA</div>';
    $html .= '<p style="font-size:12px;text-indent:0;">◆ <strong>FERREIRA &amp; SÁ ADVOCACIA</strong>, sociedade de advocacia inscrita no <strong>CNPJ ' . $esc['cnpj'] . '</strong>, <strong>Registro da Sociedade OAB ' . $esc['oab_sociedade'] . '</strong>, com sede na ' . $esc['endereco'] . ', e-mail: ' . $esc['email'] . ', whatsapp ' . $esc['whatsapp'] . ', website: ' . $esc['website'] . ', neste ato representada por seu administrador que esta assina digitalmente.</p></div>';
    $html .= '</div>';

    $html .= '<p style="text-indent:0;"><strong>CONTRATANTE</strong> e <strong>CONTRATADA</strong> de agora em diante denominadas, em conjunto, "Partes" e, individualmente, "Parte".</p>';

    $acaoTexto = $d['acao_texto'] ?: '________________________________';

    // 1. OBJETO e 2. VIGÊNCIA
    $html .= '<div style="display:flex;gap:1.5rem;margin:1.5rem 0;">';
    $html .= '<div style="flex:1;"><p class="no-indent" style="font-size:14px;font-weight:800;color:#052228;">1. OBJETO</p>';
    $html .= '<p style="font-size:12px;">Prestação de serviços advocatícios especializados, correspondente à consultoria jurídica e representação processual em <strong>' . $acaoTexto . '</strong>, incluindo, em sendo necessária, a propositura e atuação no processo judicial, até decisão judicial final.</p></div>';

    $html .= '<div style="flex:1;"><p class="no-indent" style="font-size:14px;font-weight:800;color:#052228;">2. VIGÊNCIA</p>';
    $html .= '<p style="font-size:12px;"><strong>INÍCIO DOS SERVIÇOS:</strong> a contar da assinatura do presente contrato.</p>';
    $html .= '<p style="font-size:12px;"><strong>TÉRMINO DOS SERVIÇOS:</strong> até decisão final no processo objeto do presente contrato.</p>';
    $html .= '<p style="font-size:12px;">Na eventual hipótese de descumprimento dos valores devidos a título de honorários ou manutenção mensal por parte da(o) <strong>CONTRATANTE</strong>, poderá a <strong>CONTRATADA RENUNCIAR</strong> os poderes outorgados, mediante aviso prévio, ocasião em que não mais atuará em prol dos interesses da(o) <strong>CONTRATANTE</strong>.</p></div>';
    $html .= '</div>';

    // 3. VALOR E PAGAMENTO
    $html .= '<p class="no-indent" style="font-size:14px;font-weight:800;color:#052228;">3. VALOR E PAGAMENTO</p>';

    $isRisco = (isset($d['tipo_cobranca']) && $d['tipo_cobranca'] === 'risco');

    if ($isRisco) {
        $percentual = $d['percentual_risco'] ?: '30';
        $base = $d['base_risco'] ?: 'do proveito econômico obtido';

        $html .= '<p style="font-size:12px;"><strong>HONORÁRIOS ADVOCATÍCIOS (CONTRATO DE RISCO):</strong> a(o) <strong>CONTRATANTE</strong> e a <strong>CONTRATADA</strong> acordam que os honorários serão fixados em <strong>' . f($percentual) . '% (' . f($percentual) . ' por cento) ' . f($base) . '</strong> em favor do(a) CONTRATANTE, seja por decisão judicial, acordo ou qualquer outra forma de resolução do litígio.</p>';

        $html .= '<p style="font-size:12px;">Caso não haja êxito na demanda, nenhum valor será devido a título de honorários advocatícios, caracterizando-se como uma <strong>ação de risco</strong>. As despesas processuais (custas, emolumentos, perícias) correrão por conta do(a) CONTRATANTE.</p>';

    } else {
        $valorTotal = $d['valor_honorarios'] ?: '_________';
        $valorExtenso = $d['valor_extenso'] ?: '___________________';
        $parcelas = $d['num_parcelas'] ?: '___';
        $valorParcela = $d['valor_parcela'] ?: '_________';
        $formaPgto = $d['forma_pagamento'] ?: 'BOLETO BANCÁRIO';
        $diaVenc = $d['dia_vencimento'] ?: '___';
        $mesInicio = $d['mes_inicio'] ?: '___________';

        $html .= '<p style="font-size:12px;"><strong>HONORÁRIOS ADVOCATÍCIOS:</strong> a(o) <strong>CONTRATANTE</strong> se compromete a pagar para a <strong>CONTRATADA</strong> o valor total de <strong>' . f($valorTotal) . '</strong>, em <strong>' . f($parcelas) . ' parcelas</strong> mensais e consecutivas de <strong>' . f($valorParcela) . '</strong> cada, via <strong>' . f($formaPgto) . '</strong>, cujo vencimento será <strong>todo dia ' . f($diaVenc) . ' de cada mês</strong>, com início no mês <strong>' . f($mesInicio) . '</strong>. O atraso no pagamento de qualquer das parcelas gerará à <strong>CONTRATADA</strong> o direito de renunciar os poderes outorgados, mediante aviso prévio.</p>';

        $html .= '<p style="font-size:12px;">Caso seja necessária a propositura de execução ou cumprimento de sentença para a cobrança da pensão alimentícia em atraso, fica desde já acordado que o escritório de advocacia contratado realizará o procedimento sem custo adicional para a parte <strong>CONTRATANTE</strong>. Em caso de êxito, ou seja, no efetivo recebimento dos valores devidos, o escritório fará jus a um honorário de êxito correspondente a 25% do montante recuperado, caracterizando-se como uma ação de risco.</p>';
    }

    $html .= '<p style="font-size:12px;font-weight:700;text-align:center;margin:1rem 0;">⚠ Chave pix: ' . $esc['pix'] . ' – NÃO EFETUE TRANSFERÊNCIAS PARA OUTRA CHAVE!</p>';

    // 4. RESPONSABILIDADES
    $html .= '<p class="no-indent" style="font-size:14px;font-weight:800;color:#052228;margin-top:1.5rem;">4. RESPONSABILIDADES</p>';
    $html .= '<div style="display:flex;gap:1.5rem;">';
    $html .= '<div style="flex:1;"><div style="background:#052228;color:#fff;display:inline-block;padding:.2rem .7rem;border-radius:6px;font-size:10px;font-weight:700;margin-bottom:.5rem;">CONTRATANTE</div>';
    $html .= '<p style="font-size:11.5px;">4.1 O(A) <strong>CONTRATANTE</strong> reconhece já haver recebido a orientação preventiva comportamental e jurídica para a consecução dos serviços, e fornecerá à <strong>CONTRATADA</strong> os <strong>documentos e meios necessários à comprovação do seu direito</strong>, bem como pagará as despesas judiciais e eventuais honorários advocatícios de sucumbência, caso aplicável.</p></div>';
    $html .= '<div style="flex:1;"><div style="background:#d7ab90;color:#052228;display:inline-block;padding:.2rem .7rem;border-radius:6px;font-size:10px;font-weight:700;margin-bottom:.5rem;">CONTRATADA</div>';
    $html .= '<p style="font-size:11.5px;">4.2 A <strong>CONTRATADA</strong> não assegura ao(à) <strong>CONTRATANTE</strong> êxito na demanda pois, conforme informado no ato das negociações preliminares, a obrigação na prestação de serviços de advocacia é de meio e não de fim. Todavia, a <strong>CONTRATADA</strong> se compromete a empregar todos os esforços, bem como a boa técnica para que os objetivos do(a) <strong>CONTRATANTE</strong> sejam alcançados.</p></div>';
    $html .= '</div>';

    // 5. INADIMPLEMENTO
    $html .= '<p class="no-indent" style="font-size:14px;font-weight:800;color:#052228;margin-top:1.5rem;">5. INADIMPLEMENTO FINANCEIRO - MULTA E JUROS</p>';
    $html .= '<p style="font-size:12px;">5.1. Na eventual hipótese de inadimplemento financeiro por parte do(a) <strong>CONTRATANTE</strong>, a <strong>CONTRATADA</strong> cobrará, além do valor devido, <strong>multa pecuniária de 20%, juros de mora de 1% ao mês e correção monetária.</strong> Em caso de cobrança judicial, devem ser acrescidas custas processuais e 20% de honorários advocatícios.</p>';
    $html .= '<p style="font-size:12px;">5.2 Havendo a ausência do pagamento do valor acordado no presente contrato, poderá a <strong>CONTRATADA,</strong> mediante aviso prévio de 10 dias, <strong>RENUNCIAR</strong> os poderes outorgados, deixando de atuar em prol dos interesses do(a) <strong>CONTRATANTE</strong>, sem prejuízo da cobrança judicial ou extrajudicial dos valores devidos, além do direito de pleitear a homologação da <strong>desistência da ação, finalizando o procedimento</strong>.</p>';

    // 6. SUCUMBÊNCIA e 7. DESPESAS
    $html .= '<div style="display:flex;gap:1.5rem;margin-top:1.5rem;">';
    $html .= '<div style="flex:1;"><p class="no-indent" style="font-size:13px;font-weight:800;color:#052228;">6. HONORÁRIOS DE CONDENAÇÃO (SUCUMBÊNCIA):</p>';
    $html .= '<p style="font-size:11.5px;">6.1. Se houver, pertencerão ao Escritório de Advocacia, sem exclusão dos que ora são contratados, em consonância aos artigos 23 da Lei nº 8.906/94 e 35, parágrafo 1º, do Código de Ética e Disciplina da OAB.</p></div>';
    $html .= '<div style="flex:1;"><p class="no-indent" style="font-size:13px;font-weight:800;color:#052228;">7. DESPESAS EXTRAORDINÁRIAS</p>';
    $html .= '<p style="font-size:11.5px;">7.1 O(A) <strong>CONTRATANTE</strong> arcará com o pagamento de custas e despesas judiciais, despesas de viagens, de autenticações de documentos, de expedição de certidões e quaisquer outras que decorrerem dos serviços ora contratados, mediante apresentação de demonstrativos analíticos, e a devida prestação de contas. <strong>Haverá prévia comunicação quanto a tais gastos.</strong></p></div>';
    $html .= '</div>';

    // 8. CLÁUSULAS GERAIS
    $html .= '<p class="no-indent" style="font-size:14px;font-weight:800;color:#052228;margin-top:1.5rem;">8. CLÁUSULAS GERAIS</p>';

    $html .= '<div style="display:flex;gap:1.5rem;">';
    $html .= '<div style="flex:1;"><div style="background:#052228;color:#fff;display:inline-block;padding:.2rem .7rem;border-radius:6px;font-size:10px;font-weight:700;margin-bottom:.5rem;">LIMITES DE ATUAÇÃO</div>';
    $html .= '<p style="font-size:11.5px;">8.1 A atuação profissional ficará restrita ao Juízo da causa, em Primeira Instância (salvo acordo em sentido contrário), não compreendendo manifestações em Recurso Extraordinário e/ou Especial, ou eventual Ação Rescisória.</p></div>';
    $html .= '<div style="flex:1;"><div style="background:#d7ab90;color:#052228;display:inline-block;padding:.2rem .7rem;border-radius:6px;font-size:10px;font-weight:700;margin-bottom:.5rem;">FORMAS DE CONTATO</div>';
    $html .= '<p style="font-size:11.5px;">8.2 O(A) <strong>CONTRATANTE</strong> autoriza, desde já, que a <strong>CONTRATADA</strong> envie correspondências, comunicados e atualizações aos endereços e números fornecidos.</p></div>';
    $html .= '</div>';

    // LGPD
    $html .= '<div style="margin-top:1rem;"><div style="background:#052228;color:#fff;display:inline-block;padding:.2rem .7rem;border-radius:6px;font-size:10px;font-weight:700;margin-bottom:.5rem;">LEI GERAL DE PROTEÇÃO DE DADOS</div>';
    $html .= '<p style="font-size:11.5px;">8.3 A <strong>CONTRATADA</strong> se compromete a respeitar a Lei Geral de Proteção de Dados (LGPD). Os dados pessoais dos clientes serão armazenados por 05 anos, conforme exigido por lei, e serão mantidos em ambiente seguro e protegido contra acessos não autorizados.</p></div>';

    // RENÚNCIA / REVOGAÇÃO
    $html .= '<div style="margin-top:1rem;"><div style="background:#d7ab90;color:#052228;display:inline-block;padding:.2rem .7rem;border-radius:6px;font-size:10px;font-weight:700;margin-bottom:.5rem;">RENÚNCIA / REVOGAÇÃO</div>';
    $html .= '<p style="font-size:11.5px;">8.4 Caso uma das <strong>PARTES</strong> decida pela interrupção, deverá comunicar por escrito. Em caso de revogação pelo(a) <strong>CONTRATANTE</strong>:</p>';
    $html .= '<p style="font-size:11.5px;"><strong>- Caso não tenha ocorrido a distribuição do processo, a multa será de 30% do valor total contratado;</strong></p>';
    $html .= '<p style="font-size:11.5px;"><strong>- Se o processo já tiver sido iniciado, mas sem decisão deferindo eventual tutela, a multa será de 50% do valor total contratado;</strong></p>';
    $html .= '<p style="font-size:11.5px;"><strong>- Se o processo já estiver em fase final, antes da sentença, com realização de audiência ou etapa equivalente, o valor integral do contrato será devido.</strong></p></div>';

    // 9. FORO E DATA
    $cidadeForo = $d['cidade_foro'] ?: ($d['cidade'] ?: 'Resende');
    $estadoForo = $d['estado_foro'] ?: ($d['uf'] ?: 'RJ');
    $html .= '<p class="no-indent" style="font-size:14px;font-weight:800;color:#052228;margin-top:1.5rem;">9. FORO E DATA</p>';
    $html .= '<p style="font-size:12px;">Na eventual hipótese de existência de conflitos, as partes elegem o Foro da cidade de ' . f($cidadeForo) . ' - ' . f($estadoForo) . '.</p>';
    $html .= '<p style="font-size:12px;">As partes assinam o presente contrato em ' . f($d['data_contrato'] ?: $d['cidade_data']) . '.</p>';

    return $html;
}

// ═══════════════════════════════════════════════════════
// DECLARAÇÃO DE HIPOSSUFICIÊNCIA
// ═══════════════════════════════════════════════════════
function template_hipossuficiencia($d) {
    $html = '<div class="doc-title" style="font-style:italic;">DECLARAÇÃO DE HIPOSSUFICIÊNCIA</div>';

    $html .= '<p>Eu, <strong>' . f($d['nome']) . '</strong>, CPF n. <strong>' . f($d['cpf'], '___.___.___-__') . '</strong>, residente e domiciliado(a) na ' . f($d['endereco']) . ', e-mail: ' . f($d['email']) . ', telefone n. ' . f($d['phone']) . ', <strong>DECLARO</strong> que não possuo recursos financeiros para arcar com as custas extrajudiciais ou judiciais sem prejuízo do meu próprio sustento e de minha família, na forma do artigo 98 e seguintes do Código de Processo Civil.</p>';

    $html .= '<p>DECLARO, por fim, estar ciente de que a falsidade da presente declaração pode implicar na sanção civil consistente no pagamento de até o décuplo das custas judiciais, conforme os mandamentos contidos na Lei n. 1.060/50.</p>';

    $html .= '<div class="local-data">' . f($d['cidade_data']) . '</div>';
    $html .= '<div class="assinatura"><div class="linha"></div><div class="nome-ass">' . f($d['nome']) . '</div></div>';

    return $html;
}

// ═══════════════════════════════════════════════════════
// DECLARAÇÃO DE ISENÇÃO DE IR
// ═══════════════════════════════════════════════════════
function template_isencao_ir($d) {
    $ano = date('Y');
    $anoAnterior = $ano - 1;

    $html = '<div class="doc-title">Declaração de Isenção do Imposto de Renda Pessoa Física (IRPF)</div>';

    $html .= '<p>Eu, <strong>' . f($d['nome']) . '</strong>, CPF n. <strong>' . f($d['cpf'], '___.___.___-__') . '</strong>, residente e domiciliado(a) na ' . f($d['endereco']) . ', e-mail: ' . f($d['email']) . ', telefone n. ' . f($d['phone']) . ' <strong>DECLARO</strong> ser isento(a) da apresentação da Declaração do Imposto de Renda Pessoa Física (DIRPF) no(s) exercício(s) por não incorrer em nenhuma das hipóteses de obrigatoriedade estabelecidas pelas Instruções Normativas (IN) da Receita Federal do Brasil (RFB).</p>';

    $html .= '<p>Esta declaração está em conformidade com a IN RFB nº 1548/2015 e a Lei nº 7.115/83*.</p>';

    $html .= '<p>Declaro ainda, sob as penas da lei, serem verdadeiras todas as informações acima prestadas.</p>';

    $html .= '<div class="local-data">' . f($d['cidade_data']) . '</div>';
    $html .= '<div class="assinatura"><div class="linha"></div><div class="nome-ass">' . f($d['nome']) . '</div></div>';

    // Nota de rodapé
    $html .= '<div style="margin-top:3rem;padding-top:1rem;border-top:1px solid #999;font-size:9px;color:#666;">';
    $html .= '<p style="text-indent:0;font-size:9px;">* Esclarecemos que a Receita Federal do Brasil não emite declaração de que o(a) cidadão(ã) está isento(a) de apresentar a DIRPF, pois a IN RFB nº 1548/2015 regula que, a partir de 2008, deixa de existir a Declaração Anual de Isento. A Lei nº 7.115/83 assegura que a isenção poderá ser comprovada mediante declaração escrita e assinada pelo próprio interessado.</p>';
    $html .= '<p style="text-indent:0;font-size:9px;margin-top:.5rem;"><strong>LEI Nº 7.115, DE 29 DE AGOSTO DE 1983.</strong><br>Art. 1º - A declaração destinada a fazer prova de vida, residência, pobreza, dependência econômica, homonímia ou bons antecedentes, quando firmada pelo próprio interessado ou por procurador bastante, e sob as penas da Lei, presume-se verdadeira.<br>Art. 2º - Se comprovadamente falsa a declaração, sujeitar-se-á o declarante às sanções civis, administrativas e criminais previstas na legislação aplicável.<br>Art. 3º - A declaração mencionará expressamente a responsabilidade do declarante.</p>';
    $html .= '</div>';

    return $html;
}

// ═══════════════════════════════════════════════════════
// SUBSTABELECIMENTO
// ═══════════════════════════════════════════════════════
function template_substabelecimento($d) {
    $esc = escritorioData();
    $acaoTexto = $d['acao_texto'] ?: '________________________________';
    $comReserva = !isset($d['sem_reserva']) || !$d['sem_reserva'];

    $html = '<div class="doc-title">SUBSTABELECIMENTO ' . ($comReserva ? 'COM' : 'SEM') . ' RESERVA DE PODERES</div>';

    $html .= '<p>Pelo presente instrumento particular, <strong>' . f($d['nome']) . '</strong>, CPF n. <strong>' . f($d['cpf'], '___.___.___-__') . '</strong>, na qualidade de outorgante nos autos de <strong>' . $acaoTexto . '</strong>, por intermédio de seus procuradores constituídos, <strong>FERREIRA &amp; SÁ ADVOCACIA</strong>, CNPJ ' . $esc['cnpj'] . ', Registro da Sociedade OAB ' . $esc['oab_sociedade'] . ', representada por <strong>' . $esc['adv1_nome'] . '</strong>, OAB/RJ ' . $esc['adv1_oab'] . ' e <strong>' . $esc['adv2_nome'] . '</strong>, OAB/RJ ' . $esc['adv2_oab'] . ', vem, respeitosamente,</p>';

    $html .= '<p><strong>SUBSTABELECER ' . ($comReserva ? 'COM RESERVA' : 'SEM RESERVA') . ' DE PODERES</strong>, nos termos do artigo 667, §2º do Código Civil, todos os poderes que lhe foram conferidos na procuração outorgada para representação nos autos supramencionados, ao(à) advogado(a):</p>';

    $html .= '<p style="text-indent:0;background:#f8f9fa;padding:1rem;border-radius:8px;border-left:4px solid #052228;">';
    $html .= '<strong>Nome:</strong> ' . f('', '________________________________') . '<br>';
    $html .= '<strong>OAB:</strong> ' . f('', '____________') . '<br>';
    $html .= '<strong>E-mail:</strong> ' . f('', '________________________________') . '<br>';
    $html .= '<strong>Endereço profissional:</strong> ' . f('', '________________________________');
    $html .= '</p>';

    $html .= '<p>O(A) substabelecido(a) fica autorizado(a) a praticar todos os atos necessários ao bom e fiel cumprimento do mandato, incluindo os poderes especiais da cláusula <em>ad judicia et extra</em>, ' . ($comReserva ? 'mantendo-se íntegros os poderes dos substabelecentes.' : 'ficando os substabelecentes desonerados de qualquer responsabilidade.') . '</p>';

    $html .= '<div class="local-data">' . f($d['cidade_data']) . '</div>';

    $html .= '<div style="display:flex;gap:2rem;margin-top:3rem;">';
    $html .= '<div class="assinatura" style="flex:1;"><div class="linha"></div><div class="nome-ass">' . $esc['adv1_nome'] . '</div><div style="font-size:10px;color:#6b7280;">OAB/RJ ' . $esc['adv1_oab'] . '</div></div>';
    $html .= '<div class="assinatura" style="flex:1;"><div class="linha"></div><div class="nome-ass">' . $esc['adv2_nome'] . '</div><div style="font-size:10px;color:#6b7280;">OAB/RJ ' . $esc['adv2_oab'] . '</div></div>';
    $html .= '</div>';

    return $html;
}

// ═══════════════════════════════════════════════════════
// DECLARAÇÃO DE RESIDÊNCIA
// ═══════════════════════════════════════════════════════
function template_residencia($d) {
    $html = '<div class="doc-title">DECLARAÇÃO DE RESIDÊNCIA</div>';

    $html .= '<p>Eu, <strong>' . f($d['nome']) . '</strong>, ' . f($d['estado_civil']) . ', ' . f($d['profissao']) . ', portador(a) do CPF n. <strong>' . f($d['cpf'], '___.___.___-__') . '</strong>' . (isset($d['rg']) && $d['rg'] ? ', RG n. ' . f($d['rg']) : '') . ', <strong>DECLARO</strong>, para os devidos fins de direito e sob as penas da Lei (artigo 2º da Lei 7.115/83), que <strong>RESIDO</strong> no seguinte endereço:</p>';

    $html .= '<div style="background:#f8f9fa;padding:1.25rem;border-radius:10px;border-left:4px solid #0d9488;margin:1.5rem 0;font-size:13px;">';
    $html .= '<strong>' . f($d['endereco']) . '</strong>';
    $html .= '</div>';

    $html .= '<p>Declaro ainda que as informações acima são verdadeiras e que estou ciente de que a falsidade desta declaração configura crime previsto no artigo 299 do Código Penal Brasileiro, e que a presente declaração poderá ser utilizada como comprovante de residência nos termos da legislação vigente.</p>';

    $html .= '<p>Por ser expressão da verdade, firmo a presente declaração.</p>';

    $html .= '<div class="local-data">' . f($d['cidade_data']) . '</div>';
    $html .= '<div class="assinatura"><div class="linha"></div><div class="nome-ass">' . f($d['nome']) . '</div>';
    $html .= '<div style="font-size:10px;color:#6b7280;">CPF: ' . f($d['cpf'], '___.___.___-__') . '</div></div>';

    return $html;
}

// ═══════════════════════════════════════════════════════
// TERMO DE ACORDO EXTRAJUDICIAL
// ═══════════════════════════════════════════════════════
function template_acordo($d) {
    $esc = escritorioData();

    $html = '<div class="doc-title">TERMO DE ACORDO EXTRAJUDICIAL</div>';

    $html .= '<p>Pelo presente instrumento particular, as partes abaixo qualificadas:</p>';

    $html .= '<div style="background:#f0f9ff;padding:1rem;border-radius:10px;border-left:4px solid #052228;margin:.75rem 0;">';
    $html .= '<div style="background:#052228;color:#fff;display:inline-block;padding:.15rem .6rem;border-radius:5px;font-size:10px;font-weight:700;margin-bottom:.4rem;">PARTE 1</div><br>';
    $html .= '<strong>' . f($d['nome']) . '</strong>, ' . f($d['estado_civil']) . ', ' . f($d['profissao']) . ', CPF n. ' . f($d['cpf'], '___.___.___-__') . ', residente em ' . f($d['endereco']) . ', e-mail: ' . f($d['email']) . ', telefone: ' . f($d['phone']) . '.';
    $html .= '</div>';

    $html .= '<div style="background:#fdf2f8;padding:1rem;border-radius:10px;border-left:4px solid #d7ab90;margin:.75rem 0;">';
    $html .= '<div style="background:#d7ab90;color:#052228;display:inline-block;padding:.15rem .6rem;border-radius:5px;font-size:10px;font-weight:700;margin-bottom:.4rem;">PARTE 2</div><br>';
    $html .= '<strong>' . f('', '________________________________') . '</strong>, ________________, ________________, CPF n. ___.___.___-__, residente em ________________________________.';
    $html .= '</div>';

    $html .= '<p>Resolvem, de comum acordo, firmar o presente <strong>TERMO DE ACORDO EXTRAJUDICIAL</strong>, que se regerá pelas seguintes cláusulas e condições:</p>';

    $html .= '<p class="no-indent" style="font-size:13px;font-weight:800;color:#052228;margin-top:1.5rem;">CLÁUSULA 1ª — DO OBJETO</p>';
    $html .= '<p>O presente acordo tem por objeto ________________________________________________________________________________________________________________________________.</p>';

    $html .= '<p class="no-indent" style="font-size:13px;font-weight:800;color:#052228;margin-top:1.25rem;">CLÁUSULA 2ª — DAS CONDIÇÕES</p>';
    $html .= '<p>As partes acordam que: ________________________________________________________________________________________________________________________________.</p>';

    $html .= '<p class="no-indent" style="font-size:13px;font-weight:800;color:#052228;margin-top:1.25rem;">CLÁUSULA 3ª — DO PRAZO</p>';
    $html .= '<p>O presente acordo terá vigência a partir da data de sua assinatura, pelo prazo de ________________, podendo ser renovado mediante termo aditivo.</p>';

    $html .= '<p class="no-indent" style="font-size:13px;font-weight:800;color:#052228;margin-top:1.25rem;">CLÁUSULA 4ª — DO INADIMPLEMENTO</p>';
    $html .= '<p>O descumprimento de qualquer das cláusulas deste acordo importará em multa de 20% (vinte por cento) sobre o valor do acordo, além de juros de mora de 1% (um por cento) ao mês e correção monetária, sem prejuízo da execução judicial do título extrajudicial, nos termos do artigo 784, III do Código de Processo Civil.</p>';

    $html .= '<p class="no-indent" style="font-size:13px;font-weight:800;color:#052228;margin-top:1.25rem;">CLÁUSULA 5ª — DO FORO</p>';
    $html .= '<p>As partes elegem o foro da Comarca de ' . f(isset($d['cidade']) ? $d['cidade'] : 'Resende') . ' – ' . f(isset($d['uf']) ? $d['uf'] : 'RJ') . ' para dirimir quaisquer controvérsias oriundas do presente instrumento.</p>';

    $html .= '<p>E, por estarem assim justas e acordadas, as partes assinam o presente instrumento em 02 (duas) vias de igual teor e forma, na presença de 02 (duas) testemunhas.</p>';

    $html .= '<div class="local-data">' . f($d['cidade_data']) . '</div>';

    $html .= '<div style="display:flex;gap:2rem;margin-top:2.5rem;">';
    $html .= '<div class="assinatura" style="flex:1;"><div class="linha"></div><div class="nome-ass">' . f($d['nome']) . '</div><div style="font-size:10px;color:#6b7280;">PARTE 1</div></div>';
    $html .= '<div class="assinatura" style="flex:1;"><div class="linha"></div><div class="nome-ass">________________________________</div><div style="font-size:10px;color:#6b7280;">PARTE 2</div></div>';
    $html .= '</div>';

    $html .= '<div style="display:flex;gap:2rem;margin-top:2.5rem;">';
    $html .= '<div class="assinatura" style="flex:1;"><div class="linha"></div><div class="nome-ass">TESTEMUNHA 1</div><div style="font-size:10px;color:#6b7280;">CPF: ___.___.___-__</div></div>';
    $html .= '<div class="assinatura" style="flex:1;"><div class="linha"></div><div class="nome-ass">TESTEMUNHA 2</div><div style="font-size:10px;color:#6b7280;">CPF: ___.___.___-__</div></div>';
    $html .= '</div>';

    $html .= '<div style="margin-top:2rem;padding-top:1rem;border-top:2px solid #d7ab90;text-align:center;">';
    $html .= '<p style="font-size:11px;color:#6b7280;text-indent:0;">Elaborado e acompanhado por:</p>';
    $html .= '<p style="font-size:12px;font-weight:700;text-indent:0;"><strong>FERREIRA &amp; SÁ ADVOCACIA</strong> — OAB/RJ ' . $esc['oab_sociedade'] . '</p>';
    $html .= '</div>';

    return $html;
}

// ═══════════════════════════════════════════════════════
// PETIÇÃO DE JUNTADA DE DOCUMENTOS
// ═══════════════════════════════════════════════════════
function template_juntada($d) {
    $esc = escritorioData();
    $html = '<div class="doc-title">PETIÇÃO DE JUNTADA DE DOCUMENTOS</div>';

    $vara = isset($d['vara_juizo']) && $d['vara_juizo'] ? $d['vara_juizo'] : '___ª VARA DE FAMÍLIA DA COMARCA DE _______________/RJ';
    $numProcesso = isset($d['numero_processo']) && $d['numero_processo'] ? $d['numero_processo'] : '_______________';

    $html .= '<p style="font-weight:700;text-transform:uppercase;text-indent:0;">JUÍZO DA ' . f($vara) . '</p>';
    $html .= '<p style="text-align:right;font-style:italic;text-indent:0;">Autos n. ' . f($numProcesso) . '</p>';

    $html .= '<p><strong>' . f($d['nome']) . '</strong>, já qualificado(a) nos autos do processo em epígrafe, vem, respeitosamente, perante Vossa Excelência, por intermédio de seus advogados que esta subscrevem, com escritório profissional indicado no rodapé, requerer a</p>';

    $html .= '<div style="background:#052228;color:#fff;padding:10px 20px;text-align:center;font-weight:700;font-size:13px;letter-spacing:3px;text-transform:uppercase;margin:20px 0;border-left:6px solid #B87333;">JUNTADA DE DOCUMENTOS</div>';

    $html .= '<p>pelos motivos de fato e de direito a seguir expostos.</p>';

    $html .= '<p style="font-weight:700;color:#052228;text-indent:0;margin-top:1.5rem;">DOS DOCUMENTOS</p>';
    $html .= '<p>A parte requer a juntada dos seguintes documentos, imprescindíveis ao regular andamento do feito:</p>';

    $docs = isset($d['lista_documentos']) && $d['lista_documentos'] ? $d['lista_documentos'] : '';
    if ($docs) {
        $linhas = preg_split('/\r?\n/', $docs);
        $letra = ord('a');
        $html .= '<div style="margin:12px 0;">';
        foreach ($linhas as $linha) {
            $linha = trim($linha);
            if (!$linha) continue;
            $html .= '<p style="text-indent:0;margin:4px 0;"><strong>' . chr($letra) . ')</strong> ' . f($linha) . ';</p>';
            $letra++;
        }
        $html .= '</div>';
    } else {
        $html .= '<p style="text-indent:0;">a) ________________________________;</p>';
        $html .= '<p style="text-indent:0;">b) ________________________________;</p>';
        $html .= '<p style="text-indent:0;">c) ________________________________.</p>';
    }

    $justificativa = isset($d['justificativa']) && $d['justificativa'] ? $d['justificativa'] : '';
    if ($justificativa) {
        $html .= '<p style="font-weight:700;color:#052228;text-indent:0;margin-top:1.5rem;">DA JUSTIFICATIVA</p>';
        $html .= '<p>' . f($justificativa) . '</p>';
    }

    $html .= '<p>A juntada dos referidos documentos faz-se necessária para a instrução processual adequada e a garantia do direito ao contraditório e à ampla defesa, nos termos do art. 5º, LV, da Constituição Federal.</p>';

    $html .= '<p style="font-weight:700;color:#052228;text-indent:0;margin-top:1.5rem;">DO PEDIDO</p>';
    $html .= '<p>Ante o exposto, requer a Vossa Excelência que se digne a receber e determinar a juntada dos documentos que acompanham esta petição, dando-se ciência à parte contrária, na forma da lei.</p>';

    $html .= '<p style="text-align:center;margin-top:2rem;">Nestes termos, pede deferimento.</p>';
    $html .= '<div class="local-data">' . f($d['cidade_data']) . '</div>';

    $html .= '<div style="display:flex;gap:2rem;margin-top:2.5rem;">';
    $html .= '<div class="assinatura" style="flex:1;"><div class="linha"></div><div class="nome-ass">' . $esc['adv1_nome'] . '</div><div style="font-size:10px;color:#6b7280;">OAB/RJ ' . $esc['adv1_oab'] . '</div></div>';
    $html .= '<div class="assinatura" style="flex:1;"><div class="linha"></div><div class="nome-ass">' . $esc['adv2_nome'] . '</div><div style="font-size:10px;color:#6b7280;">OAB/RJ ' . $esc['adv2_oab'] . '</div></div>';
    $html .= '</div>';

    return $html;
}

// ═══════════════════════════════════════════════════════
// PETIÇÃO DE CIÊNCIA
// ═══════════════════════════════════════════════════════
// ═══════════════════════════════════════════════════════
// PESQUISA PREVJUD
// ═══════════════════════════════════════════════════════
function template_prevjud($d) {
    $esc = escritorioData();
    $html = '';

    $vara = isset($d['vara_juizo']) && $d['vara_juizo'] ? $d['vara_juizo'] : '___ª VARA DE FAMÍLIA DA COMARCA DE _______________/RJ';
    $numProcesso = isset($d['numero_processo']) && $d['numero_processo'] ? $d['numero_processo'] : '_______________';
    $nomeGenitor = isset($d['nome_genitor']) && $d['nome_genitor'] ? $d['nome_genitor'] : '_______________';
    $cpfGenitor = isset($d['cpf_genitor']) && $d['cpf_genitor'] ? $d['cpf_genitor'] : '___.___.___-__';

    // Cabeçalho
    $html .= '<p style="font-weight:700;text-transform:uppercase;text-indent:0;font-size:12px;line-height:1.8;">JUÍZO DA ' . f($vara) . '</p>';
    $html .= '<br>';
    $html .= '<p style="text-align:right;font-style:italic;text-indent:0;font-size:11px;color:#6b7280;">Autos n. ' . f($numProcesso) . '</p>';
    $html .= '<br>';

    // Qualificação
    $html .= '<p style="text-indent:4em;text-align:justify;line-height:2;"><strong>' . f($d['nome']) . '</strong>';
    if (isset($d['child_names']) && $d['child_names']) {
        $html .= ', representado(a) por sua genitora/genitor <strong>' . f($d['child_names']) . '</strong>';
    }
    $html .= ', já qualificado(a) nos autos do processo em epígrafe, por intermédio de seus advogados que esta subscrevem digitalmente, vem, respeitosamente à presença de Vossa Excelência,</p>';

    // Destaque visual PREVJUD
    $html .= '<div style="margin:25px 0;background:linear-gradient(135deg,#052228,#0d3640);border-radius:8px;overflow:hidden;">';
    $html .= '<div style="padding:15px 25px;text-align:center;">';
    $html .= '<div style="font-size:10px;color:#B87333;text-transform:uppercase;letter-spacing:4px;font-weight:600;margin-bottom:4px;">Requerimento de</div>';
    $html .= '<div style="font-size:16px;color:#fff;font-weight:800;letter-spacing:5px;">PESQUISA PREVJUD</div>';
    $html .= '</div></div>';

    // Corpo
    $html .= '<p style="text-indent:4em;text-align:justify;line-height:2;">Requerer a Vossa Excelência a realização de pesquisa, via <strong>Sistema PREVJUD</strong> (Previdência e Justiça), a fim de que se apure e se obtenha informações detalhadas acerca do(s) <strong>vínculo(s) empregatício(s)</strong>, contribuições previdenciárias, benefícios e demais relações de trabalho do(a) senhor(a):</p>';

    // Box com dados do pesquisado
    $html .= '<div style="margin:20px 0;border:2px solid #052228;border-radius:10px;overflow:hidden;">';
    $html .= '<div style="background:#052228;color:#fff;padding:8px 20px;font-size:10px;text-transform:uppercase;letter-spacing:2px;font-weight:700;">Dados para Pesquisa</div>';
    $html .= '<div style="padding:15px 20px;">';
    $html .= '<table style="width:100%;border-collapse:collapse;">';
    $html .= '<tr><td style="padding:6px 10px;font-size:11px;color:#6b7280;font-weight:600;width:120px;border-bottom:1px solid #e5e7eb;">Nome Completo</td><td style="padding:6px 10px;font-size:12px;font-weight:700;border-bottom:1px solid #e5e7eb;">' . f($nomeGenitor) . '</td></tr>';
    $html .= '<tr><td style="padding:6px 10px;font-size:11px;color:#6b7280;font-weight:600;border-bottom:1px solid #e5e7eb;">CPF</td><td style="padding:6px 10px;font-size:12px;font-weight:700;font-family:monospace;border-bottom:1px solid #e5e7eb;">' . f($cpfGenitor) . '</td></tr>';
    $html .= '</table></div></div>';

    // Fundamentação
    $html .= '<p style="text-indent:4em;text-align:justify;line-height:2;">A presente diligência se faz necessária para a correta instrução processual, sendo imprescindível a verificação das <strong>reais condições financeiras</strong> do(a) requerido(a), especialmente no que tange à existência de vínculos empregatícios formais, informais ou eventuais benefícios previdenciários, nos termos do <strong>art. 370 do CPC</strong> e em atenção ao princípio da busca da verdade real.</p>';

    $html .= '<p style="text-indent:4em;text-align:justify;line-height:2;">Tal medida é fundamental para garantir a <strong>adequada fixação</strong> ou <strong>revisão dos alimentos</strong>, assegurando que os valores correspondam à real capacidade contributiva do alimentante e às necessidades do(a) alimentando(a), conforme dispõe o <strong>art. 1.694, §1º, do Código Civil</strong>.</p>';

    // Pedidos
    $html .= '<div style="margin:25px 0;background:#f8f9fa;border-left:4px solid #B87333;border-radius:0 8px 8px 0;padding:15px 20px;">';
    $html .= '<div style="font-size:10px;color:#B87333;text-transform:uppercase;letter-spacing:2px;font-weight:700;margin-bottom:8px;">Do Requerimento</div>';
    $html .= '<p style="text-align:justify;line-height:1.8;margin:0;">Ante o exposto, requer a Vossa Excelência que determine a realização de pesquisa via <strong>Sistema PREVJUD</strong> em nome de <strong>' . f($nomeGenitor) . '</strong>, CPF <strong>' . f($cpfGenitor) . '</strong>, para que se obtenha:</p>';
    $html .= '<ul style="margin:10px 0 0 20px;line-height:1.8;">';
    $html .= '<li>Informações sobre <strong>vínculos empregatícios</strong> ativos e inativos;</li>';
    $html .= '<li>Valores de <strong>remuneração</strong> percebida;</li>';
    $html .= '<li>Eventuais <strong>benefícios previdenciários</strong> (aposentadoria, auxílios, pensões);</li>';
    $html .= '<li>Histórico de <strong>contribuições ao INSS</strong>.</li>';
    $html .= '</ul></div>';

    // Fechamento
    $html .= '<p style="text-align:center;margin-top:2.5rem;">Nestes termos, pede deferimento.</p>';
    $html .= '<div class="local-data">' . f($d['cidade_data']) . '</div>';

    // Assinaturas
    $html .= '<div style="display:flex;gap:2rem;margin-top:2.5rem;">';
    $html .= '<div class="assinatura" style="flex:1;"><div class="linha"></div><div class="nome-ass">' . $esc['adv1_nome'] . '</div><div style="font-size:10px;color:#6b7280;">OAB/RJ ' . $esc['adv1_oab'] . '</div></div>';
    $html .= '<div class="assinatura" style="flex:1;"><div class="linha"></div><div class="nome-ass">' . $esc['adv2_nome'] . '</div><div style="font-size:10px;color:#6b7280;">OAB/RJ ' . $esc['adv2_oab'] . '</div></div>';
    $html .= '</div>';

    return $html;
}

// ═══════════════════════════════════════════════════════
// CIÊNCIA
// ═══════════════════════════════════════════════════════
function template_ciencia($d) {
    $esc = escritorioData();
    $html = '<div class="doc-title">PETIÇÃO DE CIÊNCIA</div>';

    $vara = isset($d['vara_juizo']) && $d['vara_juizo'] ? $d['vara_juizo'] : '___ª VARA DE FAMÍLIA DA COMARCA DE _______________/RJ';
    $numProcesso = isset($d['numero_processo']) && $d['numero_processo'] ? $d['numero_processo'] : '_______________';
    $objetoCiencia = isset($d['objeto_ciencia']) && $d['objeto_ciencia'] ? $d['objeto_ciencia'] : 'r. decisão/despacho de id. _______________';

    $html .= '<p style="font-weight:700;text-transform:uppercase;text-indent:0;">JUÍZO DA ' . f($vara) . '</p>';
    $html .= '<p style="text-align:right;font-style:italic;text-indent:0;">Autos n. ' . f($numProcesso) . '</p>';

    $html .= '<p><strong>' . f($d['nome']) . '</strong>, já qualificado(a) nos autos do processo em epígrafe, vem, respeitosamente, perante Vossa Excelência, por intermédio de seus advogados que esta subscrevem, com escritório profissional indicado no rodapé, manifestar</p>';

    $html .= '<div style="background:#052228;color:#fff;padding:10px 20px;text-align:center;font-weight:700;font-size:13px;letter-spacing:3px;text-transform:uppercase;margin:20px 0;border-left:6px solid #B87333;">CIÊNCIA</div>';

    $html .= '<p>acerca da <strong>' . f($objetoCiencia) . '</strong>, proferida nos autos em epígrafe, declarando estar ciente de seu inteiro teor.</p>';

    $reserva = isset($d['reserva_manifestacao']) && $d['reserva_manifestacao'] === 'sim';
    if ($reserva) {
        $html .= '<p>Desde já, <strong>reserva-se o direito de manifestação posterior</strong> no prazo legal, caso entenda necessário, ficando consignado que a presente ciência não importa em concordância tácita com o conteúdo da referida decisão.</p>';
    }

    $html .= '<p style="text-align:center;margin-top:2rem;">Nestes termos, pede deferimento.</p>';
    $html .= '<div class="local-data">' . f($d['cidade_data']) . '</div>';

    $html .= '<div style="display:flex;gap:2rem;margin-top:2.5rem;">';
    $html .= '<div class="assinatura" style="flex:1;"><div class="linha"></div><div class="nome-ass">' . $esc['adv1_nome'] . '</div><div style="font-size:10px;color:#6b7280;">OAB/RJ ' . $esc['adv1_oab'] . '</div></div>';
    $html .= '<div class="assinatura" style="flex:1;"><div class="linha"></div><div class="nome-ass">' . $esc['adv2_nome'] . '</div><div style="font-size:10px;color:#6b7280;">OAB/RJ ' . $esc['adv2_oab'] . '</div></div>';
    $html .= '</div>';

    return $html;
}

// ═══════════════════════════════════════════════════════════
// CITACAO POR WHATSAPP — Art. 246, V, CPC (Lei 14.195/2021)
// ═══════════════════════════════════════════════════════════
function template_citacao_whatsapp($d) {
    $esc = escritorioData();
    $vara = isset($d['vara_juizo']) && $d['vara_juizo'] ? $d['vara_juizo'] : '___a VARA DE FAMILIA DA COMARCA DE _______________';
    $numProcesso = isset($d['numero_processo']) && $d['numero_processo'] ? $d['numero_processo'] : '_______________';
    $nomeReu = isset($d['nome_reu']) && $d['nome_reu'] ? $d['nome_reu'] : '_______________';
    $whatsappReu = isset($d['whatsapp_reu']) && $d['whatsapp_reu'] ? $d['whatsapp_reu'] : '(__)_____-____';
    $tipoAcao = isset($d['tipo_acao_citacao']) && $d['tipo_acao_citacao'] ? $d['tipo_acao_citacao'] : '_______________';
    $justificativa = isset($d['justificativa_citacao']) && $d['justificativa_citacao'] ? $d['justificativa_citacao'] : '';

    $html = '<div class="doc-title">PETIÇÃO INTERCORRENTE</div>';
    $html .= '<p style="font-weight:700;text-transform:uppercase;text-indent:0;">JUIZO DA ' . f($vara) . '</p>';
    $html .= '<p style="text-align:right;font-style:italic;text-indent:0;">Autos n. ' . f($numProcesso) . '</p>';
    $html .= '<p><strong>' . f($d['nome']) . '</strong>, ja qualificado(a) nos autos do processo em epigrafe, vem, respeitosamente, perante Vossa Excelencia, por intermedio de seus advogados que esta subscrevem, com escritorio profissional indicado no rodape, requerer a</p>';
    $html .= '<div style="background:#052228;color:#fff;padding:10px 20px;text-align:center;font-weight:700;font-size:13px;letter-spacing:3px;text-transform:uppercase;margin:20px 0;border-left:6px solid #B87333;">CITACAO DO(A) REU/RA POR MEIO ELETRONICO (WHATSAPP)</div>';
    $html .= '<p>da parte re <strong>' . f($nomeReu) . '</strong>, nos termos a seguir expostos.</p>';

    $html .= '<div style="border-right:4px solid #B87333;padding:6px 14px 6px 0;text-align:right;font-weight:700;font-size:12px;color:#052228;text-transform:uppercase;letter-spacing:2px;margin:24px 0 10px;">I &mdash; DOS FUNDAMENTOS</div>';
    $html .= '<p>Trata-se de <strong>Acao de ' . f($tipoAcao) . '</strong> em tramite perante este r. Juizo.</p>';
    $html .= '<p>A parte autora requer que a <strong>citacao do(a) requerido(a)</strong> seja realizada por meio eletronico, especificamente pelo aplicativo <strong>WhatsApp</strong>, com fundamento no <strong>art. 246, V, do Codigo de Processo Civil</strong>, com redacao dada pela <strong>Lei n. 14.195/2021</strong>, que admite expressamente a citacao por meio eletronico.</p>';
    $html .= '<p>Dispoe o referido dispositivo legal:</p>';
    $html .= '<div style="margin:12px 0 12px 40px;padding:10px 16px;border-left:4px solid #B87333;background:#f8f8f6;font-style:italic;font-size:11px;color:#333;">&ldquo;Art. 246. A citacao sera feita preferencialmente por meio eletronico, no prazo de ate 2 (dois) dias uteis, contado da decisao que a determinar, por meio dos enderecos eletronicos indicados pelo citando no banco de dados do Poder Judiciario ou, na falta, por meio eletronico, na forma prevista em lei.&rdquo;</div>';
    $html .= '<p>A jurisprudencia dos Tribunais brasileiros tem admitido a citacao por WhatsApp como meio idoneo e eficaz de comunicacao processual, desde que possibilite a <strong>confirmacao de recebimento e leitura</strong> pelo destinatario, em respeito aos principios do contraditorio e da ampla defesa.</p>';
    $html .= '<p>Nesse sentido, o <strong>Conselho Nacional de Justica (CNJ)</strong>, por meio da <strong>Resolução n. 354/2020</strong>, regulamentou a comunicacao de atos processuais por meio eletronico, consolidando a possibilidade de utilizacao de aplicativos de mensageria para citacoes e intimacoes.</p>';
    if ($justificativa) {
        $html .= '<p>Ademais, cabe destacar que: <strong>' . f($justificativa) . '</strong>, o que reforca a necessidade e conveniencia da citacao por meio eletronico.</p>';
    }

    $html .= '<div style="border-right:4px solid #B87333;padding:6px 14px 6px 0;text-align:right;font-weight:700;font-size:12px;color:#052228;text-transform:uppercase;letter-spacing:2px;margin:24px 0 10px;">II &mdash; DADOS PARA CITACAO</div>';
    $html .= '<table style="width:100%;border-collapse:collapse;margin:12px 0;">';
    $html .= '<tr><td style="padding:8px 12px;border:1px solid #ddd;font-weight:700;width:200px;background:#f8f8f6;">Nome do(a) reu/ra</td><td style="padding:8px 12px;border:1px solid #ddd;">' . f($nomeReu) . '</td></tr>';
    $html .= '<tr><td style="padding:8px 12px;border:1px solid #ddd;font-weight:700;background:#f8f8f6;">Telefone/WhatsApp</td><td style="padding:8px 12px;border:1px solid #ddd;">' . f($whatsappReu) . '</td></tr>';
    $html .= '</table>';

    $html .= '<div style="border-right:4px solid #B87333;padding:6px 14px 6px 0;text-align:right;font-weight:700;font-size:12px;color:#052228;text-transform:uppercase;letter-spacing:2px;margin:24px 0 10px;">III &mdash; DO PEDIDO</div>';
    $html .= '<p>Ante o exposto, requer a Vossa Excelencia:</p>';
    $html .= '<table style="width:100%;border-collapse:collapse;margin:12px 0;">';
    $html .= '<tr><td style="padding:8px 12px;background:#052228;color:#fff;font-weight:700;text-align:center;width:40px;vertical-align:top;">a)</td><td style="padding:8px 12px;border:1px solid #ddd;">Que a <strong>citacao do(a) requerido(a) ' . f($nomeReu) . '</strong> seja realizada por meio do aplicativo <strong>WhatsApp</strong>, no numero <strong>' . f($whatsappReu) . '</strong>, nos termos do art. 246, V, do CPC.</td></tr>';
    $html .= '<tr><td style="padding:8px 12px;background:#052228;color:#fff;font-weight:700;text-align:center;width:40px;vertical-align:top;">b)</td><td style="padding:8px 12px;border:1px solid #ddd;background:#f8f8f6;">Que, apos a confirmacao de leitura da mensagem, seja certificada nos autos a efetivacao da citacao, com a juntada do respectivo comprovante.</td></tr>';
    $html .= '</table>';

    $html .= '<p style="text-align:center;margin-top:2rem;">Nestes termos, pede deferimento.</p>';
    $html .= '<div class="local-data">' . f($d['cidade_data']) . '</div>';
    $html .= '<div style="display:flex;gap:2rem;margin-top:2.5rem;">';
    $html .= '<div class="assinatura" style="flex:1;"><div class="linha"></div><div class="nome-ass">' . $esc['adv1_nome'] . '</div><div style="font-size:10px;color:#6b7280;">OAB/RJ ' . $esc['adv1_oab'] . '</div></div>';
    $html .= '<div class="assinatura" style="flex:1;"><div class="linha"></div><div class="nome-ass">' . $esc['adv2_nome'] . '</div><div style="font-size:10px;color:#6b7280;">OAB/RJ ' . $esc['adv2_oab'] . '</div></div>';
    $html .= '</div>';
    return $html;
}
