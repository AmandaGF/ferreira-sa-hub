<?php
/**
 * Migração: módulo de treinamento "Linha do Tempo do Cliente"
 *
 * Ensina a equipe a montar e publicar a página narrativa que o cliente
 * recebe por link exclusivo (aba 🕰️ Linha do Tempo na pasta do processo).
 *
 * Idempotente: ON DUPLICATE KEY UPDATE pro módulo; DELETE+INSERT pro quiz.
 *
 * Uso: ?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/database.php';

$key = $_GET['key'] ?? '';
if ($key !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }

$pdo = db();
header('Content-Type: text/plain; charset=utf-8');
echo "=== Treinamento: módulo Linha do Tempo do Cliente ===\n\n";

// ─── Módulo ───
$pdo->prepare(
    "INSERT INTO treinamento_modulos (slug, titulo, descricao, icone, perfis_alvo, ordem, pontos)
     VALUES (?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE
        titulo      = VALUES(titulo),
        descricao   = VALUES(descricao),
        icone       = VALUES(icone),
        perfis_alvo = VALUES(perfis_alvo),
        ordem       = VALUES(ordem),
        pontos      = VALUES(pontos)"
)->execute(array(
    'linha-tempo-cliente',
    'Linha do Tempo do Cliente',
    'A página exclusiva e animada que conta a história do processo pro cliente — rascunho com IA, revisão sua, link com trava por CPF',
    '🕰️',
    '["todos"]',
    28,
    70,
));
echo "✓ módulo: linha-tempo-cliente\n";

// ─── Quiz ───
$pdo->prepare("DELETE FROM treinamento_quiz WHERE modulo_slug = 'linha-tempo-cliente'")->execute();

$quizzes = array(
    array(
        'linha-tempo-cliente',
        'O que a Linha do Tempo faz de diferente de qualquer outro acompanhamento processual?',
        'Mostra o número do CNJ em destaque',
        'O espaço entre um marco e outro é proporcional ao tempo real que passou — e vãos longos vêm escritos ("8 meses de espera")',
        'Manda um e-mail automático toda semana',
        'Deixa o cliente responder dentro da própria página',
        'b',
        'A espera é o que o cliente de fato vive. Em vez de esconder a demora, a página mede e nomeia — é isso que responde "meu processo está parado?" antes de ele perguntar.',
        1,
    ),
    array(
        'linha-tempo-cliente',
        'Depois de gerar o rascunho com IA, qual é a sua obrigação antes de publicar?',
        'Nenhuma — a IA já entrega pronto pro cliente',
        'Só conferir se as datas estão certas',
        'Ler tudo, cortar marco burocrático, corrigir o que ela entendeu errado e garantir que não há promessa de resultado',
        'Traduzir o texto pro juridiquês correto',
        'c',
        'A IA entrega RASCUNHO. O que vai ao ar é responsabilidade de quem publicou. Ela pode interpretar mal um despacho ambíguo, e nunca pode prometer resultado.',
        2,
    ),
    array(
        'linha-tempo-cliente',
        'Você editou um marco à mão e depois clicou em "Gerar rascunho com IA" de novo. O que acontece com a sua edição?',
        'É sobrescrita pelo texto novo da IA',
        'Sobrevive intacta — marcos com a etiqueta verde "editado à mão" a IA nunca toca',
        'O sistema bloqueia a nova geração',
        'Vira um marco duplicado',
        'b',
        'Assim que você salva um marco, ele é marcado como editado_manual e fica protegido. A IA só apaga e reescreve os marcos que ela mesma criou e ninguém mexeu.',
        3,
    ),
    array(
        'linha-tempo-cliente',
        'A linha do tempo está em RASCUNHO (ainda não publicada). O que o cliente vê ao abrir o link?',
        'A página normal, só sem os marcos',
        'Um aviso de "em construção"',
        '"Página não encontrada" — em rascunho o link só abre pra quem está logado no Hub',
        'A tela de CPF, e depois uma página vazia',
        'c',
        'Rascunho serve de pré-visualização pra equipe. Pro cliente o link simplesmente não existe até você clicar em Publicar.',
        4,
    ),
    array(
        'linha-tempo-cliente',
        'No campo que monta a frase da tela de entrada ("Informe o CPF ___ para abrir"), o que NUNCA pode ser escrito?',
        'A palavra "CPF"',
        'O nome de qualquer pessoa — esse texto aparece ANTES de a pessoa se identificar, então qualquer um com o link lê',
        'Qualquer texto — o campo é só interno',
        'Abreviações',
        'b',
        'Escreva "da representante legal", nunca "da Maria Silva". Se o link vazar, o nome vaza junto — e em segredo de justiça isso é grave.',
        5,
    ),
    array(
        'linha-tempo-cliente',
        'Quantos marcos devem receber o interruptor "destaque"?',
        'Todos os favoráveis ao cliente',
        'Nenhum — o destaque é só decorativo',
        'Um, no máximo dois no caso inteiro — a virada de verdade',
        'Pelo menos metade, pra página não ficar monótona',
        'c',
        'O destaque deixa o marco maior e em rosé. Se tudo é destaque, nada é. Reserve pra liminar que garantiu a pensão, sentença favorável — a virada real.',
        6,
    ),
    array(
        'linha-tempo-cliente',
        'Você clicou em "📱 Enviar no WhatsApp". O que acontece em seguida?',
        'A mensagem é disparada na hora pro cliente',
        'Abre um modal com a mensagem e o link encurtado pra você revisar — nada sai sem você clicar em "Enviar agora"',
        'Copia o link pra área de transferência e você cola no WhatsApp à mão',
        'Agenda o envio pro dia seguinte',
        'b',
        'Nenhuma comunicação com cliente sai sem revisão humana. O modal monta a mensagem com o link curto e espera seu OK. Vai pelo canal 24 (Operacional).',
        7,
    ),
    array(
        'linha-tempo-cliente',
        'O link exclusivo de um cliente foi parar num grupo de WhatsApp por engano. Qual é a ação correta?',
        'Despublicar e nunca mais usar essa linha do tempo',
        'Não fazer nada — o CPF já protege',
        'Clicar em "🔄 Gerar link novo": o link antigo morre na hora, e aí você reenvia o novo pro cliente',
        'Apagar os marcos um por um',
        'c',
        'Regenerar mata o link antigo imediatamente. Só lembre que ele para de funcionar pra todo mundo, inclusive pro próprio cliente — reenvie o novo em seguida.',
        8,
    ),
    array(
        'linha-tempo-cliente',
        'Qual título de marco está escrito do jeito certo pra essa página?',
        '"Decisão liminar deferida nos autos"',
        '"Deferimento de tutela de urgência — art. 300 CPC"',
        '"A juíza garantiu a pensão já no começo"',
        '"Movimentação processual relevante"',
        'c',
        'O título já deve contar a notícia, em português comum. O corpo do marco explica o efeito prático: "a partir dessa data o pai passou a ser obrigado a pagar todo mês".',
        9,
    ),
    array(
        'linha-tempo-cliente',
        'Qual a diferença entre a Linha do Tempo e a Central VIP?',
        'São a mesma coisa com nomes diferentes',
        'A Central VIP é um portal com conta, senha e documentos; a Linha do Tempo é uma peça única sem senha de sistema, que abre com o CPF e conta a história do caso',
        'A Linha do Tempo substitui a Central VIP',
        'A Central VIP é pra equipe e a Linha do Tempo é pro cliente',
        'b',
        'A Linha do Tempo não tem documentos, mensagens nem GED. É peça de relacionamento, não canal de atendimento — as duas convivem.',
        10,
    ),
);

$stmtQ = $pdo->prepare(
    "INSERT INTO treinamento_quiz
        (modulo_slug, pergunta, opcao_a, opcao_b, opcao_c, opcao_d, resposta_correta, explicacao, ordem)
     VALUES (?,?,?,?,?,?,?,?,?)"
);
foreach ($quizzes as $q) { $stmtQ->execute($q); }
echo "✓ " . count($quizzes) . " perguntas inseridas\n\n";

echo "=== Migração concluída ===\n";
echo "Acessível em: /conecta/modules/treinamento/modulo.php?slug=linha-tempo-cliente\n";
