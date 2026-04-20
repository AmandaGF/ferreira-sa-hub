<?php
/**
 * Migração: Sistema de Treinamento.
 * - Cria 3 tabelas (modulos, progresso, quiz)
 * - Popula 23 módulos + ~50 perguntas do quiz
 * - Idempotente (safe pra rodar múltiplas vezes)
 */
require_once __DIR__ . '/core/database.php';

$key = $_GET['key'] ?? '';
if ($key !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }

$pdo = db();
header('Content-Type: text/plain; charset=utf-8');
echo "=== Migração Treinamento ===\n\n";

// ─── Schema ───
$pdo->exec("CREATE TABLE IF NOT EXISTS treinamento_modulos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(50) NOT NULL UNIQUE,
    titulo VARCHAR(100) NOT NULL,
    descricao TEXT,
    icone VARCHAR(10),
    perfis_alvo JSON,
    ordem INT DEFAULT 0,
    pontos INT DEFAULT 50,
    ativo TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "✓ treinamento_modulos\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS treinamento_progresso (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    modulo_slug VARCHAR(50) NOT NULL,
    conteudo_visto TINYINT(1) DEFAULT 0,
    missao_feita TINYINT(1) DEFAULT 0,
    quiz_concluido TINYINT(1) DEFAULT 0,
    concluido TINYINT(1) DEFAULT 0,
    quiz_acertos INT DEFAULT 0,
    quiz_tentativas INT DEFAULT 0,
    pontos_ganhos INT DEFAULT 0,
    concluido_em DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_modulo (user_id, modulo_slug),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "✓ treinamento_progresso\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS treinamento_quiz (
    id INT AUTO_INCREMENT PRIMARY KEY,
    modulo_slug VARCHAR(50) NOT NULL,
    pergunta TEXT NOT NULL,
    opcao_a VARCHAR(300) NOT NULL,
    opcao_b VARCHAR(300) NOT NULL,
    opcao_c VARCHAR(300) NOT NULL,
    opcao_d VARCHAR(300) NOT NULL,
    resposta_correta ENUM('a','b','c','d') NOT NULL,
    explicacao TEXT,
    ordem INT DEFAULT 0,
    INDEX idx_modulo (modulo_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "✓ treinamento_quiz\n\n";

// ─── 23 Módulos ───
$modulos = array(
    array('visao-geral','Visão Geral do Hub','O que é o F&S Hub, por que ele existe e como vai mudar sua rotina','🏠','["todos"]',1,30),
    array('painel-dia','Painel do Dia','Sua central de comando diária — agenda, prazos e lembretes em um lugar só','🌅','["todos"]',2,50),
    array('drawer-card','O Card do Cliente','O drawer unificado — coração do sistema com 7 abas','🃏','["todos"]',3,50),
    array('kanban-comercial','Kanban Comercial','Do cadastro do lead até o contrato assinado — passo a passo','📋','["comercial","cx","admin","gestao"]',4,50),
    array('whatsapp-crm','WhatsApp CRM','Atendendo leads e clientes direto pelo portal com bot de IA','💬','["comercial","cx","admin","gestao"]',5,70),
    array('procuracao-regras','Regras de Procuração','Quem assina o quê em cada tipo de ação — sem errar nunca mais','✍️','["comercial","cx","admin"]',6,50),
    array('documentos','Geração de Documentos','Procurações, contratos e ofícios gerados em segundos','📄','["comercial","cx","operacional","admin"]',7,50),
    array('kanban-operacional','Kanban Operacional','Gerenciando processos do recebimento à distribuição','⚙️','["operacional","admin","gestao"]',8,50),
    array('kanban-prev','Kanban PREV','Fluxo especializado para processos previdenciários','🏛️','["operacional","admin","gestao"]',9,50),
    array('fabrica-peticoes','Fábrica de Petições IA','Gerando petições completas com inteligência artificial','🤖','["operacional","admin","gestao"]',10,70),
    array('tarefas','Kanban de Tarefas','Organizando o trabalho diário com cascade automático para prazos','✅','["operacional","admin","gestao"]',11,50),
    array('calculadora-prazos','Calculadora de Prazos','Calculando prazos processuais com dias úteis e suspensões TJRJ','📅','["operacional","admin","gestao"]',12,50),
    array('datajud','DataJud — Sincronização','Puxando andamentos processuais automaticamente do CNJ','🔄','["operacional","admin","gestao"]',13,50),
    array('agenda','Agenda e Compromissos','Audiências, reuniões, prazos e Google Meet integrado','📆','["todos"]',14,50),
    array('sala-vip','Sala VIP do Cliente','Como o cliente acessa o portal e acompanha o processo','⭐','["cx","admin","gestao"]',15,50),
    array('helpdesk','Helpdesk Interno','Abrindo e resolvendo chamados internos da equipe','🎫','["todos"]',16,30),
    array('wiki','Wiki Interna','Base de conhecimento do escritório — consulte antes de perguntar','📚','["todos"]',17,30),
    array('financeiro','Módulo Financeiro','Cobranças, honorários e integração com Asaas','💰','["admin","gestao"]',18,70),
    array('cobranca-honorarios','Cobrança de Honorários','Fluxo completo de cobrança — do alerta ao processo judicial','⚠️','["admin","gestao"]',19,70),
    array('newsletter','Newsletter','Disparos de e-mail para clientes via Brevo','📧','["admin","gestao"]',20,50),
    array('ranking','Ranking e Gamificação','Como funciona o sistema de pontos, níveis e premiações','🏆','["todos"]',21,30),
    array('links-tribunais','Portal de Links e Tribunais','Acessando todos os tribunais e portais jurídicos em um clique','🔗','["todos"]',22,30),
    array('aniversarios','Aniversários e Relacionamento','Como o sistema parabeniza clientes automaticamente','🎂','["cx","admin","gestao"]',23,30),
);

$stmt = $pdo->prepare("INSERT INTO treinamento_modulos (slug, titulo, descricao, icone, perfis_alvo, ordem, pontos) VALUES (?,?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE titulo=VALUES(titulo), descricao=VALUES(descricao), icone=VALUES(icone), perfis_alvo=VALUES(perfis_alvo), ordem=VALUES(ordem), pontos=VALUES(pontos)");
foreach ($modulos as $m) { $stmt->execute($m); }
echo "✓ " . count($modulos) . " módulos inseridos/atualizados\n\n";

// ─── Quiz (limpa e repopula pra refletir mudanças) ───
$pdo->exec("DELETE FROM treinamento_quiz");

$quizzes = array(
    // VISÃO GERAL
    array('visao-geral','O F&S Hub substitui qual ferramenta principal do escritório?','Somente o WhatsApp','Somente planilhas Excel','LegalOne + planilhas + WhatsApp avulso — centralizando tudo','Somente o e-mail','c','O Hub foi criado para centralizar o que antes estava espalhado em várias ferramentas diferentes.',1),
    array('visao-geral','Quem pode ver o Dashboard financeiro do escritório?','Todos os perfis','Somente operacional','Somente Admin, Gestão e Sócios (whitelist [1,3,6])','Somente a Amanda','c','O financeiro tem whitelist rígida — apenas roles 1, 3 e 6 têm acesso.',2),
    array('visao-geral','O sistema é hospedado em:','Servidor local','TurboCloud (LiteSpeed)','AWS','Heroku','b','Hospedagem TurboCloud com LiteSpeed, na raiz /conecta/ do domínio ferreiraesa.com.br.',3),

    // PAINEL DIA
    array('painel-dia','O Painel do Dia mostra eventos de qual período?','Última semana','Próximos 7 dias','Apenas o dia corrente (00:00 às 23:59)','Último mês','c','O painel foca no dia atual — é sua central de comando para o dia presente.',1),
    array('painel-dia','O que aparece automaticamente no Painel do Dia sem você fazer nada?','Só lembretes manuais que você criou','Audiências da agenda + prazos vencendo hoje + tarefas do dia','Somente prazos fatais','Nada — você precisa preencher tudo','b','O sistema puxa automaticamente de agenda_eventos, prazos_processuais e case_tasks.',2),
    array('painel-dia','Se eu não for Admin/Gestão/Sócio, após login vou parar em qual tela?','Dashboard','Painel do Dia','Agenda','Login','b','Dashboard é whitelist. Demais usuários caem direto no Painel do Dia após login.',3),

    // DRAWER CARD
    array('drawer-card','Se você fez um comentário na pasta de Alimentos, ele aparece nas outras pastas do mesmo cliente?','Sim, todos os comentários são compartilhados por cliente','Não — comentários são vinculados à pasta específica (case_id)','Depende do tipo de comentário','Só se você marcar para compartilhar','b','Comentários são vinculados ao case_id — não ao client_id. Cada pasta tem seus próprios comentários.',1),
    array('drawer-card','Quantas abas tem o drawer unificado do cliente?','3 abas','5 abas','7 abas','10 abas','c','O drawer tem 7 abas: Geral, Comercial, Operacional, Docs, Financeiro, Agenda e Histórico.',2),
    array('drawer-card','Qual é a fonte da verdade pra "último andamento" do processo?','cases.updated_at','case_andamentos (tabela dedicada)','case_tasks','Nenhuma — é computado em tempo real','b','case_andamentos é a tabela dedicada e a fonte autoritativa. cases.updated_at muda com qualquer edição.',3),

    // KANBAN COMERCIAL
    array('kanban-comercial','Quando o cliente preenche o formulário público, onde ele aparece no Kanban?','Em Elaboração de Contrato','Em Cadastro Preenchido','Não aparece automaticamente','Em Link Enviado','b','O sistema cria o card automaticamente em Cadastro Preenchido — sem nenhuma ação manual.',1),
    array('kanban-comercial','O que acontece AUTOMATICAMENTE ao mover para Contrato Assinado?','Nada — é só visual','Envia e-mail para o cliente','Cria pasta no Drive + abre caso no Kanban Operacional + notifica cliente','Cancela o lead no pipeline','c','Mover para Contrato Assinado dispara 3 ações automáticas simultâneas (mais msg boas-vindas).',2),
    array('kanban-comercial','Se um cliente tem 2 ações (ex: Alimentos + Convivência), como fica no Kanban?','Um card só com as duas ações juntas','Dois cards separados — um para cada ação','Fica em uma coluna especial de múltiplas ações','Não é possível cadastrar dois processos do mesmo cliente','b','Cada ação tem seu próprio card e sua própria pasta — sempre separados.',3),

    // WHATSAPP CRM
    array('whatsapp-crm','O bot de IA funciona em qual número de WhatsApp?','DDD 24 (CX/Operacional)','Nos dois números','DDD 21 (Comercial)','Em nenhum — é só manual','c','O bot Claude Haiku responde automaticamente no DDD 21 (Comercial) até transferir para humano.',1),
    array('whatsapp-crm','Quando o cliente envia uma palavra como "urgente" ou "violência" para o bot, o que acontece?','O bot responde normalmente','O bot ignora a mensagem','O bot transfere IMEDIATAMENTE para atendimento humano','O bot pede para repetir','c','Palavras de urgência disparam transferência imediata para humano — regra de segurança do bot.',2),
    array('whatsapp-crm','Após alguém assumir uma conversa no DDD 21, por quanto tempo fica travada pra outros usuários?','Indefinidamente','1 hora','30 minutos sem interação nova','24 horas','c','Trava expira automaticamente após 30 min sem atividade. DDD 24 não trava.',3),

    // PROCURAÇÃO
    array('procuracao-regras','Em uma ação de Pensão Alimentícia, a procuração fica no nome de quem?','Do pai ou mãe que nos contratou','Da criança, representada pelo genitor responsável','De ambos os genitores','Do advogado responsável','b','Em alimentos quem pede é a criança — ela outorga poderes ao escritório, representada pelo responsável.',1),
    array('procuracao-regras','Em Regulamentação de Convivência ou Guarda, a procuração fica no nome de quem?','Da criança','De ambos os genitores','Do pai ou mãe que nos contratou','Do juiz','c','Convivência, guarda e divórcio são pedidos pelo adulto contratante — ele outorga os poderes.',2),
    array('procuracao-regras','Em uma ação de Execução de Alimentos, no nome de quem fica a procuração?','Do pai ou mãe contratante','Do advogado','Da criança (alimentanda)','Do INSS','c','Execução de alimentos segue a mesma regra dos alimentos — procuração no nome da criança.',3),

    // KANBAN OPERACIONAL
    array('kanban-operacional','O que acontece no Pipeline Comercial quando você move um caso para Doc Faltante no Operacional?','Nada — são independentes','O card vai automaticamente para Doc Faltante no Pipeline também','O card é cancelado no Pipeline','O card vai para Pasta Apta','b','O espelhamento é bilateral — Doc Faltante no Operacional reflete automaticamente no Pipeline.',1),
    array('kanban-operacional','O que fazer quando um processo não pode ser distribuído por causa de um processo prejudicial?','Deixar em Doc Faltante','Cancelar o caso','Mover para a coluna Suspenso com motivo e data de retorno','Criar um novo card','c','A coluna Suspenso tem modal de motivo — sempre registre o motivo e vincule ao processo prejudicial.',2),
    array('kanban-operacional','Ao mover para Processo Distribuído, o que o sistema verifica primeiro?','Nada — pede o número direto','Se o cliente tem número de processo já cadastrado para evitar pedir de novo','Se o contrato está assinado','Se tem documentos pendentes','b','O sistema verifica se case_number já existe antes de pedir — evitando duplicação.',3),

    // KANBAN PREV
    array('kanban-prev','Por quanto tempo um card PREV aparece no Kanban Operacional?','Para sempre','Nunca aparece no Operacional','Somente durante o mês em que foi enviado para o PREV','Por 3 meses','c','A regra de visibilidade mensal faz o card sumir do Operacional no mês seguinte ao envio.',1),
    array('kanban-prev','Quais tipos de processo vão para o Kanban PREV?','Todos os processos do escritório','Somente processos trabalhistas','INSS, BPC, LOAS, Aposentadoria, Auxílio-Doença e similares','Somente processos de família','c','O Kanban PREV é exclusivo para processos previdenciários.',2),

    // FÁBRICA PETIÇÕES
    array('fabrica-peticoes','Qual modelo de IA é usado para gerar as petições?','ChatGPT','Claude Haiku','Claude Sonnet 4.6','Gemini','c','A Fábrica usa Claude Sonnet 4.6 com prompt caching para economizar ~90% no input.',1),
    array('fabrica-peticoes','Em petições iniciais no JEC/JEF, o que NUNCA deve ser incluído?','O valor da causa','Seção de Gratuidade de Justiça','Os pedidos','A qualificação das partes','b','JEC/JEF: nunca incluir seção de gratuidade em petições iniciais — só no Recurso Inominado.',2),
    array('fabrica-peticoes','Em alimentos, o polo ativo é:','O pai/mãe contratante','A criança','Ambos os genitores','O advogado','b','Em alimentos o polo ativo é sempre a criança (mesmo representada pelo genitor).',3),

    // TAREFAS
    array('tarefas','Quando você cria uma tarefa do tipo Prazo Processual, o que acontece automaticamente?','Nada — é só uma tarefa visual','Cria também um registro em prazos_processuais e um evento na Agenda','Envia e-mail para o cliente','Gera uma petição automaticamente','b','Cascade: cria prazo em prazos_processuais E evento na agenda simultaneamente.',1),
    array('tarefas','Tarefas sem tipo preenchido (NULL) aparecem onde?','No Kanban de Tarefas','Apenas dentro da pasta do processo (checklist) — não no Kanban','Em lugar nenhum','Na agenda','b','Tarefas com tipo=NULL são checklist de documentos. Só entram no Kanban de Tarefas se tipo for preenchido.',2),

    // CALCULADORA PRAZOS
    array('calculadora-prazos','A calculadora conta qual tipo de dia ao calcular prazos processuais?','Todos os dias corridos','Somente dias úteis, excluindo fins de semana e suspensões TJRJ','Somente dias de semana sem feriados nacionais','Dias corridos menos fins de semana','b','A calculadora conta apenas dias úteis e já conhece as suspensões do TJRJ cadastradas.',1),
    array('calculadora-prazos','Quando começa a contar o prazo processual após a publicação?','No mesmo dia da publicação','No dia seguinte à publicação','No primeiro dia útil após a publicação','Dois dias úteis após a publicação','c','Disponibilização → Publicação (D+1) → Início da contagem (primeiro dia útil após D+1).',2),

    // DATAJUD
    array('datajud','O DataJud consegue puxar andamentos de processos em segredo de justiça?','Sim, sempre','Não, nunca','Tenta sempre — se não retornar, registra como segredo e tenta amanhã','Só com autorização judicial','c','O sistema tenta TODOS os processos com número cadastrado — nunca bloqueia preventivamente.',1),
    array('datajud','Com que frequência o DataJud sincroniza automaticamente?','A cada hora','Uma vez por semana','Todo dia às 07h00 via cron + botão manual disponível a qualquer hora','Somente quando você clicar no botão','c','Sync automático diário às 07h + botão manual para quando precisar na hora.',2),

    // AGENDA
    array('agenda','O Balcão Virtual do TJRJ só pode ser agendado em qual janela?','Qualquer horário','Entre 11h e 17h','Apenas manhã','Apenas tarde','b','Regra de negócio: Balcão Virtual TJRJ aceita agendamento apenas entre 11:00 e 17:00.',1),
    array('agenda','Quando crio um compromisso vinculado a processo, o que acontece automaticamente?','Nada — é só evento','Cria andamento no processo automaticamente','Envia e-mail pro juiz','Abre petição','b','Compromisso com case_id vinculado gera andamento automático no processo (com link orientação se for audiência).',2),

    // SALA VIP
    array('sala-vip','Como o cliente ativa a conta na Sala VIP?','Admin cadastra senha manualmente','Clicando no link de ativação recebido no WhatsApp (válido 72h) e criando senha','Não existe ativação — entra direto','Via reconhecimento facial','b','Fluxo: escritório clica "🔑 Portal" no chat → cliente recebe link 72h → ativa + cria senha.',1),

    // HELPDESK
    array('helpdesk','Para que serve o Helpdesk interno do portal?','Para atender clientes externos','Para a equipe abrir chamados internos sobre problemas ou dúvidas do sistema','Para gerar petições','Para enviar e-mails para clientes','b','O Helpdesk é para comunicação interna da equipe — dúvidas, problemas e solicitações.',1),
    array('helpdesk','Como funciona a @menção em um chamado do Helpdesk?','Só visual, sem efeito','Pessoa mencionada recebe sino + e-mail Brevo automaticamente','Envia WhatsApp pra pessoa','Nada — precisa avisar manualmente','b','@mencao abre autocomplete, e ao salvar envia notificação no sino + e-mail Brevo pra pessoa.',2),

    // WIKI
    array('wiki','Antes de perguntar para a Amanda ou para um colega, o que você deve fazer?','Enviar WhatsApp direto','Abrir um chamado no Helpdesk','Consultar a Wiki interna — a resposta provavelmente já está lá','Pesquisar no Google','c','A Wiki foi criada exatamente para isso — responder dúvidas recorrentes sem interromper ninguém.',1),

    // FINANCEIRO
    array('financeiro','Qual plataforma de pagamentos está integrada ao módulo financeiro?','PagSeguro','Mercado Pago','Stripe','Asaas','d','O escritório usa Asaas em produção (api.asaas.com/v3) para cobranças e assinaturas.',1),
    array('financeiro','Quais perfis têm acesso ao módulo financeiro?','Todos os perfis','Somente operacional','Admin, Gestão e Sócios — whitelist [1,3,6]','Somente a Amanda','c','O financeiro tem whitelist rígida — acesso restrito aos roles 1, 3 e 6.',2),

    // COBRANÇA HONORÁRIOS
    array('cobranca-honorarios','Após quantos dias de atraso o cliente entra automaticamente no fluxo de cobrança?','30 dias','60 dias','90 dias','120 dias','c','O cron diário detecta cobranças vencidas há 90+ dias e cria entrada automática.',1),
    array('cobranca-honorarios','Qual é a sequência correta do fluxo de cobrança?','Judicial direto','WhatsApp amigável → WhatsApp formal → Notificação Extrajudicial → Judicial','E-mail → WhatsApp → Judicial','Notificação Extrajudicial → WhatsApp → Judicial','b','São 4 etapas progressivas antes de encerrar na cobrança judicial.',2),

    // NEWSLETTER
    array('newsletter','Newsletters são enviadas via qual serviço?','Gmail','SendGrid','Brevo (ex-Sendinblue)','Mailchimp','c','Brevo integra com webhook pra tracking de aberturas, cliques e descadastros.',1),

    // RANKING
    array('ranking','O ranking é dividido por quê?','Por tempo de empresa','Por área — Comercial (contratos) e Operacional (petições distribuídas)','Um único ranking geral para todos','Por salário','b','Dois rankings separados para ser justo — métricas diferentes por área.',1),
    array('ranking','Quantos pontos você ganha ao fechar um contrato?','10 pontos','25 pontos','50 pontos','100 pontos','c','Contrato assinado = 50 pontos no ranking Comercial.',2),

    // LINKS
    array('links-tribunais','Como acessar rapidamente o PJe TJRJ pelo portal?','Digitando a URL manualmente no navegador','Pelo Portal de Links → categoria Tribunais → PJe TJRJ','Abrindo o Google e pesquisando','Não é possível pelo portal','b','O Portal de Links centraliza todos os acessos organizados por categoria — inclusive credenciais (AES-256).',1),

    // ANIVERSÁRIOS
    array('aniversarios','O sistema de aniversários funciona de forma automática ou manual?','Manual — você precisa enviar a mensagem','Automático — cron diário às 09h envia parabéns pelo DDD 24','Semi-automático — o sistema avisa mas você envia','Não existe essa funcionalidade','b','Cron diário às 09h detecta aniversariantes e envia automaticamente com 5 templates rotacionados.',1),

    // DOCUMENTOS
    array('documentos','Quando preencho os dados pra gerar uma procuração, onde eles ficam salvos?','Nenhum lugar — é gerado e esquecido','Na tabela document_history com params_json (backup crítico)','Só no arquivo .docx','Na agenda','b','document_history.params_json é backup crítico — útil pra recuperar valores que se perderam.',1),
);

$stmt = $pdo->prepare("INSERT INTO treinamento_quiz (modulo_slug, pergunta, opcao_a, opcao_b, opcao_c, opcao_d, resposta_correta, explicacao, ordem) VALUES (?,?,?,?,?,?,?,?,?)");
foreach ($quizzes as $q) { $stmt->execute($q); }
echo "✓ " . count($quizzes) . " perguntas inseridas\n\n";

echo "=== CONCLUÍDO ===\n";
