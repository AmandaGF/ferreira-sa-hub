<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Migração FAQ v2 ===\n\n";

// 1. Adicionar colunas area e destaque se não existirem
$cols = array(
    "ALTER TABLE salavip_faq ADD COLUMN area VARCHAR(50) NOT NULL DEFAULT 'geral' AFTER id",
    "ALTER TABLE salavip_faq ADD COLUMN destaque TINYINT(1) DEFAULT 0 AFTER ativo",
    "ALTER TABLE salavip_faq ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    "ALTER TABLE salavip_faq ADD INDEX idx_area (area)",
);
foreach ($cols as $sql) {
    try { $pdo->exec($sql); echo "OK: " . substr($sql, 0, 60) . "...\n"; }
    catch (Exception $e) { echo "SKIP: " . $e->getMessage() . "\n"; }
}

// 2. Remover coluna criado_em se existir (usar created_at padrão)
try { $pdo->exec("ALTER TABLE salavip_faq CHANGE criado_em created_at DATETIME DEFAULT CURRENT_TIMESTAMP"); echo "OK: criado_em -> created_at\n"; }
catch (Exception $e) { echo "SKIP criado_em: " . $e->getMessage() . "\n"; }

// 3. Limpar FAQs existentes (serão substituídas)
$count = $pdo->exec("DELETE FROM salavip_faq");
echo "\nFAQs anteriores removidas: {$count}\n";

// 4. Inserir novas FAQs por área
$faqs = array(
    // ═══ FAMÍLIA ═══
    array('familia', 'Como funciona a ação de alimentos?', 'A ação de alimentos é o instrumento jurídico pelo qual se pleiteia a fixação de pensão alimentícia em favor de quem não pode prover o próprio sustento. O valor é fixado com base no trinômio necessidade × possibilidade × proporcionalidade, levando em conta as despesas do alimentando, a capacidade financeira do alimentante e a proporcionalidade entre os genitores. O rito é especial (Lei 5.478/68), com audiência de conciliação e instrução, e os alimentos provisórios podem ser fixados já no início do processo.', 1, 1),
    array('familia', 'Qual o valor da pensão alimentícia que posso pedir?', 'O valor da pensão é calculado com base no trinômio necessidade × possibilidade × proporcionalidade. Na prática, a jurisprudência trabalha com percentuais entre 15% e 30% dos rendimentos líquidos do genitor quando ele tem emprego formal. Se for autônomo ou informal, o juiz pode fixar um valor fixo em salários mínimos. Use nossa Calculadora de Pensão para ter uma estimativa do seu caso antes mesmo de consultar um advogado.', 2, 1),
    array('familia', 'O genitor que não paga pensão pode ir preso?', 'Sim. A prisão civil por dívida de alimentos é o único caso em que a prisão civil é permitida no ordenamento jurídico brasileiro (art. 5º, LXVII da Constituição Federal). O devedor que não paga 3 meses de pensão pode ser preso por até 90 dias em regime fechado — e isso vale mesmo que ele tenha outros bens. É uma das ferramentas mais eficazes para forçar o pagamento. Nosso escritório atua com urgência nesses casos.', 3, 1),
    array('familia', 'Quanto tempo demora para começar a receber a pensão?', 'Os alimentos provisórios podem ser fixados logo no início do processo, muitas vezes na primeira decisão judicial — em poucos dias após o ajuizamento da ação. Ou seja, você não precisa esperar o fim do processo para começar a receber. O valor definitivo é fixado ao final, após audiência, e pode ser maior ou menor que o provisório.', 4, 1),
    array('familia', 'O que acontece se o genitor não pagar a pensão?', 'Existem três caminhos: (1) Desconto direto em folha de pagamento — o empregador é obrigado a descontar e repassar o valor; (2) Execução com penhora de bens — veículos, imóveis e contas bancárias podem ser bloqueados via SISBAJUD; (3) Prisão civil — o mais eficaz, com decreto de prisão em regime fechado por até 90 dias. O genitor inadimplente também pode ter o nome inscrito em cadastros de devedores de alimentos. Não deixe acumular — cada mês não pago é uma dívida que pode ser cobrada com juros e correção monetária.', 5, 1),
    array('familia', 'O genitor que trabalha por conta própria ou de forma informal tem que pagar pensão?', 'Sim, sem exceção. Todo genitor tem obrigação alimentar, independente do vínculo empregatício. Para autônomos e informais, o juiz fixa a pensão em valor fixo mensal (em salários mínimos) com base nas despesas comprovadas do filho e nos sinais externos de riqueza do genitor (veículos, imóveis, padrão de vida). A informalidade não é escudo para não pagar.', 6, 1),
    array('familia', 'Até quando o filho tem direito à pensão alimentícia?', 'Em regra, a obrigação alimentar dos pais persiste enquanto o filho depender deles para concluir seus estudos. Tudo depende do caso concreto — da situação de cada filho, do curso que realiza e das circunstâncias familiares. A análise deve ser feita individualmente. Consulte nossa equipe para entender a situação específica do seu caso.', 7, 0),
    array('familia', 'Posso pedir pensão alimentícia para mim após a separação?', 'Sim. Os alimentos entre cônjuges ou companheiros são possíveis quando um deles não tem condições de prover o próprio sustento após a separação. O prazo e o valor variam conforme a duração da união, a dedicação ao lar, a capacidade de reinserção no mercado de trabalho e as circunstâncias do caso concreto. Consulte nossa equipe para avaliar sua situação.', 8, 0),
    array('familia', 'Por que é importante regulamentar a convivência do genitor com os filhos?', 'A regulamentação judicial da convivência é fundamental por três razões: (1) Segurança jurídica — evita conflitos sobre datas, horários e feriados, pois tudo fica estabelecido em decisão judicial; (2) Proteção da criança — garante que o filho mantenha vínculo saudável com ambos os genitores, direito fundamental previsto no ECA e na Constituição Federal; (3) Prevenção de alienação parental — com regras claras, fica mais difícil para qualquer genitor obstruir o convívio do outro.', 9, 1),
    array('familia', 'O genitor pode ver o filho mesmo sem pagar a pensão?', 'Sim. O direito de convivência e o dever alimentar são independentes — um não cancela o outro. O genitor inadimplente não perde o direito de ver o filho, assim como o guardião não pode usar o filho como moeda de troca para forçar o pagamento. Isso configura alienação parental. As duas questões devem ser tratadas separadamente na Justiça.', 10, 1),
    array('familia', 'O que é alienação parental e quais as consequências?', 'Alienação parental ocorre quando um genitor manipula a criança para que ela rejeite o outro — seja com falsas acusações, impedimento de visitas ou desqualificação constante. A Lei 12.318/2010 prevê punições severas: advertência, multa, inversão da guarda, suspensão da autoridade parental e até prisão. Se você está sofrendo isso, documente tudo e procure um advogado imediatamente.', 11, 1),
    array('familia', 'Como funciona a guarda compartilhada?', 'Desde a Lei 13.058/2014, a guarda compartilhada é a regra no Brasil. Significa que ambos os genitores exercem conjuntamente as decisões sobre a vida do filho (educação, saúde, lazer), mesmo que a criança resida predominantemente com um deles (lar de referência). Não se confunde com guarda alternada — o filho não fica necessariamente metade do tempo com cada genitor.', 12, 0),
    array('familia', 'Como funciona o divórcio?', 'O divórcio pode ser consensual (quando há acordo entre as partes sobre todos os pontos) ou litigioso (quando há conflito). O consensual é mais rápido — pode ser feito em cartório se não houver filhos menores. O litigioso tramita na Justiça. Em qualquer caso, é imprescindível a assistência de advogado.', 13, 0),
    array('familia', 'Preciso ir ao fórum pessoalmente?', 'Na maioria dos casos não. O processo tramita eletronicamente (PJe) e as audiências podem ser realizadas por videoconferência. Você pode acompanhar tudo pela Sala VIP do nosso portal, sem sair de casa.', 14, 0),
    array('familia', 'Quanto tempo dura um processo de família, aproximadamente?', 'A duração varia conforme o juiz responsável, a localidade e a complexidade do caso. O CNJ divulga pesquisas anuais sobre o tempo médio dos processos no Brasil. A última pesquisa demonstrou que um processo no Estado do Rio de Janeiro leva aproximadamente 3 anos e meio para ter sentença — sem contar os recursos, que podem prolongar ainda mais. Quanto antes você ingressar com a ação, mais cedo começa a contar o prazo.', 15, 0),

    // ═══ CONSUMIDOR ═══
    array('consumidor', 'Fui vítima de fraude no meu CPF. O que fazer?', 'Você pode buscar indenização por danos morais contra a instituição que permitiu a fraude, pois as empresas têm responsabilidade objetiva (independe de culpa) pela segurança dos dados de seus clientes. O primeiro passo é registrar boletim de ocorrência e reunir toda a documentação da fraude.', 1, 1),
    array('consumidor', 'O banco cobrou juros abusivos. Tenho direito à revisão?', 'Sim. Contratos bancários com juros acima da média de mercado divulgada pelo Banco Central podem ser objeto de ação revisional. Nosso escritório analisa o contrato e identifica cláusulas abusivas.', 2, 1),
    array('consumidor', 'Tive meu nome negativado indevidamente. O que fazer?', 'A negativação indevida gera dano moral presumido (in re ipsa) — não é necessário comprovar o prejuízo. É possível obter a exclusão imediata do nome dos cadastros e indenização por danos morais.', 3, 1),
    array('consumidor', 'Produto com defeito — quais são meus direitos?', 'O CDC garante ao consumidor o direito de exigir a substituição do produto, o abatimento proporcional do preço ou a devolução do valor pago, além de indenização por danos materiais e morais causados pelo defeito.', 4, 0),
    array('consumidor', 'Quanto tempo dura um processo do consumidor, aproximadamente?', 'A duração varia conforme o juiz responsável, a localidade e a complexidade do caso. Processos nos Juizados Especiais Cíveis tendem a ser mais rápidos. O CNJ divulga pesquisas anuais — a última demonstrou que um processo no Estado do Rio de Janeiro leva aproximadamente 3 anos e meio para ter sentença, sem contar os recursos.', 5, 0),

    // ═══ CÍVEL ═══
    array('civel', 'O que é dano moral?', 'Dano moral é a lesão a direitos da personalidade — honra, imagem, intimidade, dignidade. Em muitos casos é presumido, dispensando prova do sofrimento. O valor da indenização varia conforme a extensão do dano, a capacidade econômica do ofensor e o caráter pedagógico da condenação.', 1, 1),
    array('civel', 'Sofri um acidente. Tenho direito a indenização?', 'Sim, se houver culpa de terceiro. É possível pleitear indenização por danos materiais (despesas médicas, lucros cessantes), danos morais e estéticos. Em acidentes de trânsito, o seguro DPVAT também pode ser acionado.', 2, 1),
    array('civel', 'O que é usucapião?', 'É a aquisição da propriedade pelo uso prolongado e ininterrupto do imóvel, com requisitos específicos conforme a modalidade (ordinária, extraordinária, especial urbana/rural). Nosso escritório avalia a viabilidade do seu caso.', 3, 0),
    array('civel', 'Quanto tempo dura um processo cível, aproximadamente?', 'A duração varia conforme o juiz responsável, a localidade e a complexidade do caso. O CNJ divulga pesquisas anuais — a última demonstrou que um processo no Estado do Rio de Janeiro leva aproximadamente 3 anos e meio para ter sentença, sem contar os recursos, que podem prolongar consideravelmente o prazo.', 4, 0),

    // ═══ PREVIDENCIÁRIO ═══
    array('previdenciario', 'Tive meu benefício negado pelo INSS. E agora?', 'O indeferimento administrativo não é o fim. É possível recorrer administrativamente ao CRPS (Conselho de Recursos da Previdência Social) ou ingressar com ação judicial. Na maioria dos casos, a via judicial é mais eficaz e rápida.', 1, 1),
    array('previdenciario', 'Quem tem direito ao BPC/LOAS?', 'O Benefício de Prestação Continuada é devido à pessoa com deficiência ou idoso (65 anos ou mais) cuja renda familiar per capita seja inferior a 1/4 do salário mínimo. A análise é feita caso a caso — agende uma consulta.', 2, 1),
    array('previdenciario', 'Posso receber os atrasados do benefício?', 'Sim. Quando a ação é julgada procedente, o INSS é condenado a pagar as parcelas atrasadas desde a data do requerimento administrativo, acrescidas de juros e correção monetária.', 3, 1),
    array('previdenciario', 'Quanto tempo dura um processo previdenciário, aproximadamente?', 'A duração varia conforme o juiz responsável, a localidade e a complexidade do caso. Ações nos Juizados Especiais Federais (JEF) tendem a ser mais rápidas. O CNJ divulga pesquisas anuais — a última demonstrou que um processo no Estado do Rio de Janeiro leva aproximadamente 3 anos e meio para ter sentença, sem contar os recursos. Por isso é fundamental ingressar com a ação o quanto antes.', 4, 0),

    // ═══ IMOBILIÁRIO ═══
    array('imobiliario', 'O que é evicção?', 'É a perda total ou parcial de um bem adquirido em razão de decisão judicial que reconhece direito anterior de terceiro. O comprador prejudicado tem direito à restituição do preço, indenização por danos e outras verbas previstas no Código Civil.', 1, 0),
    array('imobiliario', 'Posso rescindir um contrato de locação antes do prazo?', 'Sim, mas pode haver multa proporcional ao tempo restante. Em casos específicos (transferência de emprego, por exemplo), a multa pode ser afastada. O contrato deve ser analisado para orientação precisa.', 2, 0),
    array('imobiliario', 'Quanto tempo dura um processo imobiliário, aproximadamente?', 'A duração varia conforme o juiz responsável, a localidade e a complexidade do caso. O CNJ divulga pesquisas anuais — a última demonstrou que um processo no Estado do Rio de Janeiro leva aproximadamente 3 anos e meio para ter sentença, sem contar os recursos.', 3, 0),
);

$stmt = $pdo->prepare("INSERT INTO salavip_faq (area, pergunta, resposta, ordem, ativo, destaque) VALUES (?, ?, ?, ?, 1, ?)");
$inserted = 0;
foreach ($faqs as $f) {
    $stmt->execute(array($f[0], $f[1], $f[2], $f[3], $f[4]));
    $inserted++;
}

echo "\nFAQs inseridas: {$inserted}\n";
echo "\n=== MIGRAÇÃO FAQ v2 CONCLUÍDA ===\n";
