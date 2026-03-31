Execute o arquivo correcao_final_portal.sql em duas etapas:
Etapa 1: execute apenas o SELECT do PASSO 1 e me diz quantas linhas retornou.
Não execute o UPDATE ainda — só me mostre o número.-- ================================================================
-- Ferreira & Sa — Correcao Kanban Operacional
-- Move casos de 'pasta_apta' para 'processo_distribuido'
-- Baseado nos 439 processos com verde riscado na planilha original
-- ================================================================

-- PASSO 1: CONFERIR — Execute este SELECT primeiro
-- Anote o numero de linhas retornadas antes de executar o UPDATE

SELECT id, title, status, created_at, case_number
FROM cases
WHERE status = 'pasta_apta'
  AND (
  title LIKE 'Giseli Rodrigues Correa x Divó%'
  OR
  title LIKE 'Cinthia Mara S. P. Gama x Cons%'
  OR
  title LIKE 'Anderson Souza Candido x Consu%'
  OR
  title LIKE 'Gildson Silva de Faria x Convi%'
  OR
  title LIKE 'Misã Guimarães Lopes x Ofereci%'
  OR
  title LIKE 'Thaissa Rocha Limas x Alimento%'
  OR
  title LIKE 'Gilmar Ferreira Gomes x Negató%'
  OR
  title LIKE 'Queila Conceição dos Santos x%'
  OR
  title LIKE 'Kamilly Victoria Franco x Alim%'
  OR
  title LIKE 'Natasha Carolina Pereira da Co%'
  OR
  title LIKE 'Julio Cesar Correa e Castro -%'
  OR
  title LIKE 'Josiana da Costa da Silva Corr%'
  OR
  title LIKE 'Cleitom Avelino Eduardo x Inve%'
  OR
  title LIKE 'Eliene Lima dos Santos Almeida%'
  OR
  title LIKE 'Alícia de Carvalho Wogel x Con%'
  OR
  title LIKE 'JENIFER DA SILVA BARBOSA x Des%'
  OR
  title LIKE 'Maria Eduarda da Silva Sousa x%'
  OR
  title LIKE 'CONDOMÍNIO AURA TIJUCA - ASSES%'
  OR
  title LIKE 'Aghata Stefany Nascimento da S%'
  OR
  title LIKE 'AuraTijuca x MKB%'
  OR
  title LIKE 'ESTER DOS SANTOS PATRICIO x Gu%'
  OR
  title LIKE 'ESTER DOS SANTOS PATRICIO x Co%'
  OR
  title LIKE 'Estoel Nathan Costa Silva x Al%'
  OR
  title LIKE 'DÉBORA LOPES GONÇALVES x Plano%'
  OR
  title LIKE 'MARIA CLARA MOREIRA DE LIMA x%'
  OR
  title LIKE 'Fernanda Silva Rabetine Junque%'
  OR
  title LIKE 'Marcus Vinicius Gomes Boechat%'
  OR
  title LIKE 'ADRIANO MATHEUS LEONCIO DE JES%'
  OR
  title LIKE 'Maria Aparecida de Lima Cassim%'
  OR
  title LIKE 'Raphael de Souza Silva x Inden%'
  OR
  title LIKE 'Adriano Luis de Oliveira x Ind%'
  OR
  title LIKE 'Jorge Ferreira Gomes x Bancári%'
  OR
  title LIKE 'Irlei Ribeiro Souza x Consumid%'
  OR
  title LIKE 'Maria Isabela Elioterio Lima x%'
  OR
  title LIKE 'Mélodi Batista Cruz x Desconto%'
  OR
  title LIKE 'Vanderleia Américo x Alimentos%'
  OR
  title LIKE 'Peterson Pereira Mayworm x Con%'
  OR
  title LIKE 'Aline Aparecida da Silva x Exe%'
  OR
  title LIKE 'Juliana Alves Santana x Invest%'
  OR
  title LIKE 'Leonardo da Silva Felipe x Def%'
  OR
  title LIKE 'Rayane Aparecida Pereira da Si%'
  OR
  title LIKE 'Yasmim Cruz da Silva x Execuçã%'
  OR
  title LIKE 'Cleitom Avelino Eduardo x Noti%'
  OR
  title LIKE 'Rodrigo França Penha x Resiona%'
  OR
  title LIKE 'Gabriela Cardoso Lobato x Alim%'
  OR
  title LIKE 'ALINE APARECIDA DE SOUZA VIEIR%'
  OR
  title LIKE 'Nataly Cerqueira Mattos Silva%'
  OR
  title LIKE 'Adriano da Silva Gomes x Defes%'
  OR
  title LIKE 'Rayane Rodrigues da Silva x Al%'
  OR
  title LIKE 'WILSON HELIO SILVA x Meta (Ins%'
  OR
  title LIKE 'GABRIELE RIBEIRO GOMES x Alime%'
  OR
  title LIKE 'Nilton dos Santos Silva x Cons%'
  OR
  title LIKE 'ALANE SANTOS OLIVEIRA x Alimen%'
  OR
  title LIKE 'Rachel Nardelli Rosa x Defesa%'
  OR
  title LIKE 'Maria Aparecida Corêa e Castro%'
  OR
  title LIKE 'Tamires da Silva x Execução%'
  OR
  title LIKE 'Guilherme da Silva Benício x G%'
  OR
  title LIKE 'Deise Ferreira do Nascimento x%'
  OR
  title LIKE 'Tamyris Carvalho e Carvalho x%'
  OR
  title LIKE 'Vanessa de Souza x Alimentos%'
  OR
  title LIKE 'Célia Ferreira da Silva x Divó%'
  OR
  title LIKE 'Sebastião de Oliveira x Alvará%'
  OR
  title LIKE 'Rayane Joyce da Silva Machado%'
  OR
  title LIKE 'Gilmara Gonçalves x Alimentos%'
  OR
  title LIKE 'Diogo Nascimento de Oliveira x%'
  OR
  title LIKE 'Cristiane Aniceto x Execução%'
  OR
  title LIKE 'Amanda Gomes de Oliveira x Ali%'
  OR
  title LIKE 'YOHANNA MARTINS ALEXANDRE DA S%'
  OR
  title LIKE 'Thais Rodrigues da Silva x Ali%'
  OR
  title LIKE 'Emerson de Alvarenga de Macedo%'
  OR
  title LIKE 'Vagner de Oliveira Gama x Exon%'
  OR
  title LIKE 'Jaqueline Oliveira Silva Santo%'
  OR
  title LIKE 'Gildson Silva de Faria x Alime%'
  OR
  title LIKE 'Michele Ferreira da Conceição%'
  OR
  title LIKE 'Rodrigo José do Nascimento Alv%'
  OR
  title LIKE 'Gabriela Cardoso Lobato x Alva%'
  OR
  title LIKE 'Leonardo (DIVÓRCIO) - Notifica%'
  OR
  title LIKE 'Joziane Lino x Inventário (ex%'
  OR
  title LIKE 'Sara Ramão Silva x Guarda%'
  OR
  title LIKE 'Aldo de Souza Louenço x Consum%'
  OR
  title LIKE 'INDIANE CHRISTINE MORAIS DE SO%'
  OR
  title LIKE 'Vagner de Oliveira Gama x Defe%'
  OR
  title LIKE 'Gilmar Ferreira Gomes x Defesa%'
  OR
  title LIKE 'Angela Rodrigues de Oliveira x%'
  OR
  title LIKE 'Mariana Joana Pereira x Invest%'
  OR
  title LIKE 'Juliana Matias Euclides x Exec%'
  OR
  title LIKE 'Filipe Cristiano x Trabalhista%'
  OR
  title LIKE 'Amanda Ferreira de Limax Execu%'
  OR
  title LIKE 'Cineone x Execução de Alimento%'
  OR
  title LIKE 'Ana Caroline Carvalho Neris Lo%'
  OR
  title LIKE 'Iara dos Santos Gonçalves x Al%'
  OR
  title LIKE 'Ester Gonsalves Ramão x Consum%'
  OR
  title LIKE 'Tamara Vitória x Desc em folha%'
  OR
  title LIKE 'Bianca Nascimento Conceição x%'
  OR
  title LIKE 'Rita Pires x Inventário Tupery%'
  OR
  title LIKE 'Diana Alves Segales Ganduxe%'
  OR
  title LIKE 'Ivan de Souza Moraes x Exonera%'
  OR
  title LIKE 'Rosangela dos Santos x Trabalh%'
  OR
  title LIKE 'Joseleia Moreira de Oliveira x%'
  OR
  title LIKE 'Thaissa Rocha Lima de Oliveira%'
  OR
  title LIKE 'Gisele Silva Machado Amaro Car%'
  OR
  title LIKE 'Dayana Cabral Fernandes X Divó%'
  OR
  title LIKE 'Dayana Cabral Fernandes x Exec%'
  OR
  title LIKE 'Antoninha (em fase de acordo)%'
  OR
  title LIKE 'Caroline da Silva David x Exec%'
  OR
  title LIKE 'Maria Marinete Lins Silva x Es%'
  OR
  title LIKE 'Eli Manoel x Dissolução de Uni%'
  OR
  title LIKE 'Rafaella de Lima Benedito x Au%'
  OR
  title LIKE 'Luana Frias x Parceria - Cidad%'
  OR
  title LIKE 'Jessica Maiara Rocha da Costa%'
  OR
  title LIKE 'Carina Marcelino x Divórcio%'
  OR
  title LIKE 'Carina Marcelino Correa x Alim%'
  OR
  title LIKE 'Vitoria Aparecida Silva x Alim%'
  OR
  title LIKE 'Sara Cristina de Souza Ribeiro%'
  OR
  title LIKE 'Geyza x Trabalhista (Bianca)%'
  OR
  title LIKE 'Suelen da Costa Silva x Defesa%'
  OR
  title LIKE 'Ivan de Souza Moraes x Defesa%'
  OR
  title LIKE 'Leonardo Ribeiro Braga x Depoi%'
  OR
  title LIKE 'Leonardo Ribeiro Braga x Defes%'
  OR
  title LIKE 'Francisco Tomazi Manoel x Defe%'
  OR
  title LIKE 'Robson Machado Soares x Defesa%'
  OR
  title LIKE 'Weverton Jairo x Defesa Execuç%'
  OR
  title LIKE 'Thiago Melo x Audiência Cejusc%'
  OR
  title LIKE 'Gabriela Cristina de Oliveira%'
  OR
  title LIKE 'Natan e Neusa x Usucapião%'
  OR
  title LIKE 'Mariella dos Santos Silva x Re%'
  OR
  title LIKE 'JEOVANA MARCOLINO SOUZA DO CAR%'
  OR
  title LIKE 'Kaylane Rangel da Silva x Inve%'
  OR
  title LIKE 'Caroline de Souza Barros x Ali%'
  OR
  title LIKE 'Sara Ramão Silva x Alimentos%'
  OR
  title LIKE 'Paula Roberta Sodré x Rec. Mat%'
  OR
  title LIKE 'José Rafael da Silva (inventár%'
  OR
  title LIKE 'Diego Souza Villarinho x Benef%'
  OR
  title LIKE 'Carolaine de Souza Barros x In%'
  OR
  title LIKE 'Lohana da Silva Teixeira de Pa%'
  OR
  title LIKE 'Ana Paula Almeida Diniz x Cons%'
  OR
  title LIKE 'Guilherme da Silva Benício x E%'
  OR
  title LIKE 'Bebiana de Oliveira Reis x Con%'
  OR
  title LIKE 'Luiz Fernando de Souza Severin%'
  OR
  title LIKE 'Lorena x Execução - Já tem pas%'
  OR
  title LIKE 'Eduarda Honorato Toledo x Guar%'
  OR
  title LIKE 'Beatriz dos Santos Oliveira x%'
  OR
  title LIKE 'Marcia Aparecida Costa da Silv%'
  OR
  title LIKE 'Claudinei x Execução Penhora%'
  OR
  title LIKE 'Mariana Joana Pereira x Alimen%'
  OR
  title LIKE 'Jessica da Silva x Execução%'
  OR
  title LIKE 'Jéssica Gregório da Silva Abre%'
  OR
  title LIKE 'Rodrigo da Conceição Lopes x E%'
  OR
  title LIKE 'Jessica da Silva x Alimentos%'
  OR
  title LIKE 'Felipe (Norma) - Consumidor (C%'
  OR
  title LIKE 'Joice Correa Cezario x Indeniz%'
  OR
  title LIKE 'Juliana de Souza Barbosa x Ali%'
  OR
  title LIKE 'Viviane Lyrio Barboza x Divórc%'
  OR
  title LIKE 'Espólio Waldir x Inventário (p%'
  OR
  title LIKE 'Jéssica Ribeiro Honório x Inve%'
  OR
  title LIKE 'Arnaldo x Pensão (réu)%'
  OR
  title LIKE 'Myllena x Leilão%'
  OR
  title LIKE 'Maria Girlandia Barbosa Gomes%'
  OR
  title LIKE 'MAISA SILVA SANTOS x Alimentos%'
  OR
  title LIKE 'Maria Clara Moreira x Regulame%'
  OR
  title LIKE 'JENNIFER DE PAULA x Desconto e%'
  OR
  title LIKE 'JENNIFER DE PAULA x Execução d%'
  OR
  title LIKE 'Angela Louzada x Reg. de Convi%'
  OR
  title LIKE 'Luiz Eduardo de Sá x Ifood%'
  OR
  title LIKE 'Allan Louzada x Execução (Juli%'
  OR
  title LIKE 'Fernanda Ribeiro de Oliveira x%'
  OR
  title LIKE 'Mariella dos Santos Silva x Di%'
  OR
  title LIKE 'Taina Cristina de Lima dos San%'
  OR
  title LIKE 'Wallace Gabriel Pereira Tamioz%'
  OR
  title LIKE 'AILANDA ALINE LESSA FARIA%'
  OR
  title LIKE 'Leonardo Tavares Ferreira x De%'
  OR
  title LIKE 'Mariele de Souza Barbosa x Ali%'
  OR
  title LIKE 'Mariucha dos Santos Silva x De%'
  OR
  title LIKE 'Carlos Agusto Ferreira Monteir%'
  OR
  title LIKE 'Maiara Pereira Cardozo x Execu%'
  OR
  title LIKE 'Felipe Guimarães - Of. Aliment%'
  OR
  title LIKE 'Enayle Garcia Fontes x Execuçã%'
  OR
  title LIKE 'Dayane de Araújo Amancio  x Ex%'
  OR
  title LIKE 'KAUAN RODRIGUES DE SOUZA x GPX%'
  OR
  title LIKE 'Maurício Maia Ferreira x GPX%'
  OR
  title LIKE 'Mariele de Souza Barbosa x Inv%'
  OR
  title LIKE 'Mariella dos Santos Silva x Al%'
  OR
  title LIKE 'Patrick Gomes das Graças Passi%'
  OR
  title LIKE 'Giseli Rodrigues Correa x Alim%'
  OR
  title LIKE 'Raiane Martins Damasceno x Alm%'
  OR
  title LIKE 'Márcia Honorato Nunes Medeiros%'
  OR
  title LIKE 'Andriw Peixoto Pereira x Disso%'
  OR
  title LIKE 'Monique Alves Carvalho x Guard%'
  OR
  title LIKE 'Carlos Augusto Ferreira Montei%'
  OR
  title LIKE 'Ana Claudia Guimarães da Silva%'
  OR
  title LIKE 'Anesio Francisco da Silva x Co%'
  OR
  title LIKE 'STEPHANIA DOS SANTOS ROCHA x A%'
  OR
  title LIKE 'Thaís Beringhy dos Santos Barr%'
  OR
  title LIKE 'Valquíria da Silva Vieira x De%'
  OR
  title LIKE 'Geyza - Combo Família%'
  OR
  title LIKE 'Thaise (0006243-53.2019.8.19.0%'
  OR
  title LIKE 'Igor Yahnn Neves de Carvalho x%'
  OR
  title LIKE 'Renan Nascimento dos Santos x%'
  OR
  title LIKE 'Fernanda x Divórcio%'
  OR
  title LIKE 'Fernanda x Guarda%'
  OR
  title LIKE 'Jessica Regina dos Santos Mach%'
  OR
  title LIKE 'Aline Fernandes Carvalho x Ind%'
  OR
  title LIKE 'Thainá de Castro x Investigaçã%'
  OR
  title LIKE 'Raul x Dissolução UE%'
  OR
  title LIKE 'Thaynara x Responsabilidade Ci%'
  OR
  title LIKE 'Dayane de Araújo Amancio  x Re%'
  OR
  title LIKE 'Paulo Sérgio x Investigação%'
  OR
  title LIKE 'Denilson x GPX%'
  OR
  title LIKE 'Cassiane Faria Conceição x Ali%'
  OR
  title LIKE 'Thaise Marques (Academia VR)%'
  OR
  title LIKE 'Suelen de Paula da Silva x Exe%'
  OR
  title LIKE 'Aila dos Santos Gaia x Execuçã%'
  OR
  title LIKE 'Luciana Diniz Barbosa x LOAS%'
  OR
  title LIKE 'Lívia Silva Nunes Mendonça x D%'
  OR
  title LIKE 'Claudinei Marcolino x Alimento%'
  OR
  title LIKE 'Claudinei Marcolino x Regulame%'
  OR
  title LIKE 'Thiago Melo x Convivência%'
  OR
  title LIKE 'Deise Cristina Ramos de Olivei%'
  OR
  title LIKE 'Kênia Domingos do Nascimento x%'
  OR
  title LIKE 'Cezar Augusto Pereira de Carva%'
  OR
  title LIKE 'Inicial Elaine - Alimentos%'
  OR
  title LIKE 'Inicial Elaine - Tentativa de%'
  OR
  title LIKE 'Priscila (imobiliária) - Entra%'
  OR
  title LIKE 'Ângela - Notificação Extrajudi%'
  OR
  title LIKE 'Letícia Vitória dos Santos Ave%'
  OR
  title LIKE 'Francimeire (cobrança - execuç%'
  OR
  title LIKE 'Ana Paula Almeida Diniz x Dano%'
  OR
  title LIKE 'Ana Carolina Bragança Pinto x%'
  OR
  title LIKE 'Lorena Quintanilha Soares x In%'
  OR
  title LIKE 'Tiago x Execução (defesa)%'
  OR
  title LIKE 'Silvia Neurauter x GPX%'
  OR
  title LIKE 'Fernanda x Pensão%'
  OR
  title LIKE 'Priscila Almeida Silva Anastác%'
  OR
  title LIKE 'Ruana x Desconto em Folha%'
  OR
  title LIKE 'Ruana x Execução%'
  OR
  title LIKE 'Silzy (Ré) x GPX (FIZEMOS ACOR%'
  OR
  title LIKE 'Douglas Silva de Souza x Alime%'
  OR
  title LIKE 'Cristina Mara Oliveira x Alime%'
  OR
  title LIKE 'Karine Souza x GPX%'
  OR
  title LIKE 'Alex Sander x Exoneração%'
  OR
  title LIKE 'Vanessa Moraes x GPX%'
  OR
  title LIKE 'Maiza x Revisional%'
  OR
  title LIKE 'Renata Silva x Alimentos%'
  OR
  title LIKE 'Jéssica Cristina%'
  OR
  title LIKE 'Eduarda Honorato - Execução%'
  OR
  title LIKE 'Rafaelly Sousa da Silva x Desc%'
  OR
  title LIKE 'Lidiane Maria - EXECUÇÃO%'
  OR
  title LIKE 'AURATIJUCA - EXECUÇÃO%'
  OR
  title LIKE 'Joyce%'
  OR
  title LIKE 'Karen Lopes dos Santos x Inves%'
  OR
  title LIKE 'Kezia de Souza Costa x Aliment%'
  OR
  title LIKE 'Kezia de Souza Costa x Execuçã%'
  OR
  title LIKE 'Lucca Celano Pereira Ribeiro x%'
  OR
  title LIKE 'Solange Sacramento Guedes Ferr%'
  OR
  title LIKE 'Ruana Constantino Torres x Exe%'
  OR
  title LIKE 'Valquiria da Silva x Vieira%'
  OR
  title LIKE 'Alexandro da Silva Martins x D%'
  OR
  title LIKE 'Alvará FGTS Welsey (pai da And%'
  OR
  title LIKE 'Marli - Consumidor Enel%'
  OR
  title LIKE 'Processo Rayane (carro)%'
  OR
  title LIKE 'JOYCE CANDIDO TEIXEIRA - Revis%'
  OR
  title LIKE 'Eli x IPVA%'
  OR
  title LIKE 'KAYLA KEROLAYNE x Alimentos%'
  OR
  title LIKE 'ENI SACRAMENTO - APELAÇÃO%'
  OR
  title LIKE 'Kayla Kerolayne x Pensão%'
  OR
  title LIKE 'Guilherme Benício x Pensão%'
  OR
  title LIKE 'Sebastião (Débora) x Consumido%'
  OR
  title LIKE 'Nativânia x GPX%'
  OR
  title LIKE 'Wallace dos Santos x Trabalhis%'
  OR
  title LIKE 'Angela Rodrigues x Alimentos%'
  OR
  title LIKE 'PAOLA FERNANDA SILVA DE ASSIS%'
  OR
  title LIKE 'KAMILA DA SILVA ELEOTÉRIO X Re%'
  OR
  title LIKE 'EDUARDA HONORATO - Habilitação%'
  OR
  title LIKE 'LUANA - pensão%'
  OR
  title LIKE 'HARLESON - provas + reiterar d%'
  OR
  title LIKE 'Thamires Calabar x Alimentos%'
  OR
  title LIKE 'Ana Claudia Guimarães x Alimen%'
  OR
  title LIKE 'Aline Fernandes Carvalho x Exe%'
  OR
  title LIKE 'GEYZA - ATULIZAR L1 - PEDI HAB%'
  OR
  title LIKE 'Carlos - Inversão da guarda -%'
  OR
  title LIKE 'Erika de Sena Antunes x Alimen%'
  OR
  title LIKE 'CLEIDE RÉPLICA - ALIMENTOS%'
  OR
  title LIKE 'Marceli Santos Marins x Alimen%'
  OR
  title LIKE 'Bruno - Réplica%'
  OR
  title LIKE 'JCLF - Plano de Partilha%'
  OR
  title LIKE 'Dulce - manifestação%'
  OR
  title LIKE 'Clayton Ribeiro da Silva - Con%'
  OR
  title LIKE 'Inicial Divórcio - Carlos Augu%'
  OR
  title LIKE 'Verginia - Acordo processo n.%'
  OR
  title LIKE 'Eduarda  Honorato - Regulament%'
  OR
  title LIKE 'LILIANE CRISTINA DA SILVA (ali%'
  OR
  title LIKE 'ISABELLE FIGUEIRA DA SILVA (al%'
  OR
  title LIKE 'Allan - Regulamentação de Conv%'
  OR
  title LIKE 'Natalia Celano x C6 - Consumid%'
  OR
  title LIKE 'Edilaine Ferreira Alves - Alim%'
  OR
  title LIKE 'Kelly Bento - Peticionar para%'
  OR
  title LIKE 'Luana Tavares Soares - Aliment%'
  OR
  title LIKE 'Gilmar Silva Calixto - Investi%'
  OR
  title LIKE 'Lana - dar um retorno%'
  OR
  title LIKE 'Sara Maria Moreira da Silva Ma%'
  OR
  title LIKE 'Evellyn Xavier Manso - Aliment%'
  OR
  title LIKE 'Andreza Faria Leite - Alimento%'
  OR
  title LIKE 'Suelen de Paula da Silva - Ali%'
  OR
  title LIKE 'Amanda Castro - Inv. de Pat. 0%'
  OR
  title LIKE 'RI - Nathan%'
  OR
  title LIKE 'Helber - Sócio Rodrigo%'
  OR
  title LIKE 'Raiane - Investigação de Pater%'
  OR
  title LIKE 'Alexandro (juntar documentos c%'
  OR
  title LIKE 'Ágatha da Silva Queiroz%'
  OR
  title LIKE 'KAYLA KEROLAYNE BORGES DA SILV%'
  OR
  title LIKE 'Guilherme da Silva Benício - A%'
  OR
  title LIKE 'CONTESTAÇÃO - Isabella%'
  OR
  title LIKE 'JAQUELINE - PENSÃO%'
  OR
  title LIKE 'Peticionei no processo do Marc%'
  OR
  title LIKE 'Processo da Camila%'
  OR
  title LIKE 'Processo Adily%'
  OR
  title LIKE 'Processo Hayama - Procedimento%'
  OR
  title LIKE 'Inicial Leonardo - GPX (caso m%'
  OR
  title LIKE 'Inial Ilan%'
  OR
  title LIKE 'Inicial Revisional - Fabrício%'
  OR
  title LIKE 'Processo Berenice - Gpx%'
  OR
  title LIKE 'Inicial Danielli - Adoção%'
  OR
  title LIKE 'Emmanuelly - Neg. Acordo - 001%'
  OR
  title LIKE 'Eliana x Enel (2° processo)%'
  OR
  title LIKE 'Contestação Edilson - Alimento%'
  OR
  title LIKE 'Fábio Luiz - Enoxeração já em%'
  OR
  title LIKE 'Michele (Arthur) - Alimentos%'
  OR
  title LIKE 'Tatyane - JG%'
  OR
  title LIKE 'Fabrício - Contrarrazões Agrav%'
  OR
  title LIKE 'Thiago Silva (habilitação - ex%'
  OR
  title LIKE 'LIDIANE MARIA DE SOUZA (habili%'
  OR
  title LIKE 'MAIARA PEREIRA CARDOZO (alimen%'
  OR
  title LIKE 'CINEONE DA CONCEIÇÃO LIMA (ali%'
  OR
  title LIKE 'Contrarrazões - Letícia%'
  OR
  title LIKE 'Natan (Embargos de Declaração)%'
  OR
  title LIKE 'RENATA ALINE MONTEIRO PAULO -%'
  OR
  title LIKE 'RI DANUBIA%'
  OR
  title LIKE 'Fabrício - Contestação - Regul%'
  OR
  title LIKE 'Manifestação Dulce - Inventári%'
  OR
  title LIKE 'Inicial Bruno x Light%'
  OR
  title LIKE 'Inicial Rafaela GPX%'
  OR
  title LIKE 'Agravo John%'
  OR
  title LIKE 'Divórcio Francimeire Aparecida%'
  OR
  title LIKE 'Elisabeth - Contrarrazões RI%'
  OR
  title LIKE 'Eni Sacramento - Juntar Quesit%'
  OR
  title LIKE 'Inicial Danielli - Alvará Viag%'
  OR
  title LIKE 'Réplica Luana x Gol - 0815604-%'
  OR
  title LIKE 'Inicial Bruno Vinicius x PicPa%'
  OR
  title LIKE 'Fabrício - Divórcio%'
  OR
  title LIKE 'Alexandro - Revisional (Resend%'
  OR
  title LIKE 'Bruno Vinicius - consumidor x%'
  OR
  title LIKE 'Recurso - Odete (Melissa)%'
  OR
  title LIKE 'Cristiano (Enel)%'
  OR
  title LIKE 'Diego x Decolar%'
  OR
  title LIKE 'Felipe x Merisa%'
  OR
  title LIKE 'Luiz (emendar para indicar as%'
  OR
  title LIKE 'Allan - Pedido de homologação%'
  OR
  title LIKE 'Kaio (Luciana) - Reiterar pedi%'
  OR
  title LIKE 'Thiago%'
  OR
  title LIKE 'RESPONDER HILDA%'
  OR
  title LIKE 'ENVIAR PROCURAÇÃO VITOR HUGO%'
  OR
  title LIKE 'Réplica Diego - 5002526-58.202%'
  OR
  title LIKE 'Danielli - envio de termo de a%'
  OR
  title LIKE 'Naiara - Pedir renovação guard%'
  OR
  title LIKE 'Gabriella - entrar em contato%'
  OR
  title LIKE 'Contestação 0079205-70.2023.8.%'
  OR
  title LIKE 'Danubia - GPX - ED%'
  OR
  title LIKE 'Silvana - rito expropriação%'
  OR
  title LIKE 'Rafaela - manifestação sobre p%'
  OR
  title LIKE 'Jussara - rito prisão - pedido%'
  OR
  title LIKE 'Everton - pedido - revelia%'
  OR
  title LIKE 'Harleson Gomes - manifestação%'
  OR
  title LIKE 'Everton - embargos de declaraç%'
  OR
  title LIKE 'Tatyane - guarda%'
  OR
  title LIKE 'Jenifer - Pensão%'
  OR
  title LIKE 'Micaela - Embargos de Declaraç%'
  OR
  title LIKE 'Rachel - ED%'
  OR
  title LIKE 'Mayara - Juntada%'
  OR
  title LIKE 'Diego - Embargos de Declaração%'
  OR
  title LIKE 'Diego - Contrarrazões Embargos%'
  OR
  title LIKE 'Dulce - Inventário - Juntada d%'
  OR
  title LIKE 'Daiana - Sophia (ver whatsapp%'
  OR
  title LIKE 'Willian - Embargos de Declaraç%'
  OR
  title LIKE 'Everton (Lara) - Enviar ofício%'
  OR
  title LIKE 'Comprovantes de pagamento IPTU%'
  OR
  title LIKE 'Manifestação no processo da Lu%'
  OR
  title LIKE 'Inicial Sérgio Teixeira - Revi%'
  OR
  title LIKE 'Inicial Sérgio Teixeira - Regu%'
  OR
  title LIKE 'Inicial Rose Kely GPX%'
  OR
  title LIKE 'Revisional Everton - Processo%'
  OR
  title LIKE 'Oferecimento de Alimentos Alex%'
  OR
  title LIKE 'Inicial Janaína - GPX%'
  OR
  title LIKE 'Inicial Jean Kleber - GPX - te%'
  OR
  title LIKE 'Inicial Graziele - GPX%'
  OR
  title LIKE 'Inicial Cristiano%'
  OR
  title LIKE 'Inicial Alexsandro GPX%'
  OR
  title LIKE 'Inicial Tiago - GPX%'
  OR
  title LIKE 'Réplica Danúbia - GPX%'
  OR
  title LIKE 'Réplica Matheus%'
  OR
  title LIKE 'Enviar para Lidiane Meu INSS m%'
  OR
  title LIKE 'Réplica Adily%'
  OR
  title LIKE 'Minuta Gabriella Bairos%'
  OR
  title LIKE 'Recurso - Lucimar%'
  OR
  title LIKE 'Joana - Embargos de Declaração%'
  OR
  title LIKE 'Marcar reunião com Antoninha -%'
  OR
  title LIKE 'Harleson x Anna - Pedir divórc%'
  OR
  title LIKE 'Priscila - ver documentos%'
  OR
  title LIKE 'Madelaine%'
  OR
  title LIKE 'Rayane - CARRO - enviar procur%'
  OR
  title LIKE 'Agravo de Instrumento - Alexan%'
  OR
  title LIKE 'Fabrício - manifestar processo%'
  OR
  title LIKE 'Vitor Hugo - divórcio%'
  OR
  title LIKE 'Andreina%'
  OR
  title LIKE 'Diego x Decolar - Réplica%'
  OR
  title LIKE 'Aldo - Contrarrazões ED%'
)
ORDER BY title;

-- ================================================================
-- PASSO 2: EXECUTAR — So apos confirmar o SELECT acima
-- Descomente removendo /* e */ para executar
-- ================================================================
/*

UPDATE cases
SET
  status = 'processo_distribuido',
  updated_at = NOW()
WHERE status = 'pasta_apta'
  AND (
  title LIKE 'Giseli Rodrigues Correa x Divó%'
  OR
  title LIKE 'Cinthia Mara S. P. Gama x Cons%'
  OR
  title LIKE 'Anderson Souza Candido x Consu%'
  OR
  title LIKE 'Gildson Silva de Faria x Convi%'
  OR
  title LIKE 'Misã Guimarães Lopes x Ofereci%'
  OR
  title LIKE 'Thaissa Rocha Limas x Alimento%'
  OR
  title LIKE 'Gilmar Ferreira Gomes x Negató%'
  OR
  title LIKE 'Queila Conceição dos Santos x%'
  OR
  title LIKE 'Kamilly Victoria Franco x Alim%'
  OR
  title LIKE 'Natasha Carolina Pereira da Co%'
  OR
  title LIKE 'Julio Cesar Correa e Castro -%'
  OR
  title LIKE 'Josiana da Costa da Silva Corr%'
  OR
  title LIKE 'Cleitom Avelino Eduardo x Inve%'
  OR
  title LIKE 'Eliene Lima dos Santos Almeida%'
  OR
  title LIKE 'Alícia de Carvalho Wogel x Con%'
  OR
  title LIKE 'JENIFER DA SILVA BARBOSA x Des%'
  OR
  title LIKE 'Maria Eduarda da Silva Sousa x%'
  OR
  title LIKE 'CONDOMÍNIO AURA TIJUCA - ASSES%'
  OR
  title LIKE 'Aghata Stefany Nascimento da S%'
  OR
  title LIKE 'AuraTijuca x MKB%'
  OR
  title LIKE 'ESTER DOS SANTOS PATRICIO x Gu%'
  OR
  title LIKE 'ESTER DOS SANTOS PATRICIO x Co%'
  OR
  title LIKE 'Estoel Nathan Costa Silva x Al%'
  OR
  title LIKE 'DÉBORA LOPES GONÇALVES x Plano%'
  OR
  title LIKE 'MARIA CLARA MOREIRA DE LIMA x%'
  OR
  title LIKE 'Fernanda Silva Rabetine Junque%'
  OR
  title LIKE 'Marcus Vinicius Gomes Boechat%'
  OR
  title LIKE 'ADRIANO MATHEUS LEONCIO DE JES%'
  OR
  title LIKE 'Maria Aparecida de Lima Cassim%'
  OR
  title LIKE 'Raphael de Souza Silva x Inden%'
  OR
  title LIKE 'Adriano Luis de Oliveira x Ind%'
  OR
  title LIKE 'Jorge Ferreira Gomes x Bancári%'
  OR
  title LIKE 'Irlei Ribeiro Souza x Consumid%'
  OR
  title LIKE 'Maria Isabela Elioterio Lima x%'
  OR
  title LIKE 'Mélodi Batista Cruz x Desconto%'
  OR
  title LIKE 'Vanderleia Américo x Alimentos%'
  OR
  title LIKE 'Peterson Pereira Mayworm x Con%'
  OR
  title LIKE 'Aline Aparecida da Silva x Exe%'
  OR
  title LIKE 'Juliana Alves Santana x Invest%'
  OR
  title LIKE 'Leonardo da Silva Felipe x Def%'
  OR
  title LIKE 'Rayane Aparecida Pereira da Si%'
  OR
  title LIKE 'Yasmim Cruz da Silva x Execuçã%'
  OR
  title LIKE 'Cleitom Avelino Eduardo x Noti%'
  OR
  title LIKE 'Rodrigo França Penha x Resiona%'
  OR
  title LIKE 'Gabriela Cardoso Lobato x Alim%'
  OR
  title LIKE 'ALINE APARECIDA DE SOUZA VIEIR%'
  OR
  title LIKE 'Nataly Cerqueira Mattos Silva%'
  OR
  title LIKE 'Adriano da Silva Gomes x Defes%'
  OR
  title LIKE 'Rayane Rodrigues da Silva x Al%'
  OR
  title LIKE 'WILSON HELIO SILVA x Meta (Ins%'
  OR
  title LIKE 'GABRIELE RIBEIRO GOMES x Alime%'
  OR
  title LIKE 'Nilton dos Santos Silva x Cons%'
  OR
  title LIKE 'ALANE SANTOS OLIVEIRA x Alimen%'
  OR
  title LIKE 'Rachel Nardelli Rosa x Defesa%'
  OR
  title LIKE 'Maria Aparecida Corêa e Castro%'
  OR
  title LIKE 'Tamires da Silva x Execução%'
  OR
  title LIKE 'Guilherme da Silva Benício x G%'
  OR
  title LIKE 'Deise Ferreira do Nascimento x%'
  OR
  title LIKE 'Tamyris Carvalho e Carvalho x%'
  OR
  title LIKE 'Vanessa de Souza x Alimentos%'
  OR
  title LIKE 'Célia Ferreira da Silva x Divó%'
  OR
  title LIKE 'Sebastião de Oliveira x Alvará%'
  OR
  title LIKE 'Rayane Joyce da Silva Machado%'
  OR
  title LIKE 'Gilmara Gonçalves x Alimentos%'
  OR
  title LIKE 'Diogo Nascimento de Oliveira x%'
  OR
  title LIKE 'Cristiane Aniceto x Execução%'
  OR
  title LIKE 'Amanda Gomes de Oliveira x Ali%'
  OR
  title LIKE 'YOHANNA MARTINS ALEXANDRE DA S%'
  OR
  title LIKE 'Thais Rodrigues da Silva x Ali%'
  OR
  title LIKE 'Emerson de Alvarenga de Macedo%'
  OR
  title LIKE 'Vagner de Oliveira Gama x Exon%'
  OR
  title LIKE 'Jaqueline Oliveira Silva Santo%'
  OR
  title LIKE 'Gildson Silva de Faria x Alime%'
  OR
  title LIKE 'Michele Ferreira da Conceição%'
  OR
  title LIKE 'Rodrigo José do Nascimento Alv%'
  OR
  title LIKE 'Gabriela Cardoso Lobato x Alva%'
  OR
  title LIKE 'Leonardo (DIVÓRCIO) - Notifica%'
  OR
  title LIKE 'Joziane Lino x Inventário (ex%'
  OR
  title LIKE 'Sara Ramão Silva x Guarda%'
  OR
  title LIKE 'Aldo de Souza Louenço x Consum%'
  OR
  title LIKE 'INDIANE CHRISTINE MORAIS DE SO%'
  OR
  title LIKE 'Vagner de Oliveira Gama x Defe%'
  OR
  title LIKE 'Gilmar Ferreira Gomes x Defesa%'
  OR
  title LIKE 'Angela Rodrigues de Oliveira x%'
  OR
  title LIKE 'Mariana Joana Pereira x Invest%'
  OR
  title LIKE 'Juliana Matias Euclides x Exec%'
  OR
  title LIKE 'Filipe Cristiano x Trabalhista%'
  OR
  title LIKE 'Amanda Ferreira de Limax Execu%'
  OR
  title LIKE 'Cineone x Execução de Alimento%'
  OR
  title LIKE 'Ana Caroline Carvalho Neris Lo%'
  OR
  title LIKE 'Iara dos Santos Gonçalves x Al%'
  OR
  title LIKE 'Ester Gonsalves Ramão x Consum%'
  OR
  title LIKE 'Tamara Vitória x Desc em folha%'
  OR
  title LIKE 'Bianca Nascimento Conceição x%'
  OR
  title LIKE 'Rita Pires x Inventário Tupery%'
  OR
  title LIKE 'Diana Alves Segales Ganduxe%'
  OR
  title LIKE 'Ivan de Souza Moraes x Exonera%'
  OR
  title LIKE 'Rosangela dos Santos x Trabalh%'
  OR
  title LIKE 'Joseleia Moreira de Oliveira x%'
  OR
  title LIKE 'Thaissa Rocha Lima de Oliveira%'
  OR
  title LIKE 'Gisele Silva Machado Amaro Car%'
  OR
  title LIKE 'Dayana Cabral Fernandes X Divó%'
  OR
  title LIKE 'Dayana Cabral Fernandes x Exec%'
  OR
  title LIKE 'Antoninha (em fase de acordo)%'
  OR
  title LIKE 'Caroline da Silva David x Exec%'
  OR
  title LIKE 'Maria Marinete Lins Silva x Es%'
  OR
  title LIKE 'Eli Manoel x Dissolução de Uni%'
  OR
  title LIKE 'Rafaella de Lima Benedito x Au%'
  OR
  title LIKE 'Luana Frias x Parceria - Cidad%'
  OR
  title LIKE 'Jessica Maiara Rocha da Costa%'
  OR
  title LIKE 'Carina Marcelino x Divórcio%'
  OR
  title LIKE 'Carina Marcelino Correa x Alim%'
  OR
  title LIKE 'Vitoria Aparecida Silva x Alim%'
  OR
  title LIKE 'Sara Cristina de Souza Ribeiro%'
  OR
  title LIKE 'Geyza x Trabalhista (Bianca)%'
  OR
  title LIKE 'Suelen da Costa Silva x Defesa%'
  OR
  title LIKE 'Ivan de Souza Moraes x Defesa%'
  OR
  title LIKE 'Leonardo Ribeiro Braga x Depoi%'
  OR
  title LIKE 'Leonardo Ribeiro Braga x Defes%'
  OR
  title LIKE 'Francisco Tomazi Manoel x Defe%'
  OR
  title LIKE 'Robson Machado Soares x Defesa%'
  OR
  title LIKE 'Weverton Jairo x Defesa Execuç%'
  OR
  title LIKE 'Thiago Melo x Audiência Cejusc%'
  OR
  title LIKE 'Gabriela Cristina de Oliveira%'
  OR
  title LIKE 'Natan e Neusa x Usucapião%'
  OR
  title LIKE 'Mariella dos Santos Silva x Re%'
  OR
  title LIKE 'JEOVANA MARCOLINO SOUZA DO CAR%'
  OR
  title LIKE 'Kaylane Rangel da Silva x Inve%'
  OR
  title LIKE 'Caroline de Souza Barros x Ali%'
  OR
  title LIKE 'Sara Ramão Silva x Alimentos%'
  OR
  title LIKE 'Paula Roberta Sodré x Rec. Mat%'
  OR
  title LIKE 'José Rafael da Silva (inventár%'
  OR
  title LIKE 'Diego Souza Villarinho x Benef%'
  OR
  title LIKE 'Carolaine de Souza Barros x In%'
  OR
  title LIKE 'Lohana da Silva Teixeira de Pa%'
  OR
  title LIKE 'Ana Paula Almeida Diniz x Cons%'
  OR
  title LIKE 'Guilherme da Silva Benício x E%'
  OR
  title LIKE 'Bebiana de Oliveira Reis x Con%'
  OR
  title LIKE 'Luiz Fernando de Souza Severin%'
  OR
  title LIKE 'Lorena x Execução - Já tem pas%'
  OR
  title LIKE 'Eduarda Honorato Toledo x Guar%'
  OR
  title LIKE 'Beatriz dos Santos Oliveira x%'
  OR
  title LIKE 'Marcia Aparecida Costa da Silv%'
  OR
  title LIKE 'Claudinei x Execução Penhora%'
  OR
  title LIKE 'Mariana Joana Pereira x Alimen%'
  OR
  title LIKE 'Jessica da Silva x Execução%'
  OR
  title LIKE 'Jéssica Gregório da Silva Abre%'
  OR
  title LIKE 'Rodrigo da Conceição Lopes x E%'
  OR
  title LIKE 'Jessica da Silva x Alimentos%'
  OR
  title LIKE 'Felipe (Norma) - Consumidor (C%'
  OR
  title LIKE 'Joice Correa Cezario x Indeniz%'
  OR
  title LIKE 'Juliana de Souza Barbosa x Ali%'
  OR
  title LIKE 'Viviane Lyrio Barboza x Divórc%'
  OR
  title LIKE 'Espólio Waldir x Inventário (p%'
  OR
  title LIKE 'Jéssica Ribeiro Honório x Inve%'
  OR
  title LIKE 'Arnaldo x Pensão (réu)%'
  OR
  title LIKE 'Myllena x Leilão%'
  OR
  title LIKE 'Maria Girlandia Barbosa Gomes%'
  OR
  title LIKE 'MAISA SILVA SANTOS x Alimentos%'
  OR
  title LIKE 'Maria Clara Moreira x Regulame%'
  OR
  title LIKE 'JENNIFER DE PAULA x Desconto e%'
  OR
  title LIKE 'JENNIFER DE PAULA x Execução d%'
  OR
  title LIKE 'Angela Louzada x Reg. de Convi%'
  OR
  title LIKE 'Luiz Eduardo de Sá x Ifood%'
  OR
  title LIKE 'Allan Louzada x Execução (Juli%'
  OR
  title LIKE 'Fernanda Ribeiro de Oliveira x%'
  OR
  title LIKE 'Mariella dos Santos Silva x Di%'
  OR
  title LIKE 'Taina Cristina de Lima dos San%'
  OR
  title LIKE 'Wallace Gabriel Pereira Tamioz%'
  OR
  title LIKE 'AILANDA ALINE LESSA FARIA%'
  OR
  title LIKE 'Leonardo Tavares Ferreira x De%'
  OR
  title LIKE 'Mariele de Souza Barbosa x Ali%'
  OR
  title LIKE 'Mariucha dos Santos Silva x De%'
  OR
  title LIKE 'Carlos Agusto Ferreira Monteir%'
  OR
  title LIKE 'Maiara Pereira Cardozo x Execu%'
  OR
  title LIKE 'Felipe Guimarães - Of. Aliment%'
  OR
  title LIKE 'Enayle Garcia Fontes x Execuçã%'
  OR
  title LIKE 'Dayane de Araújo Amancio  x Ex%'
  OR
  title LIKE 'KAUAN RODRIGUES DE SOUZA x GPX%'
  OR
  title LIKE 'Maurício Maia Ferreira x GPX%'
  OR
  title LIKE 'Mariele de Souza Barbosa x Inv%'
  OR
  title LIKE 'Mariella dos Santos Silva x Al%'
  OR
  title LIKE 'Patrick Gomes das Graças Passi%'
  OR
  title LIKE 'Giseli Rodrigues Correa x Alim%'
  OR
  title LIKE 'Raiane Martins Damasceno x Alm%'
  OR
  title LIKE 'Márcia Honorato Nunes Medeiros%'
  OR
  title LIKE 'Andriw Peixoto Pereira x Disso%'
  OR
  title LIKE 'Monique Alves Carvalho x Guard%'
  OR
  title LIKE 'Carlos Augusto Ferreira Montei%'
  OR
  title LIKE 'Ana Claudia Guimarães da Silva%'
  OR
  title LIKE 'Anesio Francisco da Silva x Co%'
  OR
  title LIKE 'STEPHANIA DOS SANTOS ROCHA x A%'
  OR
  title LIKE 'Thaís Beringhy dos Santos Barr%'
  OR
  title LIKE 'Valquíria da Silva Vieira x De%'
  OR
  title LIKE 'Geyza - Combo Família%'
  OR
  title LIKE 'Thaise (0006243-53.2019.8.19.0%'
  OR
  title LIKE 'Igor Yahnn Neves de Carvalho x%'
  OR
  title LIKE 'Renan Nascimento dos Santos x%'
  OR
  title LIKE 'Fernanda x Divórcio%'
  OR
  title LIKE 'Fernanda x Guarda%'
  OR
  title LIKE 'Jessica Regina dos Santos Mach%'
  OR
  title LIKE 'Aline Fernandes Carvalho x Ind%'
  OR
  title LIKE 'Thainá de Castro x Investigaçã%'
  OR
  title LIKE 'Raul x Dissolução UE%'
  OR
  title LIKE 'Thaynara x Responsabilidade Ci%'
  OR
  title LIKE 'Dayane de Araújo Amancio  x Re%'
  OR
  title LIKE 'Paulo Sérgio x Investigação%'
  OR
  title LIKE 'Denilson x GPX%'
  OR
  title LIKE 'Cassiane Faria Conceição x Ali%'
  OR
  title LIKE 'Thaise Marques (Academia VR)%'
  OR
  title LIKE 'Suelen de Paula da Silva x Exe%'
  OR
  title LIKE 'Aila dos Santos Gaia x Execuçã%'
  OR
  title LIKE 'Luciana Diniz Barbosa x LOAS%'
  OR
  title LIKE 'Lívia Silva Nunes Mendonça x D%'
  OR
  title LIKE 'Claudinei Marcolino x Alimento%'
  OR
  title LIKE 'Claudinei Marcolino x Regulame%'
  OR
  title LIKE 'Thiago Melo x Convivência%'
  OR
  title LIKE 'Deise Cristina Ramos de Olivei%'
  OR
  title LIKE 'Kênia Domingos do Nascimento x%'
  OR
  title LIKE 'Cezar Augusto Pereira de Carva%'
  OR
  title LIKE 'Inicial Elaine - Alimentos%'
  OR
  title LIKE 'Inicial Elaine - Tentativa de%'
  OR
  title LIKE 'Priscila (imobiliária) - Entra%'
  OR
  title LIKE 'Ângela - Notificação Extrajudi%'
  OR
  title LIKE 'Letícia Vitória dos Santos Ave%'
  OR
  title LIKE 'Francimeire (cobrança - execuç%'
  OR
  title LIKE 'Ana Paula Almeida Diniz x Dano%'
  OR
  title LIKE 'Ana Carolina Bragança Pinto x%'
  OR
  title LIKE 'Lorena Quintanilha Soares x In%'
  OR
  title LIKE 'Tiago x Execução (defesa)%'
  OR
  title LIKE 'Silvia Neurauter x GPX%'
  OR
  title LIKE 'Fernanda x Pensão%'
  OR
  title LIKE 'Priscila Almeida Silva Anastác%'
  OR
  title LIKE 'Ruana x Desconto em Folha%'
  OR
  title LIKE 'Ruana x Execução%'
  OR
  title LIKE 'Silzy (Ré) x GPX (FIZEMOS ACOR%'
  OR
  title LIKE 'Douglas Silva de Souza x Alime%'
  OR
  title LIKE 'Cristina Mara Oliveira x Alime%'
  OR
  title LIKE 'Karine Souza x GPX%'
  OR
  title LIKE 'Alex Sander x Exoneração%'
  OR
  title LIKE 'Vanessa Moraes x GPX%'
  OR
  title LIKE 'Maiza x Revisional%'
  OR
  title LIKE 'Renata Silva x Alimentos%'
  OR
  title LIKE 'Jéssica Cristina%'
  OR
  title LIKE 'Eduarda Honorato - Execução%'
  OR
  title LIKE 'Rafaelly Sousa da Silva x Desc%'
  OR
  title LIKE 'Lidiane Maria - EXECUÇÃO%'
  OR
  title LIKE 'AURATIJUCA - EXECUÇÃO%'
  OR
  title LIKE 'Joyce%'
  OR
  title LIKE 'Karen Lopes dos Santos x Inves%'
  OR
  title LIKE 'Kezia de Souza Costa x Aliment%'
  OR
  title LIKE 'Kezia de Souza Costa x Execuçã%'
  OR
  title LIKE 'Lucca Celano Pereira Ribeiro x%'
  OR
  title LIKE 'Solange Sacramento Guedes Ferr%'
  OR
  title LIKE 'Ruana Constantino Torres x Exe%'
  OR
  title LIKE 'Valquiria da Silva x Vieira%'
  OR
  title LIKE 'Alexandro da Silva Martins x D%'
  OR
  title LIKE 'Alvará FGTS Welsey (pai da And%'
  OR
  title LIKE 'Marli - Consumidor Enel%'
  OR
  title LIKE 'Processo Rayane (carro)%'
  OR
  title LIKE 'JOYCE CANDIDO TEIXEIRA - Revis%'
  OR
  title LIKE 'Eli x IPVA%'
  OR
  title LIKE 'KAYLA KEROLAYNE x Alimentos%'
  OR
  title LIKE 'ENI SACRAMENTO - APELAÇÃO%'
  OR
  title LIKE 'Kayla Kerolayne x Pensão%'
  OR
  title LIKE 'Guilherme Benício x Pensão%'
  OR
  title LIKE 'Sebastião (Débora) x Consumido%'
  OR
  title LIKE 'Nativânia x GPX%'
  OR
  title LIKE 'Wallace dos Santos x Trabalhis%'
  OR
  title LIKE 'Angela Rodrigues x Alimentos%'
  OR
  title LIKE 'PAOLA FERNANDA SILVA DE ASSIS%'
  OR
  title LIKE 'KAMILA DA SILVA ELEOTÉRIO X Re%'
  OR
  title LIKE 'EDUARDA HONORATO - Habilitação%'
  OR
  title LIKE 'LUANA - pensão%'
  OR
  title LIKE 'HARLESON - provas + reiterar d%'
  OR
  title LIKE 'Thamires Calabar x Alimentos%'
  OR
  title LIKE 'Ana Claudia Guimarães x Alimen%'
  OR
  title LIKE 'Aline Fernandes Carvalho x Exe%'
  OR
  title LIKE 'GEYZA - ATULIZAR L1 - PEDI HAB%'
  OR
  title LIKE 'Carlos - Inversão da guarda -%'
  OR
  title LIKE 'Erika de Sena Antunes x Alimen%'
  OR
  title LIKE 'CLEIDE RÉPLICA - ALIMENTOS%'
  OR
  title LIKE 'Marceli Santos Marins x Alimen%'
  OR
  title LIKE 'Bruno - Réplica%'
  OR
  title LIKE 'JCLF - Plano de Partilha%'
  OR
  title LIKE 'Dulce - manifestação%'
  OR
  title LIKE 'Clayton Ribeiro da Silva - Con%'
  OR
  title LIKE 'Inicial Divórcio - Carlos Augu%'
  OR
  title LIKE 'Verginia - Acordo processo n.%'
  OR
  title LIKE 'Eduarda  Honorato - Regulament%'
  OR
  title LIKE 'LILIANE CRISTINA DA SILVA (ali%'
  OR
  title LIKE 'ISABELLE FIGUEIRA DA SILVA (al%'
  OR
  title LIKE 'Allan - Regulamentação de Conv%'
  OR
  title LIKE 'Natalia Celano x C6 - Consumid%'
  OR
  title LIKE 'Edilaine Ferreira Alves - Alim%'
  OR
  title LIKE 'Kelly Bento - Peticionar para%'
  OR
  title LIKE 'Luana Tavares Soares - Aliment%'
  OR
  title LIKE 'Gilmar Silva Calixto - Investi%'
  OR
  title LIKE 'Lana - dar um retorno%'
  OR
  title LIKE 'Sara Maria Moreira da Silva Ma%'
  OR
  title LIKE 'Evellyn Xavier Manso - Aliment%'
  OR
  title LIKE 'Andreza Faria Leite - Alimento%'
  OR
  title LIKE 'Suelen de Paula da Silva - Ali%'
  OR
  title LIKE 'Amanda Castro - Inv. de Pat. 0%'
  OR
  title LIKE 'RI - Nathan%'
  OR
  title LIKE 'Helber - Sócio Rodrigo%'
  OR
  title LIKE 'Raiane - Investigação de Pater%'
  OR
  title LIKE 'Alexandro (juntar documentos c%'
  OR
  title LIKE 'Ágatha da Silva Queiroz%'
  OR
  title LIKE 'KAYLA KEROLAYNE BORGES DA SILV%'
  OR
  title LIKE 'Guilherme da Silva Benício - A%'
  OR
  title LIKE 'CONTESTAÇÃO - Isabella%'
  OR
  title LIKE 'JAQUELINE - PENSÃO%'
  OR
  title LIKE 'Peticionei no processo do Marc%'
  OR
  title LIKE 'Processo da Camila%'
  OR
  title LIKE 'Processo Adily%'
  OR
  title LIKE 'Processo Hayama - Procedimento%'
  OR
  title LIKE 'Inicial Leonardo - GPX (caso m%'
  OR
  title LIKE 'Inial Ilan%'
  OR
  title LIKE 'Inicial Revisional - Fabrício%'
  OR
  title LIKE 'Processo Berenice - Gpx%'
  OR
  title LIKE 'Inicial Danielli - Adoção%'
  OR
  title LIKE 'Emmanuelly - Neg. Acordo - 001%'
  OR
  title LIKE 'Eliana x Enel (2° processo)%'
  OR
  title LIKE 'Contestação Edilson - Alimento%'
  OR
  title LIKE 'Fábio Luiz - Enoxeração já em%'
  OR
  title LIKE 'Michele (Arthur) - Alimentos%'
  OR
  title LIKE 'Tatyane - JG%'
  OR
  title LIKE 'Fabrício - Contrarrazões Agrav%'
  OR
  title LIKE 'Thiago Silva (habilitação - ex%'
  OR
  title LIKE 'LIDIANE MARIA DE SOUZA (habili%'
  OR
  title LIKE 'MAIARA PEREIRA CARDOZO (alimen%'
  OR
  title LIKE 'CINEONE DA CONCEIÇÃO LIMA (ali%'
  OR
  title LIKE 'Contrarrazões - Letícia%'
  OR
  title LIKE 'Natan (Embargos de Declaração)%'
  OR
  title LIKE 'RENATA ALINE MONTEIRO PAULO -%'
  OR
  title LIKE 'RI DANUBIA%'
  OR
  title LIKE 'Fabrício - Contestação - Regul%'
  OR
  title LIKE 'Manifestação Dulce - Inventári%'
  OR
  title LIKE 'Inicial Bruno x Light%'
  OR
  title LIKE 'Inicial Rafaela GPX%'
  OR
  title LIKE 'Agravo John%'
  OR
  title LIKE 'Divórcio Francimeire Aparecida%'
  OR
  title LIKE 'Elisabeth - Contrarrazões RI%'
  OR
  title LIKE 'Eni Sacramento - Juntar Quesit%'
  OR
  title LIKE 'Inicial Danielli - Alvará Viag%'
  OR
  title LIKE 'Réplica Luana x Gol - 0815604-%'
  OR
  title LIKE 'Inicial Bruno Vinicius x PicPa%'
  OR
  title LIKE 'Fabrício - Divórcio%'
  OR
  title LIKE 'Alexandro - Revisional (Resend%'
  OR
  title LIKE 'Bruno Vinicius - consumidor x%'
  OR
  title LIKE 'Recurso - Odete (Melissa)%'
  OR
  title LIKE 'Cristiano (Enel)%'
  OR
  title LIKE 'Diego x Decolar%'
  OR
  title LIKE 'Felipe x Merisa%'
  OR
  title LIKE 'Luiz (emendar para indicar as%'
  OR
  title LIKE 'Allan - Pedido de homologação%'
  OR
  title LIKE 'Kaio (Luciana) - Reiterar pedi%'
  OR
  title LIKE 'Thiago%'
  OR
  title LIKE 'RESPONDER HILDA%'
  OR
  title LIKE 'ENVIAR PROCURAÇÃO VITOR HUGO%'
  OR
  title LIKE 'Réplica Diego - 5002526-58.202%'
  OR
  title LIKE 'Danielli - envio de termo de a%'
  OR
  title LIKE 'Naiara - Pedir renovação guard%'
  OR
  title LIKE 'Gabriella - entrar em contato%'
  OR
  title LIKE 'Contestação 0079205-70.2023.8.%'
  OR
  title LIKE 'Danubia - GPX - ED%'
  OR
  title LIKE 'Silvana - rito expropriação%'
  OR
  title LIKE 'Rafaela - manifestação sobre p%'
  OR
  title LIKE 'Jussara - rito prisão - pedido%'
  OR
  title LIKE 'Everton - pedido - revelia%'
  OR
  title LIKE 'Harleson Gomes - manifestação%'
  OR
  title LIKE 'Everton - embargos de declaraç%'
  OR
  title LIKE 'Tatyane - guarda%'
  OR
  title LIKE 'Jenifer - Pensão%'
  OR
  title LIKE 'Micaela - Embargos de Declaraç%'
  OR
  title LIKE 'Rachel - ED%'
  OR
  title LIKE 'Mayara - Juntada%'
  OR
  title LIKE 'Diego - Embargos de Declaração%'
  OR
  title LIKE 'Diego - Contrarrazões Embargos%'
  OR
  title LIKE 'Dulce - Inventário - Juntada d%'
  OR
  title LIKE 'Daiana - Sophia (ver whatsapp%'
  OR
  title LIKE 'Willian - Embargos de Declaraç%'
  OR
  title LIKE 'Everton (Lara) - Enviar ofício%'
  OR
  title LIKE 'Comprovantes de pagamento IPTU%'
  OR
  title LIKE 'Manifestação no processo da Lu%'
  OR
  title LIKE 'Inicial Sérgio Teixeira - Revi%'
  OR
  title LIKE 'Inicial Sérgio Teixeira - Regu%'
  OR
  title LIKE 'Inicial Rose Kely GPX%'
  OR
  title LIKE 'Revisional Everton - Processo%'
  OR
  title LIKE 'Oferecimento de Alimentos Alex%'
  OR
  title LIKE 'Inicial Janaína - GPX%'
  OR
  title LIKE 'Inicial Jean Kleber - GPX - te%'
  OR
  title LIKE 'Inicial Graziele - GPX%'
  OR
  title LIKE 'Inicial Cristiano%'
  OR
  title LIKE 'Inicial Alexsandro GPX%'
  OR
  title LIKE 'Inicial Tiago - GPX%'
  OR
  title LIKE 'Réplica Danúbia - GPX%'
  OR
  title LIKE 'Réplica Matheus%'
  OR
  title LIKE 'Enviar para Lidiane Meu INSS m%'
  OR
  title LIKE 'Réplica Adily%'
  OR
  title LIKE 'Minuta Gabriella Bairos%'
  OR
  title LIKE 'Recurso - Lucimar%'
  OR
  title LIKE 'Joana - Embargos de Declaração%'
  OR
  title LIKE 'Marcar reunião com Antoninha -%'
  OR
  title LIKE 'Harleson x Anna - Pedir divórc%'
  OR
  title LIKE 'Priscila - ver documentos%'
  OR
  title LIKE 'Madelaine%'
  OR
  title LIKE 'Rayane - CARRO - enviar procur%'
  OR
  title LIKE 'Agravo de Instrumento - Alexan%'
  OR
  title LIKE 'Fabrício - manifestar processo%'
  OR
  title LIKE 'Vitor Hugo - divórcio%'
  OR
  title LIKE 'Andreina%'
  OR
  title LIKE 'Diego x Decolar - Réplica%'
  OR
  title LIKE 'Aldo - Contrarrazões ED%'
);

-- Verificacao pos-update
SELECT status, COUNT(*) as qtd
FROM cases
GROUP BY status
ORDER BY qtd DESC;

*/

-- ================================================================
-- REFERENCIA: 439 nomes da planilha original (verde riscado)
-- Esperado apos correcao:
--   pasta_apta:          ~130 (casos realmente ativos)
--   processo_distribuido: ~300+ (historico)
-- ================================================================