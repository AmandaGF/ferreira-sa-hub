<?php
/**
 * Migração: 2 novos módulos de treinamento
 *  1. distribuir-peticao-inicial — movimentação de card no Kanban Operacional
 *     pra distribuir uma petição inicial
 *  2. cadastrar-processo-existente — cadastro de processo que já existe
 *     (migrou de outro escritório, recurso, etc.)
 *
 * Idempotente: ON DUPLICATE KEY UPDATE pros módulos; DELETE+INSERT pro quiz.
 *
 * Uso: ?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/database.php';

$key = $_GET['key'] ?? '';
if ($key !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }

$pdo = db();
header('Content-Type: text/plain; charset=utf-8');
echo "=== Treinamento: módulos Distribuir + Cadastrar Existente ===\n\n";

// ─── Módulos ───
$modulos = array(
    array(
        'distribuir-peticao-inicial',
        'Distribuir Petição Inicial',
        'Como movimentar o card do caso pra "Processo Distribuído" no Kanban Operacional — preenchimento do modal e gatilhos automáticos',
        '🏛️',
        '["todos"]',
        25,
        50,
    ),
    array(
        'cadastrar-processo-existente',
        'Cadastrar Processo já Existente',
        'Como cadastrar no Hub um processo que já foi protocolado (cliente que migrou de outro escritório, recurso de antigo, processo herdado)',
        '📂',
        '["todos"]',
        26,
        50,
    ),
    array(
        'vincular-incidentais-recursos',
        'Vincular Processos — Incidentais e Recursos',
        'Como marcar um processo como incidental ou recurso de outro principal — uso correto do "Outros" e catálogo de naturezas jurídicas',
        '📎',
        '["todos"]',
        27,
        50,
    ),
);

$stmtMod = $pdo->prepare(
    "INSERT INTO treinamento_modulos (slug, titulo, descricao, icone, perfis_alvo, ordem, pontos)
     VALUES (?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE
        titulo      = VALUES(titulo),
        descricao   = VALUES(descricao),
        icone       = VALUES(icone),
        perfis_alvo = VALUES(perfis_alvo),
        ordem       = VALUES(ordem),
        pontos      = VALUES(pontos)"
);
foreach ($modulos as $m) {
    $stmtMod->execute($m);
    echo "✓ módulo: {$m[0]}\n";
}

// ─── Quiz: limpa e repopula só pros slugs novos ───
$pdo->prepare("DELETE FROM treinamento_quiz WHERE modulo_slug IN ('distribuir-peticao-inicial','cadastrar-processo-existente','vincular-incidentais-recursos')")
    ->execute();

$quizzes = array(
    // distribuir-peticao-inicial ─────────────────────────────
    array(
        'distribuir-peticao-inicial',
        'Onde abre o modal "Dados do Processo Distribuído"?',
        'Quando clica em + Novo Processo no Operacional',
        'Quando arrasta o card pra coluna "🏛️ Processo Distribuído" no Kanban Operacional',
        'Quando gera petição na Fábrica de Petições',
        'Quando o cliente assina o contrato no Pipeline Comercial',
        'b',
        'O modal abre automaticamente ao mover o card pra coluna Distribuído — não precisa apertar nenhum botão extra. É o gatilho da movimentação.',
        1,
    ),
    array(
        'distribuir-peticao-inicial',
        'Após você salvar os dados de distribuição, o que o sistema dispara automaticamente?',
        'Nada — só atualiza visual do Kanban',
        'Apaga o card do Kanban',
        'Grava case_number, registra andamento "Processo distribuído" e notifica o cliente (Sala VIP + WhatsApp se ativo)',
        'Cobra honorários no Asaas',
        'c',
        'A movimentação dispara 3 ações em cadeia: persistência (case_number/court/comarca), histórico (andamento automático) e comunicação ao cliente.',
        2,
    ),
    array(
        'distribuir-peticao-inicial',
        'Por quanto tempo o card permanece visível na coluna Distribuído do Kanban Operacional?',
        'Para sempre',
        'Apenas no mês em que foi distribuído — depois fica só na lista de Processos (continua com status=distribuido no banco)',
        'Por 7 dias corridos',
        'Até o processo ser arquivado',
        'b',
        'Visibilidade mensal: o card sai do Kanban no mês seguinte pra não poluir a tela. O dado continua no banco e na lista de Processos.',
        3,
    ),
    array(
        'distribuir-peticao-inicial',
        'No modal de distribuição, quando uso o toggle "📋 Extrajudicial"?',
        'Sempre que o processo é digital',
        'Quando o cliente é Pessoa Jurídica',
        'Em medidas que não geram processo judicial — divórcio em cartório, inventário extrajudicial, escritura, etc.',
        'Quando não tenho o número do processo',
        'c',
        'Extrajudicial cobre demandas resolvidas em cartório/notarial. Não tem CNJ; campos são adaptados (cartório, livro, folha) em vez de vara/comarca.',
        4,
    ),

    // cadastrar-processo-existente ──────────────────────────
    array(
        'cadastrar-processo-existente',
        'Quando faz MAIS sentido cadastrar processo direto no Operacional (sem passar pelo Pipeline Comercial)?',
        'Sempre — Pipeline é só pra leads frios',
        'Quando o cliente migrou de outro escritório com processo já ajuizado, recurso de processo antigo ou pasta herdada',
        'Nunca — todo processo deve passar pelo Pipeline',
        'Só quando o cliente já é VIP',
        'b',
        'O Pipeline acompanha funil comercial DESDE o lead. Se o processo já está em curso, faz sentido cadastrar direto no Operacional pra não distorcer o funil.',
        1,
    ),
    array(
        'cadastrar-processo-existente',
        'No campo "Cliente" do form de novo caso, dá pra buscar por:',
        'Só nome',
        'Só CPF (com pontuação obrigatória)',
        'Nome OU CPF (com ou sem pontuação) — autocomplete unificado',
        'Só telefone',
        'c',
        'A busca foi unificada: digitando nome, CPF ou parte dele (3+ dígitos), o sistema normaliza e procura nos dois campos. Funciona com ou sem pontos/traços.',
        2,
    ),
    array(
        'cadastrar-processo-existente',
        'Qual é a convenção de TÍTULO da pasta no escritório?',
        'Pode ser qualquer nome livre',
        '"CLIENTE x TIPO DE AÇÃO" — ex: "MARIA SILVA x Divórcio"',
        'O número do CNJ',
        'Data de cadastro + sobrenome',
        'b',
        'Padrão "Cliente x Ação" é seguido por todo o sistema — relatórios, dashboards e listagens dependem dele. Não invente formato próprio.',
        3,
    ),
    array(
        'cadastrar-processo-existente',
        'Ao adicionar partes (autor, réu, litisconsorte) e o nome bater com cliente em `clients`:',
        'Cria nova entrada de cliente automaticamente',
        'Linka client_id automaticamente, marca o checkbox CLIENTE e puxa o CPF — aparece badge "NOSSO CLIENTE" na pasta',
        'Bloqueia o cadastro pra evitar duplicação',
        'Manda e-mail pro cliente avisando',
        'b',
        'Autocomplete por nome também busca em clients — se achar match exato (case-insensitive), linka automaticamente. CPF do cliente entra no campo doc da parte.',
        4,
    ),

    // vincular-incidentais-recursos ───────────────────────────────────────
    array(
        'vincular-incidentais-recursos',
        'Onde fica a opção de marcar um processo como incidental/recurso de outro?',
        'Botão "+ Vincular processo incidental" (na seção Processos Incidentais)',
        'Dropdown ⚙️ Ações ▾ → 📎 Marcar como incidental de outro',
        'Editando o título do processo',
        'Não tem essa opção',
        'b',
        'O botão "+ Vincular processo incidental" adiciona FILHOS (este processo é o principal). Pra marcar este como FILHO de outro, use ⚙️ Ações → 📎 Marcar como incidental de outro.',
        1,
    ),
    array(
        'vincular-incidentais-recursos',
        'Quando devo escolher "Recurso" em vez de "Incidental"?',
        'Quando o processo é antigo',
        'Quando é uma Apelação, Agravo, Embargos de Declaração, REsp, RE — alguma peça que ATACA decisão do principal',
        'Sempre que tiver número CNJ',
        'Quando é processo de família',
        'b',
        'Recurso = ataque a decisão judicial em instância superior. Incidental = processo que pega carona no principal pra resolver questão acessória (execução, tutela, embargos de terceiro, etc.)',
        2,
    ),
    array(
        'vincular-incidentais-recursos',
        'O que acontece quando seleciono "Outros" no Tipo de relação?',
        'Salva literalmente "Outros" no banco',
        'Aparece campo amarelo obrigatório pra digitar a natureza jurídica (ex: Carta Precatória, Embargos de Terceiro)',
        'Vincula sem precisar especificar',
        'Bloqueia o cadastro',
        'b',
        '"Outros" é genérico demais pra ser útil. Quando escolhe, abre input pra digitar a natureza jurídica real — esse texto é o que vai pro banco em tipo_relacao, não a string "Outros".',
        3,
    ),
    array(
        'vincular-incidentais-recursos',
        'Quais destes EXEMPLOS típicos cabem em "Outros" pra Incidental?',
        'Apelação, Agravo, Embargos de Declaração',
        'Carta Precatória, Embargos de Terceiro, Habilitação de Crédito, Cumprimento Provisório, Impugnação ao Cumprimento, Restauração de Autos',
        'Audiência, Reunião, Prazo',
        'Cliente VIP, Cliente Comum',
        'b',
        'Os típicos: Carta Precatória (cumprimento de ato em outra comarca), Embargos de Terceiro (terceiro reivindica bem), Habilitação de Crédito (em execução/inventário), Cumprimento Provisório (CPC art. 520), Impugnação, Restauração de Autos. Apelação/Agravo/ED são RECURSOS (escolher Recurso → tem opção própria).',
        4,
    ),
    array(
        'vincular-incidentais-recursos',
        'Ao abrir o modal sem digitar nada, o que aparece de "Sugestões"?',
        'Lista aleatória de processos',
        'Outros processos do MESMO CLIENTE + processos com PARTE EM COMUM (badges azul e âmbar)',
        'Os 10 últimos cadastrados',
        'Nada — só após digitar 2+ chars',
        'b',
        'O sistema usa o caso atual pra sugerir: outros casos do mesmo client_id + casos com partes em comum (match em case_partes.nome/razao_social). Badges visuais identificam por qual motivo cada um veio.',
        5,
    ),
);

$stmtQ = $pdo->prepare(
    "INSERT INTO treinamento_quiz
        (modulo_slug, pergunta, opcao_a, opcao_b, opcao_c, opcao_d, resposta_correta, explicacao, ordem)
     VALUES (?,?,?,?,?,?,?,?,?)"
);
foreach ($quizzes as $q) {
    $stmtQ->execute($q);
}
echo "\n✓ " . count($quizzes) . " perguntas inseridas\n\n";

// ─── Libera todos os módulos pra todos os perfis ───
// Amanda pediu: qualquer usuário pode acessar qualquer módulo de treinamento
// (no filtro "Meus" da listagem, módulos com "todos" sempre aparecem).
// Exceções de segurança (financeiro / cobranca-honorarios) ficam mantidas
// por whitelist no modulo.php — independente do perfis_alvo.
$afetados = $pdo->exec("UPDATE treinamento_modulos SET perfis_alvo = '[\"todos\"]'");
echo "✓ {$afetados} módulo(s) atualizado(s) com perfis_alvo=[\"todos\"]\n\n";

echo "=== Migração concluída ===\n";
echo "Acessível em: /conecta/modules/treinamento/modulo.php?slug=distribuir-peticao-inicial\n";
echo "             /conecta/modules/treinamento/modulo.php?slug=cadastrar-processo-existente\n";
