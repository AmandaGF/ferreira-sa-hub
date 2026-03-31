-- ================================================================
-- FERREIRA & SA — Correcao Kanban v2
-- Vincula processos aos clientes pelo nome_pasta_drive
-- e corrige os cards pasta_apta que ja foram distribuidos
-- ================================================================

SET NAMES utf8mb4;

-- ================================================================
-- PASSO 1: Vincular processos a clientes (preencher cliente_id)
-- ================================================================

UPDATE processos p
SET p.cliente_id = (
  SELECT c.id FROM clientes c
  WHERE c.nome_pasta_drive = p.nome_pasta
  ORDER BY c.id LIMIT 1
)
WHERE p.cliente_id IS NULL
  AND EXISTS (
    SELECT 1 FROM clientes c2
    WHERE c2.nome_pasta_drive = p.nome_pasta
  );

-- Match parcial: nome do processo começa com nome do cliente
UPDATE processos p
SET p.cliente_id = (
  SELECT c.id FROM clientes c
  WHERE p.nome_pasta LIKE CONCAT(SUBSTRING(c.nome_completo,1,20),'%')
  ORDER BY c.id LIMIT 1
)
WHERE p.cliente_id IS NULL;

-- ================================================================
-- PASSO 2: Vincular kanban_cards operacionais aos clientes
-- ================================================================

UPDATE kanban_cards k
SET k.cliente_id = (
  SELECT p.cliente_id FROM processos p
  WHERE p.nome_pasta = (
    SELECT c.nome_pasta_drive FROM clientes c WHERE c.id = k.cliente_id LIMIT 1
  )
  AND p.cliente_id IS NOT NULL
  LIMIT 1
)
WHERE k.kanban = 'operacional'
  AND k.cliente_id IS NULL;

-- ================================================================
-- PASSO 3: Corrigir cards pasta_apta via nome_pasta_drive
-- ================================================================

UPDATE kanban_cards k
JOIN clientes c ON c.id = k.cliente_id
SET
  k.coluna_anterior = k.coluna_atual,
  k.coluna_atual    = 'processo_distribuido',
  k.observacao      = CONCAT(IFNULL(k.observacao,''), ' | Corrigido v2: processo ja distribuido')
WHERE k.kanban       = 'comercial_cx'
  AND k.coluna_atual = 'pasta_apta'
  AND c.nome_pasta_drive IN ('Kauan R','Joice Correa Cezario x Indenização','Raiane Martins Damasceno x Almentos','Joziane Lino x Inventário (ex do Eli)','Espólio Waldir x Inventário (parceria Bianca)','Ana Claudia Guimarães da Silva x Execução de Alimentos','Adriano Matheus Leoncio de Jesus x Execução de Alimentos - Rito de Prisão','Natan e Neusa x Usucapião','Dayane de Araújo Amancio x Execução de Alimentos','Irlei Ribeiro Souza x Consumidor (Carro)','Deise Cristina Ramos de Oliveira x Execução','Rodrigo França Penha x Resional de Alimentos','Lohana da Silva Teixeira de Paula x Execução','Josiana da Costa da Silva Correa x Alimentos','Dayane de Araújo Amancio x Desconto em Folha','Anderson Souza Candido x Consumidor','Rachel Nardelli Rosa x Defesa de Revisional','Luiz Eduardo de Sá x Ifood','MAISA SILVA SANTOS x Alimentos','Lorena Quintanilha Soares x Alimentos','Carolaine de Souza Barros x Investigação de Parternidade','Ruana Constantino Torres x Execução de Alimentos','Carina Marcelino x Divórcio','GABRIELE RIBEIRO GOMES x Alimentos','Tamara Vitória x Desc em folha e Execução','Arnaldo x Pensão (réu)','Thaissa Rocha Lima de Oliveira x Execução','Guilherme da Silva Benício x Guarda','Caroline da Silva David x Execução','Eliene Lima dos Santos Almeida x Descumprimento de sentença Reg. Visitas e Convivência');

UPDATE kanban_cards k
JOIN clientes c ON c.id = k.cliente_id
SET
  k.coluna_anterior = k.coluna_atual,
  k.coluna_atual    = 'processo_distribuido',
  k.observacao      = CONCAT(IFNULL(k.observacao,''), ' | Corrigido v2: processo ja distribuido')
WHERE k.kanban       = 'comercial_cx'
  AND k.coluna_atual = 'pasta_apta'
  AND c.nome_pasta_drive IN ('Emerson de Alvarenga de Macedo x Defesa de Alimentos','Monique Alves Carvalho x Revisional de Alimentos','Joseleia Moreira de Oliveira x BPC LOAS','Marceli Santos Marins x Alimentos','Luciana Diniz Barbosa x LOAS','Deise Ferreira do Nascimento x Convivência','Claudinei Marcolino x Alimentos','Wallace Gabriel Pereira Tamiozzo de Oliveira x Consumidor','STEPHANIA DOS SANTOS ROCHA x Alimentos','Vanessa de Souza x Alimentos','Fernanda Ribeiro de Oliveira x Execução','Rayane Rodrigues da Silva x Alimentos','Diogo Nascimento de Oliveira x Convivência','Thiago Melo x Audiência Cejusc 15/12 - Alimentos','Michele Ferreira da Conceição x Alimentos - Proc Cad no L1','Allan Louzada x Execução (Juliana)','Aila dos Santos Gaia x Execução de Alimentos','Mariele de Souza Barbosa x Investigação de Paternidade','Ana Paula Almeida Diniz x Danos Morais','Adriano da Silva Gomes x Defesa Cível','AILANDA ALINE LESSA FARIA','Gabriela Cristina de Oliveira Gonçalves x Auxílio Maternidade','Enayle Garcia Fontes x Execução Cível','Guilherme da Silva Benício x Execução de Alimentos - Já é nosso cliente','Valquíria da Silva Vieira x Def. Regulamentação de Convivência','KAMILA DA SILVA ELEOTÉRIO X Revisional','Aline Aparecida de Souza Vieira Malaquias x Guarda do Neto','Gabriela Cardoso Lobato x Alvará','Monique Alves Carvalho x Guarda e Visitação','Carlos Augusto Ferreira Monteiro x Defesa de Alimentos (Parceria Bianca)');

UPDATE kanban_cards k
JOIN clientes c ON c.id = k.cliente_id
SET
  k.coluna_anterior = k.coluna_atual,
  k.coluna_atual    = 'processo_distribuido',
  k.observacao      = CONCAT(IFNULL(k.observacao,''), ' | Corrigido v2: processo ja distribuido')
WHERE k.kanban       = 'comercial_cx'
  AND k.coluna_atual = 'pasta_apta'
  AND c.nome_pasta_drive IN ('Igor Yahnn Neves de Carvalho x Divórcio Extra','Cristiane Aniceto x Execução','Deise Cristina Ramos de Oliveira x 2° Aux Maternidade','Lorena x Execução - Já tem pasta em Investigação de Paternidade','Tamyris Carvalho e Carvalho x Alimentos','Amanda Gomes de Oliveira x Alimentos','Tiago x Execução (defesa)','Luiz Fernando de Souza Severino x Consumidor','Sara Ramão Silva x Alimentos','Sebastião de Oliveira x Alvará de FGTS','Marcia Aparecida Costa da Silva x Execução','PAOLA FERNANDA SILVA DE ASSIS x Alimentos','Peterson Pereira Mayworm x Consumidor','Taina Cristina de Lima dos Santos x Alimentos','Eduarda Honorato x Execução','JENIFER DA SILVA BARBOSA x Desc em Folha','AuraTijuca x MKB','Diana Alves Segales Ganduxe','Leonardo Ribeiro Braga x Defesa em Convivência','Cinthia Mara S. P. Gama x Consumidor','DÉBORA LOPES GONÇALVES x Plano de Saúde (tutela urgencia)','Queila Conceição dos Santos x Alimentos','JEOVANA MARCOLINO SOUZA DO CARMO x Alimentos','Márcia Honorato Nunes Medeiros x Advogada (com Flaviane)','Ana Claudia Guimarães x Alimentos','Gilmara Gonçalves x Execução de Alimentos (Pedro)','Gilmar Ferreira Gomes x Negatória de Paternidade','Douglas Silva de Souza x Defesa em Ação de Alimentos','Ester Gonsalves Ramão x Consumidor','Gildson Silva de Faria x Alimentos');

UPDATE kanban_cards k
JOIN clientes c ON c.id = k.cliente_id
SET
  k.coluna_anterior = k.coluna_atual,
  k.coluna_atual    = 'processo_distribuido',
  k.observacao      = CONCAT(IFNULL(k.observacao,''), ' | Corrigido v2: processo ja distribuido')
WHERE k.kanban       = 'comercial_cx'
  AND k.coluna_atual = 'pasta_apta'
  AND c.nome_pasta_drive IN ('Jorge Ferreira Gomes x Bancário (Flaviane)','Silvia Neurauter x GPX','Aline Fernandes Carvalho x Alimentos','Estoel Nathan Costa Silva x Alimentos','LORENA QUINTANILHA SOARES x REG CONVIVÊNCIA','Misã Guimarães Lopes x Oferecimento de Alimentos','Maurício Maia Ferreira x GPX','ESTER DOS SANTOS PATRICIO x Guarda','Natasha Carolina Pereira da Costa x Consumidor','BIBI - Leonardo Tavares x Cobrança','Allan Louzada x Revisional','Maria Clara Moreira de Lima x Alimentos','Claudinei x Execução Penhora','Maria Gilandia Barbosa Gomes x INSS','Thais Rodrigues da Silva x Alimentos','Diogo Nascimento de Oliveira x Guarda Unilateral','Sara Cristina de Souza Ribeiro x Alimentos','Bianca Nascimento Conceição x Alimentos','Kamilly Victoria Franco x Alimentos','Dayana Cabral Fernandes X Divórcio Litigioso','Gilmara Gonçalves x Alimentos Noah','Mariana Joana Pereira x Investigação de Paternidade','Jessica da Silva x Alimentos','Jessica Maiara Rocha da Costa x Alimentos Avoengos','Juliana Alves Santana x Investigação de Paternidade','Aghata Stefany Nascimento da Silva x Revisional','INDIANE CHRISTINE MORAIS DE SOUZA X Alimentos','ESTER DOS SANTOS PATRICIO x Convivência','Raphael de Souza Silva x Indenização','Célia Ferreira da Silva x Divórcio');

UPDATE kanban_cards k
JOIN clientes c ON c.id = k.cliente_id
SET
  k.coluna_anterior = k.coluna_atual,
  k.coluna_atual    = 'processo_distribuido',
  k.observacao      = CONCAT(IFNULL(k.observacao,''), ' | Corrigido v2: processo ja distribuido')
WHERE k.kanban       = 'comercial_cx'
  AND k.coluna_atual = 'pasta_apta'
  AND c.nome_pasta_drive IN ('Rita Pires x Inventário Tupery','Maria Girlandia Barbosa Gomes x Contestação','Maria Aparecida de Lima Cassimiro x Inventário Vitor','Cleitom Avelino Eduardo x Inventário','Karen Lopes dos Santos x Investigação de Paternidade','José Rafael da Silva (inventário - Manoel Mariano da Silva)','Rayane Rodrigues da Silva x Convivência','Mélodi Batista Cruz x Desconto em Folha','Mariella dos Santos x Convivência','DAYANA CABRAL FERNANDES - LOAS (CLARA)','Taina Cristina de Lima dos Santos x Investigação de Paternidade','Maria Marinete Lins Silva x Estacionamento Pátiomix','Maria Eduarda da Silva Sousa x Alimentos (Bernado)','Edilaine Ferreira Alves x Aux Maternidade','Aldo de Souza Louenço x Consumidor','Marcus Vinicius Gomes Boechat x Divórcio','ALINE APARECIDA DE SOUZA VIEIRA MALAQUIAS x Recurso de Apelação - URGENTE','Rafaella de Lima Benedito x Auxílio Maternidade','Joseleia Moreira de Oliveira x Ação Possessória','Joice Correa Cezario x Prorrogação de Auxílio Doença','Patrick Gomes das Graças Passifico x Defesa','Aline Fernandes Carvalho x Indenização DP','Ana Paula Almeida Diniz x Consumidor (Getnet)','Alexandro da Silva Martins x Defesa Criminal','Aline Aparecida da Silva x Execução de Alimentos','Ana Caroline Carvalho Neris Lourenço x Consumidor','Marcia Aparecida Costa da Silva x Desconto em Folha','Priscila Almeida Silva Anastácio x Alimentos','Lohana da Silva Teixeira de Paula x Desconto em Folha','Cezar Augusto Pereira de Carvalho x Consumidor (Geladeira)');

UPDATE kanban_cards k
JOIN clientes c ON c.id = k.cliente_id
SET
  k.coluna_anterior = k.coluna_atual,
  k.coluna_atual    = 'processo_distribuido',
  k.observacao      = CONCAT(IFNULL(k.observacao,''), ' | Corrigido v2: processo ja distribuido')
WHERE k.kanban       = 'comercial_cx'
  AND k.coluna_atual = 'pasta_apta'
  AND c.nome_pasta_drive IN ('Suelen da Costa Silva x Defesa de Guarda e Convivência','Gildson Silva de Faria x Convivência','Letícia Vitória dos Santos Avelino x GPX','CONDOMÍNIO AURA TIJUCA - ASSESSORIA JURÍDICA','Solange Sacramento Guedes Ferreira  x GPX','Vanderléia Américo x Investigação de Patrenidade Ana Allyce','Carlos Agusto Ferreira Monteiro x Divórcio (Indicação Bianca)','GISELE SILVA MACHADO AMARO CARDOZO x LOAS','Giseli Rodrigues Correa x Divórcio','Tamires da Silva x Execução','Jessica da Silva x Execução','Deise Ferreira do Nascimento x Alimentos','LEONARDO TAVARES FERREIRA X CONSUMIDOR (IND DANO MATERIAL)','Alícia de Carvalho Wogel x Consumidor (Fla Parceria)','Maria Clara Moreira de Lima x Divórcio','Leonardo da Silva Felipe x Defesa de Alimentos','Estoel Nathan Costa Silva x Guarda Compartilhada','Dayana Cabral Fernandes x Revisional Clara','Cleitom Avelino Eduardo x Regularização de Imóvel (CEF)','KAYLA KEROLAYNE BORGES DA SILVA x Alimentos Maitê','Nataly Cerqueira Mattos Silva x Defesa em Revisional de Convivência','ESTER DOS SANTOS PATRICIO x Alimentos','Anesio Francisco da Silva x Consumidor (Enel)','WILSON HELIO SILVA x Meta (Instagram)','Claudinei Marcolino x Regulamentação da Convivência','Leonardo Ribeiro Braga x Depoimento 89ª DP (Lucas)','Maria Isabela Elioterio Lima x Alimentos','Tamyris Carvalho e Carvalho x Convivência','ALANE SANTOS OLIVEIRA x Convivência','Maria Eduarda da Silva Sousax  Guarda Unilateral');

UPDATE kanban_cards k
JOIN clientes c ON c.id = k.cliente_id
SET
  k.coluna_anterior = k.coluna_atual,
  k.coluna_atual    = 'processo_distribuido',
  k.observacao      = CONCAT(IFNULL(k.observacao,''), ' | Corrigido v2: processo ja distribuido')
WHERE k.kanban       = 'comercial_cx'
  AND k.coluna_atual = 'pasta_apta'
  AND c.nome_pasta_drive IN ('Vanderleia Américo x Alimentos Lorenzo e Anthony','Giseli Rodrigues Correa x Alimentos','Lucca Celano Pereira Ribeiro x Defesa em Exoneração de Alimentos','Thamires Calabar x Alimentos','Renan Nascimento dos Santos x GPX (Danos Materiais)','Thaissa Rocha Lima de Oliveira x Desconto em Folha','Vagner de Oliveira Gama x Defesa em Execução','Thaissa Rocha Limas x Alimentos Avoengos','Dayana Cabral Fernandes x Execução','Juliana Matias Euclides x Execução','Diego Souza Villarinho x Benefício por incapacidade','Cineone x Execução de Alimentos','Aghata Stefany Nascimento da Silva x Execução','Jéssica Gregório da Silva Abreu x Divórcio','Nilton dos Santos Silva x Consumidor','Ivan de Souza Moraes x Defesa em Execução','Mariucha dos Santos Silva x Desconto em Folha','Gilmar Ferreira Gomes x Defesa em Alimentos','Suelen de Paula da Silva x Execução de Alimentos','Maria Eduarda da Silva Sousa x Convivência','Viviane Lyrio Barboza x Divórcio','Vagner de Oliveira Gama x Exoneração','Ivan de Souza Moraes x Exoneração','Ruana Constantino Torres x Desconto em Folha','Maria Clara Moreira x Regulamentação de Convivência','Sara Ramão Silva x Guarda','Carina Marcelino Correa x Alimentos','Yasmim Cruz da Silva x Execução de Alimentos','Francisco Tomazi Manoel x Defesa em Alimentos','Vitoria Aparecida Silva x Alimentos');

UPDATE kanban_cards k
JOIN clientes c ON c.id = k.cliente_id
SET
  k.coluna_anterior = k.coluna_atual,
  k.coluna_atual    = 'processo_distribuido',
  k.observacao      = CONCAT(IFNULL(k.observacao,''), ' | Corrigido v2: processo ja distribuido')
WHERE k.kanban       = 'comercial_cx'
  AND k.coluna_atual = 'pasta_apta'
  AND c.nome_pasta_drive IN ('Kaylane Rangel da Silva x Investigação de Paternidade','Paula Roberta Sodré x Rec. Maternidade','Mariana Joana Pereira x Alimentos','Maria Aparecida de Lima Cassimiro x Curetala (Vitor)','Rafaelly Sousa da Silva x Desconto em Folha e Execução','Weverton Jairo x Defesa Execução (Indicação Rejane)','Iara dos Santos Gonçalves x Alimentos','Rayane Joyce da Silva Machado x Alimentos','Felipe (Norma) - Consumidor (Carro)','Ana Carolina Bragança Pinto x Alimentos','Angela Louzada x Reg. de Convivência (Juliana)','Jaqueline Oliveira Silva Santos x Alimentos','Mariana Joana Pereira x Alimentos (Guilherme)','Lívia Silva Nunes Mendonça x Defesa Criminal','Valquiria da Silva x Vieira','Caroline de Souza Barros x Alimentos','Juliana de Souza Barbosa x Alimentos','Gisele Silva Machado Amaro Cardozo x Alimentos','Fernanda x Divórcio','Robson Machado Soares x Defesa em Guarda Unilateral','Bebiana de Oliveira Reis x Consumidor','Myllena x Leilão','Maiara Pereira Cardozo x Execução de Alimentos','Thiago Melo x Convivência','Amanda Ferreira de Limax Execução','Jéssica Ribeiro Honório x Investigação de Paternidade','Gisele Silva Machado Amaro Cardozo x Divórcio','Diogo Nascimento de Oliveira x Alimentos','Gabriela Cardoso Lobato x Alimentos','Cristina Mara Oliveira dos Santos x Alimentos');

UPDATE kanban_cards k
JOIN clientes c ON c.id = k.cliente_id
SET
  k.coluna_anterior = k.coluna_atual,
  k.coluna_atual    = 'processo_distribuido',
  k.observacao      = CONCAT(IFNULL(k.observacao,''), ' | Corrigido v2: processo ja distribuido')
WHERE k.kanban       = 'comercial_cx'
  AND k.coluna_atual = 'pasta_apta'
  AND c.nome_pasta_drive IN ('ALANE SANTOS OLIVEIRA x Alimentos','Eduarda Honorato Toledo x Guarda Unilateral','Cassiane Faria Conceição x Alimentos','Fernanda x Guarda','Jessica Regina dos Santos Machado x GPX','Rodrigo José do Nascimento Alves x Defesa Execução de Alimentos','Rosangela dos Santos x Trabalhista','Joice Correa Cezario x Conversão de Auxílio Doença','Thaís Beringhy dos Santos Barros x Alimentos','Erika de Sena Antunes x Alimentos','Leonardo Tavares Ferreira x Defesa JEC Consumidor','Fernanda x Pensão');

-- ================================================================
-- PASSO 4: Corrigir cards pasta_apta pelo nome do cliente (fallback)
-- ================================================================

UPDATE kanban_cards k
JOIN clientes c ON c.id = k.cliente_id
SET
  k.coluna_anterior = k.coluna_atual,
  k.coluna_atual    = 'processo_distribuido',
  k.observacao      = CONCAT(IFNULL(k.observacao,''), ' | Corrigido v2 por nome')
WHERE k.kanban       = 'comercial_cx'
  AND k.coluna_atual = 'pasta_apta'
  AND c.nome_completo IN ('WILSON HELIO SILVA','ESTER DOS SANTOS PATRICIO','Carlos Augusto Ferreira Monteiro','Raphael de Souza Silva','Dayane de Araújo Amancio x Execução de Alimentos','Kamilly Victoria Franco Duarte','Alícia de Carvalho Wogel','Claudinei','MAIARA PEREIRA CARDOZO','GISELI RODRIGUES CORREA','INDIANE CHRISTINE MORAIS DE SOUZA','ANA PAULA ALMEIDA DINIZ','Maria Eduarda da Silva Sousa','TAMYRIS CARVALHO E CARVALHO','CINTHIA MARA DA SILVA PINHEIRO GAMA','LIDIANE MARIA DE SOUZA','Dayane de Araújo Amancio x Desconto em Folha','Joseleia Moreira de Oliveira','MARIA CLARA MOREIRA DE LIMA','Lorena Quintanilha Soares x Alimentos','Ruana Constantino Torres x Execução de Alimentos','Arnaldo x Pensão (réu)','Gabriela Cristina de Oliveira Gonçalves','Adriano Matheus Leoncio de Jesus x Execução de Alimentos - Rito de Prisão','Marceli Santos Marins x Alimentos','Estoel Nathan Costa Silva','Juliana Alves Santana','Norma Monteiro Pereira Leite Machado','ALINE APARECIDA DE SOUZA VIEIRA MALAQUIAS','Vanderleia Américo');

UPDATE kanban_cards k
JOIN clientes c ON c.id = k.cliente_id
SET
  k.coluna_anterior = k.coluna_atual,
  k.coluna_atual    = 'processo_distribuido',
  k.observacao      = CONCAT(IFNULL(k.observacao,''), ' | Corrigido v2 por nome')
WHERE k.kanban       = 'comercial_cx'
  AND k.coluna_atual = 'pasta_apta'
  AND c.nome_completo IN ('Maria Marinete Lins Silva','AMANDA GOMES DE OLIVEIRA','Bianca Nascimento Conceição','Gilmar Ferreira Gomes','Sara Ramão da Silva','Allan Louzada x Execução (Juliana)','Ana Paula Almeida Diniz x Danos Morais','ESPÓLIO DE WALDIR ESTEVES GUIMARÃES','Anesio Francisco da Silva','DEISE FERREIRA DO NASCIMENTO','ROSANGELA DOS SANTOS','Peterson Pereira Mayworm','JÉSSICA MAIARA ROCHA DA COSTA','AILANDA ALINE LESSA FARIA','Enayle Garcia Fontes x Execução Cível','Mélodi Batista Cruz','Aline Aparecida da Silva','KAYLA KEROLAYNE BORGES DA SILVA','RAFAELLA DE LIMA BENEDITO','RAFAELLY SOUSA DA SILVA','Tamara Vitória de Souza Moreno','KAMILA DA SILVA ELEOTÉRIO X Revisional','Aldo de Souza Lourenço','Gilmara Gonçalves','JOSELEIA MOREIRA DE OLIVEIRA','SEBASTIÃO DE OLIVEIRA','Igor Yahnn Neves de Carvalho x Divórcio Extra','RAIANE MARTINS DAMASCENO','LUCCA CELANO PEREIRA RIBEIRO','Anderson Silva de Souza Candido');

UPDATE kanban_cards k
JOIN clientes c ON c.id = k.cliente_id
SET
  k.coluna_anterior = k.coluna_atual,
  k.coluna_atual    = 'processo_distribuido',
  k.observacao      = CONCAT(IFNULL(k.observacao,''), ' | Corrigido v2 por nome')
WHERE k.kanban       = 'comercial_cx'
  AND k.coluna_atual = 'pasta_apta'
  AND c.nome_completo IN ('DÉBORA LOPES GONÇALVES','GILDSON SILVA DE FARIA','CINEONE DA CONCEIÇÃO LIMA','Tiago x Execução (defesa)','Solange Sacramento Guedes Ferreira','PAOLA FERNANDA SILVA DE ASSIS x Alimentos','Ana Caroline Carvalho Neris Lourenço','Rodrigo França Penha','Claudinei Marcolino','Cleitom Avelino Eduardo','Diana Alves Segales Ganduxe','CRISTIANE ANICETO DA COSTA','Angela de Oliveira Louzada Costa','Queila Conceição dos Santos','Valquiria da Silva Vieira','Michele Ferreira da Conceição','Ana Claudia Guimarães x Alimentos','STEPHANIA DOS SANTOS ROCHA','Douglas Silva de Souza x Defesa em Ação de Alimentos','Allan Louzada','Silvia Neurauter x GPX','NATAN e NEUSA','Aline Fernandes Carvalho x Alimentos','EMERSON ALVARENGA DE MACEDO','Márcia Aparecida Costa da Silva','Lohana da Silva Teixeira de Paula','Joziane da Silveira Lino','SUELEN DE PAULA DA SILVA','EDILAINE FERREIRA ALVES','MARCIA HONORATO NUNES MEDEIROS');

UPDATE kanban_cards k
JOIN clientes c ON c.id = k.cliente_id
SET
  k.coluna_anterior = k.coluna_atual,
  k.coluna_atual    = 'processo_distribuido',
  k.observacao      = CONCAT(IFNULL(k.observacao,''), ' | Corrigido v2 por nome')
WHERE k.kanban       = 'comercial_cx'
  AND k.coluna_atual = 'pasta_apta'
  AND c.nome_completo IN ('THAISSA ROCHA LIMA DE OLIVEIRA','VALQUÍRIA DA SILVA VIERA','Rayane Joyce da Silva Machado','GABRIELE RIBEIRO GOMES','Maria Clara Moreira de Lima','Kaylane Rangel da Silva','Luiz Eduardo de Sá','Gabriela Cardoso Lobato','Nataly Cerqueira Mattos Silva','Leonardo Ribeiro Braga','Juliana Matias Euclides','CONDOMÍNIO AURA TIJUCA','Josiana da Costa da Silva Correa','CASSIANE FARIA CONCEIÇÃO','Mariele de Souza Barbosa','Jéssica Ribeiro Honório','Erika de Sena Antunes x Alimentos','Iara Dos Santos Gonçalves','JULIANA FERREIRA DE SOUZA BARBOSA','BEBIANA DE OLIVEIRA REIS','MONIQUE ALVES CARVALHO','Ester Gonsalves Romão','Patrick Gomes das Graças Passifico','Carina Marcelino Correa','Aghata Stefany Nascimento da Silva','Aline Fernandes Carvalho x Indenização DP','Joice Correa Cezario','LUCIANA DINIZ BARBOSA','Jaqueline Oliveira Silva Santos','Priscila Almeida Silva Anastácio x Alimentos');

UPDATE kanban_cards k
JOIN clientes c ON c.id = k.cliente_id
SET
  k.coluna_anterior = k.coluna_atual,
  k.coluna_atual    = 'processo_distribuido',
  k.observacao      = CONCAT(IFNULL(k.observacao,''), ' | Corrigido v2 por nome')
WHERE k.kanban       = 'comercial_cx'
  AND k.coluna_atual = 'pasta_apta'
  AND c.nome_completo IN ('IVAN DE SOUZA MORAES','Maria Girlandia Barbosa Gomes','Paula Roberta Sodré de Almeida Querino','Rita Pires dos Santos','Diego de Souza Villarinho','Letícia Vitória dos Santos Avelino x GPX','Carolaine de Souza Barros','AuraTijuca (mensalistA)','Caroline da Silva David','ANA CLAUDIA GUIMARÃES DA SILVA','VAGNER DE OLIVEIRA GAMA','Deise Cristina Ramos de Oliveira','KAREN LOPES DOS SANTOS','ALANE SANTOS OLIVEIRA','Nilton dos Santos Silva','Thaís Beringhy dos Santos Barros','MAISA SILVA SANTOS','Wallace Gabriel Pereira Tamiozzo de Oliveira','Rodrigo José do Nascimento Alves','GISELE SILVA MACHADO AMARO CARDOZO','Lívia Silva Nunes Mendonça','Rayane rodrigues da Silva','Thaissa Rocha Lima de Oliveira','Guilherme da Silva Benício','Fernanda Ribeiro de Oliveira','Thamires Calabar x Alimentos','Renan Nascimento dos Santos x GPX (Danos Materiais)','JEOVANA MARCOLINO SOUZA DO CARMO','JOICE CORREA CEZARIO','ALEXANDRO DA SILVA MARTINS');

UPDATE kanban_cards k
JOIN clientes c ON c.id = k.cliente_id
SET
  k.coluna_anterior = k.coluna_atual,
  k.coluna_atual    = 'processo_distribuido',
  k.observacao      = CONCAT(IFNULL(k.observacao,''), ' | Corrigido v2 por nome')
WHERE k.kanban       = 'comercial_cx'
  AND k.coluna_atual = 'pasta_apta'
  AND c.nome_completo IN ('Natasha Carolina Pereira da Costa','SARAH CRISTINA DE SOUZA RIBEIRO,','Jéssica Gregório da Silva Abreu','Vanessa de Souza','Misã Guimarães Lopes','Ruana Constantino Torres x Desconto em Folha','Maria Aparecida de Lima Cassimiro','Vitória Aparecida da Silva','SUELEN DA COSTA SILVA','Mariana Joana Pereira','WEVERTON JAIRO ARTHUR DOS SANTOS','AILA DOS SANTOS GAIA','KAUAN RODRIGUES DE SOUZA','Mariucha dos Santos Silva','JENIFER DA SILVA BARBOSA','Marcus Vinicius Gomes Boechat','Eliene Lima dos Santos Almeida','Jéssica da Silva','Yasmim Cruz da Silva','Ana Carolina Bragança Pinto x Alimentos','Lorena Quintanilha Soares','José Rafael da Silva (inventário - Manoel Mariano da Silva) - indicação Vagner','AMANDA FERREIRA DE LIMA','Mariella dos Santos Silva','LEONARDO RIBEIRO BRAGA','LEONARDO TAVARES FERREIRA','MARIA ISABELA ELIOTERIO LIMA TINOCO','TAINA CRISTINA DE LIMA DOS SANTOS','Adriano da Silva Gomes','Fernanda x Divórcio');

UPDATE kanban_cards k
JOIN clientes c ON c.id = k.cliente_id
SET
  k.coluna_anterior = k.coluna_atual,
  k.coluna_atual    = 'processo_distribuido',
  k.observacao      = CONCAT(IFNULL(k.observacao,''), ' | Corrigido v2 por nome')
WHERE k.kanban       = 'comercial_cx'
  AND k.coluna_atual = 'pasta_apta'
  AND c.nome_completo IN ('Myllena x Leilão','IRLEI RIBEIRO SOUZA','Dayana Cabral Fernandes','Thiago Guimarães de Melo','Francisco Tomazi Manoel','Jorge Ferreira Gomes','LORENA QUINTANILHA SOARES','ROBSON MACHADO SOARES','Rachel Nardelli Rosa','EDUARDA HONORATO TOLEDO','Rayane Aparecida Pereira da Silva','TAMIRES DA SILVA','CÉLIA FERREIRA DA SILVA','Viviane Lyrio Barboza','Cristina Mara Oliveira dos Santos x Alimentos','Diogo Nascimento de Oliveira','Cezar Augusto Pereira de Carvalho','MAURÍCIO MAIA FERREIRA','Thais Rodrigues da Silva','Fernanda x Guarda','Jessica Regina dos Santos Machado x GPX','DAYANA CABRAL FERNANDES','Leonardo da Silva Felipe','Fernanda x Pensão','Luiz Fernando de Souza Severino');

-- ================================================================
-- PASSO 5: Log de auditoria
-- ================================================================

INSERT INTO log_auditoria (acao, kanban, coluna_origem, coluna_destino, gatilhos)
VALUES (
  'CORRECAO_PASTA_APTA_V2',
  'comercial_cx',
  'pasta_apta',
  'processo_distribuido',
  CONCAT('Correcao v2 em ', NOW(), ' — vinculacao por nome_pasta_drive e nome_completo')
);

-- ================================================================
-- VERIFICACAO FINAL
-- ================================================================
-- SELECT coluna_atual, COUNT(*) as qtd
--   FROM kanban_cards WHERE kanban='comercial_cx'
--   GROUP BY coluna_atual ORDER BY qtd DESC;
--
-- Esperado:
-- pasta_apta            ~130 (ativos reais + sem processo)
-- processo_distribuido  ~267 (inclui os 12 ja corrigidos)
-- elaboracao_procuracao  ~114
-- cancelado              ~108
-- reuniao_cobrando_docs  ~16
-- suspenso               ~6
-- ================================================================