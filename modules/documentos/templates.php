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

    $valorTotal = $d['valor_honorarios'] ?: '_________';
    $valorExtenso = $d['valor_extenso'] ?: '___________________';
    $parcelas = $d['num_parcelas'] ?: '___';
    $valorParcela = $d['valor_parcela'] ?: '_________';
    $formaPgto = $d['forma_pagamento'] ?: 'BOLETO BANCÁRIO';
    $diaVenc = $d['dia_vencimento'] ?: '___';
    $mesInicio = $d['mes_inicio'] ?: '___________';

    $html .= '<p style="font-size:12px;"><strong>HONORÁRIOS ADVOCATÍCIOS:</strong> a(o) <strong>CONTRATANTE</strong> se compromete a pagar para a <strong>CONTRATADA</strong> o valor total de <strong>R$ ' . f($valorTotal) . '</strong>, em <strong>' . f($parcelas) . ' parcelas</strong> mensais e consecutivas de <strong>R$ ' . f($valorParcela) . '</strong> cada, via <strong>' . f($formaPgto) . '</strong>, cujo vencimento será <strong>todo dia ' . f($diaVenc) . ' de cada mês</strong>, com início no mês <strong>' . f($mesInicio) . '</strong>. O atraso no pagamento de qualquer das parcelas gerará à <strong>CONTRATADA</strong> o direito de renunciar os poderes outorgados, mediante aviso prévio.</p>';

    $html .= '<p style="font-size:12px;">Caso seja necessária a propositura de execução ou cumprimento de sentença para a cobrança da pensão alimentícia em atraso, fica desde já acordado que o escritório de advocacia contratado realizará o procedimento sem custo adicional para a parte <strong>CONTRATANTE</strong>. Em caso de êxito, ou seja, no efetivo recebimento dos valores devidos, o escritório fará jus a um honorário de êxito correspondente a 25% do montante recuperado, caracterizando-se como uma ação de risco.</p>';

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
