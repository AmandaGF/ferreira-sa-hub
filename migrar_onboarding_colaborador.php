<?php
/**
 * Registra o modulo de treinamento "Onboarding do Colaborador" (Amanda 10/07/2026).
 * Idempotente — pode rodar quantas vezes quiser.
 */
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }

$pdo = db();
header('Content-Type: text/plain; charset=utf-8');
echo "=== Migracao: Onboarding do Colaborador ===\n\n";

// 1) Modulo: ordem=0 pra ficar como PRIMEIRO no grid; perfis_alvo = todos
$slug = 'onboarding-colaborador';
$titulo = 'Onboarding do Colaborador';
$desc = 'Sua jornada pelo Conecta — visao geral de todas as ferramentas do Hub e as regras nao-negociaveis da casa';
$icone = '🎓';
$perfis = '["todos"]';
$ordem = 0;
$pontos = 100;

$stmt = $pdo->prepare("INSERT INTO treinamento_modulos (slug, titulo, descricao, icone, perfis_alvo, ordem, pontos, ativo)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                       ON DUPLICATE KEY UPDATE
                           titulo = VALUES(titulo),
                           descricao = VALUES(descricao),
                           icone = VALUES(icone),
                           perfis_alvo = VALUES(perfis_alvo),
                           ordem = VALUES(ordem),
                           pontos = VALUES(pontos),
                           ativo = 1");
$stmt->execute([$slug, $titulo, $desc, $icone, $perfis, $ordem, $pontos]);
echo "OK modulo '$slug' inserido/atualizado (ordem=$ordem, pontos=$pontos)\n\n";

// 2) Quiz — 10 perguntas cobrindo o conteudo. Delete/reinsert pra ficar sincronizado
$pdo->prepare("DELETE FROM treinamento_quiz WHERE modulo_slug = ?")->execute([$slug]);

$perguntas = array(
    array($slug, 'Qual e a hierarquia central do Conecta?',
        'Tarefa -> Cliente -> Processo',
        'Cliente -> Processo -> Tarefa',
        'Processo -> Cliente -> Tarefa',
        'Sao 3 conceitos independentes, sem hierarquia',
        'b',
        'Um cliente pode ter varios processos; cada processo tem varias tarefas. Toda informacao orbita nessa ordem.',
        1),

    array($slug, 'Um cliente tem 2 acoes (Alimentos + Divorcio). Como fica no sistema?',
        'Um card unico com as duas acoes descritas juntas',
        'Um cliente e duas pastas (cases) separadas — cada acao tem sua propria pasta',
        'Precisa cadastrar como 2 clientes diferentes',
        'So cabe uma acao por cliente',
        'b',
        'Cliente e um so registro. Cada acao judicial (case) tem sua propria pasta com docs, andamentos e tarefas.',
        2),

    array($slug, 'Toda mensagem automatica/agendada ao cliente deve ser assinada como?',
        'Dra. Amanda Ferreira',
        'Seu proprio nome (quem agendou)',
        'Equipe Ferreira & Sa Advocacia',
        'Nao precisa assinar',
        'c',
        'Padrao da casa: mensagens automaticas saem em nome do escritorio, nunca de pessoa fisica — nem da Dra. Amanda, nem de quem agendou.',
        3),

    array($slug, 'Voce esta atendendo um cliente pelo WhatsApp canal 24. Ele responde no 21 tambem. Pode juntar as duas conversas em uma so?',
        'Sim, pra manter o historico organizado',
        'Sim, e ate recomendado — o Hub faz merge automatico',
        'NAO — cada canal e um numero fisico do escritorio, mesclar quebra o fluxo de resposta',
        'Sim, mas so se as duas conversas forem no mesmo dia',
        'c',
        'Cada canal (21 e 24) e um numero fisico diferente. Mesclar impede que a resposta chegue certo — regra nao-negociavel.',
        4),

    array($slug, 'Em listagens do dia a dia, como o CPF do cliente aparece?',
        'Sempre completo, pra facilitar consulta',
        'Mascarado (ex: 070.***.**6-78) — LGPD',
        'Nao aparece nunca',
        'So aparece pra admin/gestao',
        'b',
        'LGPD: telas de uso diario mostram CPF mascarado. CPF completo so em telas de detalhe individual e em exportacao CSV controlada.',
        5),

    array($slug, 'Voce quer nomear uma pasta como "Divorcio — Joao x Maria". Pode?',
        'Sim, sem problema',
        'NAO — travessao (— ou –) e proibido em nomes de pasta/arquivo porque PJe recusa. Use hifen (-) ou espaco',
        'So se voce for admin',
        'Sim, mas so em processos que nao vao pro PJe',
        'b',
        'PJe recusa nomes com travessao. O Hub bloqueia automaticamente, mas e bom saber o motivo.',
        6),

    array($slug, 'Um processo de familia entra no sistema. O checkbox "Segredo de justica" vem como?',
        'Desmarcado por padrao — voce marca se precisar',
        'Marcado por padrao — familia ja e sigilo automaticamente',
        'Nao existe esse checkbox pra familia',
        'Depende do tribunal',
        'b',
        'Processos de familia (Alimentos, Divorcio, Guarda etc) e medidas protetivas ja vem com segredo marcado. Nunca desmarque sem verificar.',
        7),

    array($slug, 'Um card do Kanban ficou "esquecido" ha 3 meses. O sistema arquiva ele automaticamente?',
        'Sim, apos 90 dias vira arquivado automatico',
        'NAO — cards so saem via coluna "Para Arquivar" + botao "Arquivar TODOS" com 2 confirmacoes',
        'Sim, no primeiro dia do mes seguinte',
        'Depende do stage',
        'b',
        'Nao existe cron que apaga/oculta cards. So sai via acao humana explicita com dupla confirmacao. Regra nao-negociavel.',
        8),

    array($slug, 'Voce precisa de ajuda de outra area sobre uma tarefa. Qual e o caminho certo?',
        'Manda WhatsApp pessoal pra pessoa',
        'Chama no corredor',
        'Abre chamado no Helpdesk marcando os responsaveis — eles recebem sino + e-mail automatico',
        'Espera passar 2 dias pra ver se resolve sozinho',
        'c',
        'Helpdesk e o canal interno. Marcando responsaveis, eles recebem notificacao push e e-mail. Fica registrado, nao se perde.',
        9),

    array($slug, 'O atalho Ctrl+K serve pra que no Conecta?',
        'Copiar texto selecionado',
        'Abrir busca universal (cliente, processo, tarefa) de qualquer tela',
        'Salvar o formulario aberto',
        'Fechar a janela',
        'b',
        'Ctrl+K abre a barra de busca universal do Hub. Ctrl+F na sidebar filtra o menu; Ctrl+Shift+H volta pro Painel do Dia.',
        10),
);

$stq = $pdo->prepare("INSERT INTO treinamento_quiz (modulo_slug, pergunta, opcao_a, opcao_b, opcao_c, opcao_d, resposta_correta, explicacao, ordem)
                      VALUES (?,?,?,?,?,?,?,?,?)");
foreach ($perguntas as $p) { $stq->execute($p); }
echo "OK " . count($perguntas) . " perguntas do quiz inseridas\n\n";

// 3) Mostra estado final
$m = $pdo->prepare("SELECT id, slug, titulo, ordem, pontos, ativo FROM treinamento_modulos WHERE slug = ?");
$m->execute([$slug]);
$row = $m->fetch(PDO::FETCH_ASSOC);
echo "Modulo final:\n";
foreach ($row as $k => $v) echo "  $k = $v\n";

echo "\n=== CONCLUIDO ===\n";
echo "Acesse: /conecta/modules/treinamento/ (o card 'Onboarding' aparece primeiro)\n";
echo "Ou direto: /conecta/modules/treinamento/modulo.php?slug=$slug\n";
