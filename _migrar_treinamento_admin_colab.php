<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$slug = 'admin-cadastrar-colaborador';

// Ordem 26 (depois dos 25 iniciais). Perfil so admin.
$pdo->prepare("INSERT INTO treinamento_modulos (slug, titulo, descricao, icone, perfis_alvo, ordem, pontos, ativo)
               VALUES (?, ?, ?, ?, ?, ?, ?, 1)
               ON DUPLICATE KEY UPDATE
                   titulo = VALUES(titulo), descricao = VALUES(descricao),
                   icone = VALUES(icone), perfis_alvo = VALUES(perfis_alvo),
                   ordem = VALUES(ordem), pontos = VALUES(pontos), ativo = 1")
   ->execute([
       $slug,
       'Cadastrar Colaborador (Admin)',
       'Cadastro completo de novo colaborador: dados, perfil de cargo, remuneracao, documentos e link de boas-vindas',
       '👔', '["admin"]', 26, 80
   ]);

$pdo->prepare("DELETE FROM treinamento_quiz WHERE modulo_slug = ?")->execute([$slug]);

$perguntas = array(
    array($slug, 'Quais sao os 2 campos OBRIGATORIOS pra criar o cadastro?',
        'Nome + CPF',
        'Nome completo + data de nascimento',
        'CPF + e-mail institucional',
        'Nome + telefone',
        'b',
        'Nome e data de nascimento — sao a chave de login que a colaboradora usa pra acessar a pagina publica dela.', 1),

    array($slug, 'Voce preencheu o CPF mas deixou o campo "Senha inicial" em branco. O que o sistema faz?',
        'Nao gera senha — colaboradora tem que criar depois',
        'Gera senha aleatoria de 8 caracteres',
        'Gera senha padrao FSA = CPF completo (11 digitos sem pontuacao) + @',
        'Retorna erro pedindo pra preencher a senha',
        'c',
        'Padrao da casa: CPF 12345678900 vira senha "12345678900@". So nao aplica se voce digitar uma senha manual no campo.', 2),

    array($slug, 'Voce cadastrou como "estagiaria" e depois de 1 semana ela virou CLT. Ao mudar o perfil, o que acontece com os documentos ja vinculados?',
        'Somem automaticamente — o sistema limpa o que nao aplica ao novo perfil',
        'Ficam la — voce precisa arquivar/desmarcar manualmente pra preservar historico de assinaturas',
        'Sao migrados automaticamente pros documentos equivalentes do CLT',
        'O sistema bloqueia a mudanca de perfil',
        'b',
        'Sistema preserva historico. Assinaturas ja coletadas nao sao apagadas — voce arquiva o que nao serve mais.', 3),

    array($slug, 'O link de boas-vindas vazou (WhatsApp errado). Qual e a acao correta?',
        'Excluir o cadastro e criar de novo',
        'Nao ha o que fazer — quem tem o link entra',
        'Clicar em "Regenerar" — o sistema gera novo token e invalida o antigo na hora',
        'Trocar a senha da colaboradora',
        'c',
        'Botao "Regenerar" existe exatamente pra isso. Novo token, antigo para de funcionar imediatamente.', 4),

    array($slug, 'Qual e a diferenca entre "Arquivar" e "Excluir" o cadastro?',
        'Nenhuma — sao sinonimos',
        'Arquivar some da lista mas preserva dados/assinaturas; Excluir apaga PRA SEMPRE e so e permitido se nunca foi acessado',
        'Arquivar apaga; Excluir preserva',
        'Ambos apagam — mas Arquivar avisa a colaboradora por e-mail',
        'b',
        'Arquivar e reversivel e mantem historico (LGPD). Excluir e definitivo e so vale antes do primeiro acesso.', 5),

    array($slug, 'Voce vai cadastrar um Prestador PJ. Quais campos ESPECIFICOS dele aparecem que nao apareceriam pra um CLT?',
        'CNPJ, razao social, escopo de servicos, dados bancarios, se emite NF, periodo do contrato',
        'Modalidade de estagio (I ou II)',
        'Semestre da faculdade e instituicao de ensino',
        'Nenhum campo especifico — todos os perfis usam o mesmo formulario',
        'a',
        'Perfil "prestador_pj/mei" liga os campos de CNPJ, razao social, escopo, dados bancarios, NF e periodo.', 6),

    array($slug, 'A colaboradora cadastrou WhatsApp mas a foto do perfil nao apareceu. Qual e o motivo mais comum?',
        'Bug do sistema — abra chamado no helpdesk',
        'Ela precisa ativar a conta primeiro',
        'Perfil dela esta com foto privada (nao "Todos") — pedir pra ela liberar e clicar em "Buscar foto do WhatsApp" de novo',
        'Z-API cobra a mais pra puxar foto',
        'c',
        'Z-API so consegue puxar foto publica. Se o perfil dela e "Meus contatos" ou "Ninguem", falha. Solucao: privacidade Todos, depois voce reclica.', 7),

    array($slug, 'A ficha de seguro pro corretor gera um PDF. O que voce escolhe antes de gerar?',
        'O nome do corretor',
        'O valor da cobertura (R$ 30k a R$ 500k)',
        'A data de vencimento da apolice',
        'Nada — sai automatico',
        'b',
        'Antes de gerar, escolha o valor de cobertura no dropdown. PDF sai formatado com identidade visual + cobertura escolhida.', 8),

    array($slug, 'A colaboradora salvou o cadastro mas ainda nao acessou. Qual e o status dela?',
        'ativo', 'pendente', 'aceito', 'arquivado',
        'b',
        'Fluxo: pendente (criado) -> ativo (acessou o link) -> aceito (assinou todos os documentos).', 9),

    array($slug, 'Ao gravar o cadastro, o campo "Nome completo" precisa ser exatamente igual ao que ela vai digitar depois. Se voce escreveu "Ana Beatriz Ferrera" e o certo era "Ferreira", o que acontece?',
        'Ela consegue entrar mesmo assim — o sistema faz busca aproximada',
        'Ela nao consegue entrar — precisa que voce edite o cadastro pra corrigir',
        'O sistema envia SMS pedindo confirmacao do nome',
        'A colaboradora pode digitar qualquer variante do nome',
        'b',
        'Nome completo e chave de login (junto com data nasc). Erro de digitacao trava o acesso — corrija ANTES de mandar o link.', 10),
);

$stq = $pdo->prepare("INSERT INTO treinamento_quiz (modulo_slug, pergunta, opcao_a, opcao_b, opcao_c, opcao_d, resposta_correta, explicacao, ordem)
                      VALUES (?,?,?,?,?,?,?,?,?)");
foreach ($perguntas as $p) $stq->execute($p);

echo "OK modulo '$slug' inserido (10 perguntas)\n";
echo "Acesse: /conecta/modules/treinamento/modulo.php?slug=$slug\n";
