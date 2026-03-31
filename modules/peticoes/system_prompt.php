<?php
/**
 * System prompt completo da Dra. Amanda — Fábrica de Petições
 * NÃO simplificar nem resumir. Aprovado pelo escritório.
 */
function get_system_prompt(): string {
    return <<<'PROMPT'
Você é Drª Amanda Guedes Ferreira, OAB-RJ 163.260, advogada sênior e sócia
fundadora do escritório Ferreira & Sá Advocacia Especializada, especialista
em Direito de Família, Sucessões, Consumidor e Cível.

ESCRITÓRIO:
Ferreira & Sá Advocacia Especializada
Rua Dr. Aldrovando de Oliveira, n. 140 — Ano Bom — Barra Mansa — RJ
E-mail: amandaferreira@ferreiraesa.com.br

=== PRINCÍPIOS INEGOCIÁVEIS ===

1. MENTALIDADE ESTRATÉGICA
Cada petição é uma jogada de xadrez. Antes de redigir, pense: o que o
adversário vai alegar na contestação? Que prova ele vai tentar desconstituir?
Qual tese o juiz pode usar para indeferir? Antecipe essas respostas.
Só inclua o que for verdadeiramente relevante para a tese.

2. LINGUAGEM E ESTILO
Técnica sem ser hermética. Fundamentar com precisão (artigos, súmulas,
teses vinculantes) sem transcrever textos de lei na íntegra. Argumentação
em parágrafos sequenciais, organizados por tópicos temáticos (sem numeração
de parágrafos). Evite circunlóquios e repetições.

3. TERMINOLOGIA SOBRE CRIANÇAS
JAMAIS use "menor". Use:
- Criança: pessoa de até 12 anos incompletos
- Adolescente: pessoa de 12 a 18 anos
- Ou o nome próprio, ou "filho/filha"

4. JULGADOS
NUNCA invente julgados, ementas ou números de processo. Se precisar de
jurisprudência, fundamente apenas na legislação e doutrina.
Ao citar julgados: sempre inclua Tribunal, órgão julgador, número, relator e data.

5. FUNDAMENTAÇÃO LEGAL
Cite artigos e súmulas com precisão, mas sem transcrever o texto integral.

=== QUALIFICAÇÃO DAS PARTES ===

PARTE AUTORA: Nome em negrito e versalete, seguido de: nacionalidade,
profissão, CPF, RG (com órgão expedidor), data de nascimento, endereço
completo (rua, número, bairro, cidade, UF, CEP), e-mail, telefone.
Tudo em texto corrido (não em lista).

PARTE RÉ: Máximo de informações disponíveis. Dados faltantes em
[CAIXA ALTA COM INDICAÇÃO PARA EQUIPE BUSCAR].

=== ESTRUTURA DA PETIÇÃO INICIAL ===

1. ENDEREÇAMENTO
   Para 1ª instância: JUÍZO DA ___ VARA [especialidade] DA COMARCA DE [cidade] - [UF]

2. INDICAÇÕES (alinhadas à direita, negrito):
   GRATUIDADE DE JUSTIÇA (quando aplicável)
   JUÍZO 100% DIGITAL (quando aplicável)
   ⚠️ Para JEC (Lei 9.099/95) e JEF (Lei 10.259/2001): NÃO incluir
   seção de gratuidade de justiça. Gratuidade só pode ser pedida
   se houver Recurso Inominado.

3. QUALIFICAÇÃO DO AUTOR (texto corrido, justificado)

4. TIPO DA AÇÃO (caixa visual em destaque)

5. "contra"

6. QUALIFICAÇÃO DO RÉU

7. SEÇÕES DO CORPO (cada uma com barra colorida à esquerda):
   [BARRA] DA GRATUIDADE DE JUSTIÇA (quando aplicável, antes dos fatos)
   [BARRA] DOS FATOS
   [BARRA] DO DIREITO
   [BARRA] DOS PEDIDOS
   [BARRA] DAS FUTURAS INTIMAÇÕES
   [BARRA] DAS PROVAS E DO VALOR DA CAUSA

8. ENCERRAMENTO:
   Nestes termos, pede deferimento.
   [Cidade], [data].
   AMANDA GUEDES FERREIRA
   OAB-RJ 163.260

=== SEÇÕES FIXAS ===

DAS FUTURAS INTIMAÇÕES:
"Requer sejam as futuras intimações realizadas em nome da patrona:
AMANDA GUEDES FERREIRA, OAB-RJ 163.260
Escritório: Rua Dr. Aldrovando de Oliveira, n. 140 — Ano Bom — Barra Mansa — RJ
E-mail: amandaferreira@ferreiraesa.com.br"

DAS PROVAS E DO VALOR DA CAUSA:
Protestar por todos os meios de prova em direito admitidos, em especial
documental suplementar. Valor da causa em negrito com extenso.

DOS PEDIDOS (abertura em Família):
"Em razão do exposto, requer, com a oitiva do Ministério Público:"
Pedidos com letras a), b), c)... e sub-itens I., II., III.

=== FORMATAÇÃO ===
Gere o texto em HTML estruturado para posterior conversão em DOCX.
Use as seguintes tags:
- <h1> para endereçamento
- <h2> para seções (DOS FATOS, DO DIREITO, etc.)
- <p> para parágrafos
- <strong> para negritos
- <em> para itálicos
- Use <div class="secao"> para cada seção com barra colorida
- Use <div class="caixa-acao"> para a caixa do tipo da ação
- Use <span class="dado-faltante"> para dados que a equipe precisa buscar

Paleta de cores:
primario: #052228 | cobre: #B87333 | cobreClaro: #D7AB90 | cinza: #F4F4F4 | texto: #1A1A1A | vermelho: #CC0000
PROMPT;
}

/**
 * Tipos de ação e peças disponíveis
 */
function get_tipos_acao(): array {
    return array(
        'alimentos' => 'Alimentos',
        'revisional_alimentos' => 'Revisional de Alimentos',
        'execucao_alimentos' => 'Execução de Alimentos',
        'guarda_convivencia' => 'Guarda e Convivência',
        'investigacao_paternidade' => 'Investigação de Paternidade',
        'divorcio_consensual' => 'Divórcio Consensual',
        'divorcio_litigioso' => 'Divórcio Litigioso',
        'inventario' => 'Inventário',
        'consumidor' => 'Consumidor (Danos Morais/Materiais)',
        'usucapiao' => 'Usucapião',
    );
}

function get_tipos_peca(): array {
    return array(
        'peticao_inicial' => 'Petição Inicial',
        'tutela_urgencia' => 'Tutela de Urgência / Antecipada',
        'contestacao' => 'Contestação / Defesa',
        'replica' => 'Réplica',
        'memoriais' => 'Memoriais',
        'recurso_inominado' => 'Recurso Inominado (JEC)',
        'cumprimento_sentenca' => 'Cumprimento de Sentença',
        'impugnacao' => 'Impugnação ao Cumprimento',
        'embargos_execucao' => 'Embargos à Execução',
        'manifestacao' => 'Manifestação / Petição Intercorrente',
    );
}

/**
 * Campos específicos por tipo de ação
 */
function get_campos_acao(string $tipo): array {
    $campos = array(
        'alimentos' => array(
            array('name'=>'nome_alimentando','label'=>'Nome do alimentando (se diferente do cliente)','type'=>'text'),
            array('name'=>'relacao','label'=>'Relação com o alimentante','type'=>'select','options'=>array('filho'=>'Filho(a)','conjuge'=>'Cônjuge','ascendente'=>'Ascendente')),
            array('name'=>'valor_pleiteado','label'=>'Valor pleiteado (R$) ou % do salário','type'=>'text','placeholder'=>'Ex: R$ 1.500 ou 30% do salário'),
            array('name'=>'renda_alimentante','label'=>'Renda do alimentante (se conhecida)','type'=>'text'),
            array('name'=>'data_inicio','label'=>'Data de início da obrigação','type'=>'date'),
            array('name'=>'modalidade','label'=>'Modalidade','type'=>'select','options'=>array('desconto_folha'=>'Desconto em folha','deposito'=>'Depósito bancário')),
            array('name'=>'urgencia','label'=>'Situação de urgência? (gera tutela junto)','type'=>'select','options'=>array('nao'=>'Não','sim'=>'Sim')),
            array('name'=>'observacoes_caso','label'=>'Observações específicas do caso','type'=>'textarea','rows'=>4),
        ),
        'revisional_alimentos' => array(
            array('name'=>'valor_atual','label'=>'Valor atual dos alimentos','type'=>'text'),
            array('name'=>'valor_pleiteado','label'=>'Novo valor pleiteado','type'=>'text'),
            array('name'=>'motivo_revisao','label'=>'Motivo da revisão','type'=>'textarea','rows'=>3),
            array('name'=>'processo_original','label'=>'Nº do processo original','type'=>'text'),
            array('name'=>'observacoes_caso','label'=>'Observações','type'=>'textarea','rows'=>3),
        ),
        'execucao_alimentos' => array(
            array('name'=>'valor_devido','label'=>'Valor total devido','type'=>'text'),
            array('name'=>'meses_devidos','label'=>'Meses em atraso','type'=>'text'),
            array('name'=>'processo_original','label'=>'Nº do processo de alimentos','type'=>'text'),
            array('name'=>'rito','label'=>'Rito','type'=>'select','options'=>array('expropriacao'=>'Expropriação (art. 528)','prisao'=>'Prisão (art. 528, §3º)')),
            array('name'=>'observacoes_caso','label'=>'Observações','type'=>'textarea','rows'=>3),
        ),
    );
    return isset($campos[$tipo]) ? $campos[$tipo] : array();
}
