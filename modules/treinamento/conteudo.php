<?php
/**
 * Conteúdo didático dos 23 módulos de treinamento.
 * Array associativo indexado pelo slug.
 * Cada módulo: por_que (string), passos (array), atencao (string|null),
 *              dica (string|null), missao (string).
 * Formato: markdown-like simples (quebras de linha viram <br>, **negrito**, etc.)
 */
return array(

'visao-geral' => array(
    'por_que' => 'O F&S Hub é o sistema que centraliza TUDO que antes estava espalhado: LegalOne, planilhas Excel, WhatsApp avulso, caderno, e-mails. Em vez de lembrar onde cada informação mora, você tem um só lugar — organizado por cliente, por processo e por tarefa.',
    'passos' => array(
        'Todo fluxo começa pelo **Painel do Dia** (sua home) ou pelo **Kanban** da sua área.',
        'Cada cliente tem uma **ficha única** — acessível pela busca (Ctrl+K ou menu lateral).',
        'Cada processo (case) tem uma **pasta própria** com: partes, docs pendentes, andamentos, tarefas, prazos, agenda.',
        'O **drawer** (aba lateral) mostra tudo desse cliente num só clique — 7 abas.',
        'Movimentações entre Kanbans disparam **gatilhos automáticos** (cria pasta, notifica cliente, etc.)',
    ),
    'atencao' => 'Não cadastre o mesmo cliente 2 vezes. Use a busca pelo nome/CPF/telefone antes de criar — o sistema dedup automático, mas melhor prevenir.',
    'dica' => 'O atalho **Ctrl+F** na sidebar filtra o menu em tempo real. Digite "whats" e só módulos de WhatsApp aparecem.',
    'missao' => 'Abra seu **Painel do Dia** e localize um evento, uma tarefa e um prazo. Se não tiver nenhum, crie uma tarefa pra você "Conhecer o Hub" com prazo de amanhã.',
),

'painel-dia' => array(
    'por_que' => 'O Painel do Dia é sua **central de comando**. Aqui você vê o que precisa fazer hoje — audiências, prazos e tarefas — sem precisar abrir 3 kanbans diferentes.',
    'passos' => array(
        'Entra automaticamente após login (se você não for Admin/Gestão/Sócio).',
        'Lista tudo do dia corrente: audiências, reuniões, prazos vencendo, tarefas do dia.',
        'Informação puxada automaticamente de `agenda_eventos`, `prazos_processuais` e `case_tasks`.',
        'Clica em qualquer item pra abrir a pasta do processo correspondente.',
        'No fim do dia, marque tarefas como concluídas — alimentam o ranking.',
    ),
    'atencao' => 'Se algum item estiver faltando, pode ser que não está marcado com a data certa. Ex: tarefa sem `due_date=hoje` não aparece.',
    'dica' => 'Os itens vermelhos são **urgentes** (prazos vencendo hoje). Atacar os vermelhos primeiro.',
    'missao' => 'Abra seu Painel do Dia e conte quantas audiências você tem esta semana (próximos 7 dias).',
),

'drawer-card' => array(
    'por_que' => 'O drawer é o **coração do sistema** — quando você clica num cliente ou processo em qualquer lugar do Hub, ele abre à direita com TUDO: dados, processos, docs, financeiro, agenda, histórico. Em vez de navegar por 5 telas, você tem tudo numa.',
    'passos' => array(
        'Clica no nome de qualquer cliente em qualquer kanban/lista → drawer abre.',
        '**7 abas na ordem:** Geral · Comercial · Operacional · Docs · Financeiro · Agenda · Histórico.',
        '**Geral** — dados de contato, filhos, endereço, status.',
        '**Comercial** — leads do Pipeline, valor honorários, forma pagamento.',
        '**Operacional** — processos (cases), status, vara, nº processo.',
        '**Docs** — checklist de documentos pendentes + GED dessa pasta.',
        '**Financeiro** — cobranças Asaas, pagos, vencidos, propostas.',
        '**Agenda** — eventos vinculados a esse cliente.',
        '**Histórico** — comentários e andamentos (específicos da pasta aberta, não do cliente).',
    ),
    'atencao' => 'Comentários e andamentos são vinculados à **pasta específica** (`case_id`), NÃO ao cliente. Se você comentou na pasta de Alimentos, NÃO aparece em Convivência do mesmo cliente. Cada pasta tem sua timeline.',
    'dica' => 'Na aba Docs, marque um documento como recebido pra destravar o caso de `doc_faltante` automaticamente.',
    'missao' => 'Abra o drawer de qualquer cliente com mais de 1 processo. Percorra as 7 abas e observe como os dados mudam conforme a pasta que você seleciona.',
),

'kanban-comercial' => array(
    'por_que' => 'O Kanban Comercial acompanha o lead desde o primeiro contato até o contrato assinado. É o funil que o comercial toca todos os dias — 10 colunas com gatilhos automáticos em cada movimentação.',
    'passos' => array(
        'Cliente preenche formulário público (site ou landing) → card aparece em **Cadastro Preenchido**.',
        'Comercial move pra **Elaboração Docs** → gera procuração/contrato pela Fábrica.',
        'Move pra **Link Enviado** → marca que os docs foram enviados pra cliente.',
        'Cliente assina → **Contrato Assinado** → sistema AUTOMATICAMENTE: cria pasta no Drive, abre caso no Operacional, envia msg de boas-vindas.',
        'Próximas etapas: Agendado Docs → Reunião/Cobrança → Pasta Apta (encerra ciclo comercial).',
        '**Suspenso** (só Admin) — salva coluna anterior pra restaurar depois.',
        '**Cancelado** (só Admin) — também cancela o caso vinculado.',
        '**Arquivado** (via drawer) — some do kanban sem afetar métricas.',
    ),
    'atencao' => 'Cada ação é um card separado. Cliente com Alimentos + Convivência = 2 cards distintos, em colunas possivelmente diferentes. Não tente juntar.',
    'dica' => 'Na **aba Tabela**, você edita nome/telefone/honorários inline — muito mais rápido que abrir cada card.',
    'missao' => 'Acha um lead em Cadastro Preenchido e abra o drawer dele. Verifica qual é o tipo de ação pra saber qual procuração gerar.',
),

'whatsapp-crm' => array(
    'por_que' => 'Atende cliente pelo WhatsApp direto do Hub — sem precisar sair do sistema. 2 canais: **DDD 21** (Comercial) e **DDD 24** (CX/Operacional). Histórico completo, integrado ao cliente, com trava por atendente, etiquetas automáticas e cronômetro de liberação.',
    'passos' => array(
        'Entra em **WhatsApp Comercial (21)** ou **WhatsApp CX (24)** na sidebar.',
        'Filtros: Todos · Aguardando · Em atend. · Não lidas · Resolv. · 🔓 AT Desbloq. · 🏷 Etiqueta · 👥 Atendente.',
        '**DDD 21 tem bot IA** (Claude Haiku) que responde até transferir pra humano.',
        '**Assumir** trava a conversa pra você (só DDD 21). DDD 24 é colaborativo.',
        '**Trava libera em 2 situações:** (a) cliente é última msg há >**8h úteis** (seg-sex, 9h-18h); (b) equipe é última msg há >36h corridas (follow-up).',
        'Cronômetro no topo mostra **quanto falta pra liberar** — atualiza ao vivo.',
        '**Etiqueta 🔓 AT DESBLOQUEADO** aplicada AUTOMATICAMENTE quando a trava libera — botão atalho filtra.',
        'Envia: texto, imagem, documento, áudio (com preview antes de enviar), figurinha, reação.',
        'Botões: 📞 Ligar (Nvoip) · 📋 Chamado · 🔑 Portal · 🎯 Delegar (só Amanda/Luiz) · 🔀 Mesclar duplicatas.',
    ),
    'atencao' => '**NUNCA mesclar conversas entre canais 21 e 24** — são números físicos diferentes do escritório, juntar quebra o fluxo de resposta. Mesclar só vale DENTRO do mesmo canal (duplicatas @lid).',
    'dica' => 'Clicou **⏹** no áudio? Ele PARA (não envia). Você escuta o preview e clica **➤ Enviar** pra mandar. Pra fluxo rápido tipo WhatsApp normal, clica **➤ Enviar durante a gravação** que ele para+envia direto.',
    'missao' => 'Abra uma conversa travada (🔒 no topo), observe o cronômetro de liberação e filtre por **🔓 AT Desbloq.** pra ver leads que perderam atendimento.',
),

'ligacoes-nvoip' => array(
    'por_que' => 'Ligações telefônicas direto pelo Hub via Nvoip — com 1 clique em "📞 Ligar" em qualquer tela de cliente/processo/conversa. Cada chamada é **gravada automaticamente**, **transcrita** por IA e **resumida em 3 linhas** — tudo vinculado ao cliente/processo. Nada manual, nada perdido.',
    'passos' => array(
        'Sidebar Comercial → **📞 Ligações** pra ver histórico geral, passo a passo e credenciais.',
        '**Antes de ligar:** abra o painel Nvoip em outra aba e ative o **WebPhone** (botão ⋮⋮⋮ laranja). Deixe a aba minimizada — ela precisa ficar aberta.',
        'No Hub, clica **📞 Ligar** no: drawer do cliente, perfil, pasta do processo OU cabeçalho da conversa WhatsApp (pra ligar pra quem mandou msg mas não é cliente ainda).',
        'Seu WebPhone toca na aba Nvoip → atende ali → Nvoip disca pro cliente e conecta vocês.',
        'Widget flutuante no canto inferior direito mostra: nome, telefone, status (⏳/🟢/✓) e timer. Botão 📵 encerra manualmente. Limite 5min por segurança.',
        'Ao desligar: gravação baixada pra `/files/ligacoes/`, transcrita via Groq Whisper, resumida por Claude Haiku em 3 linhas (assunto · próximos passos · observações).',
        'Histórico aparece em 3 lugares: **Comercial → Ligações** (geral), **aba 📞 Ligações do drawer** (por cliente), **Admin → Nvoip** (todas + filtros + CSV).',
    ),
    'atencao' => 'Se o WebPhone da Nvoip **não estiver aberto e registrado**, a ligação falha com `RECOVERY_ON_TIMER_EXPIRE` — a chamada é pro SEU ramal primeiro, você atende, aí liga pro cliente. Sem ramal atendendo, não acontece.',
    'dica' => '**Z-API NÃO faz ligações** (só WhatsApp permite msg pela API, não chamadas). Por isso a Nvoip é separada — linha telefônica independente. Custo: cada chamada consome saldo Nvoip (ver saldo no painel admin).',
    'missao' => 'Acesse **Comercial → Ligações**, leia o passo a passo, abra o webphone em outra aba e faça uma ligação teste pro próprio celular. Depois confira o histórico com gravação + resumo IA.',
),

'procuracao-regras' => array(
    'por_que' => 'Procuração errada = **nulidade processual**. Pode comprometer o caso inteiro. Essa é a regra mais crítica do escritório — não erre.',
    'passos' => array(
        '**ALIMENTOS** → procuração no nome da **CRIANÇA** (representada pelo pai/mãe responsável).',
        '**EXECUÇÃO DE ALIMENTOS** → procuração no nome da **CRIANÇA**.',
        '**REVISIONAL DE ALIMENTOS** → procuração no nome da **CRIANÇA**.',
        '**CONVIVÊNCIA** → procuração no nome do **PAI/MÃE CONTRATANTE**.',
        '**GUARDA** → procuração no nome do **PAI/MÃE CONTRATANTE**.',
        '**DIVÓRCIO** → procuração no nome do **PAI/MÃE CONTRATANTE** (ambos se consensual).',
        '**INVESTIGAÇÃO DE PATERNIDADE** → procuração no nome da **CRIANÇA**.',
    ),
    'atencao' => 'Errar o polo ativo invalida a procuração e pode gerar nulidade. Em caso de DÚVIDA, pergunta à Amanda ANTES de gerar. Nunca presuma.',
    'dica' => 'Na Fábrica de Petições, o campo "polo ativo" já vem **sugerido automaticamente** baseado no tipo de ação escolhido. Confira sempre.',
    'missao' => 'Acesse um caso de Alimentos e verifique se a procuração gerada está no nome da criança. Em um caso de Convivência, no nome do contratante.',
),

'documentos' => array(
    'por_que' => 'Geração automática de 15 templates estáticos — procuração, contrato de honorários, substabelecimento, hipossuficiência, citação por WhatsApp (CPC 246, V), etc. Tudo preenchido em segundos a partir dos dados do cadastro.',
    'passos' => array(
        'Menu **Documentos** ou botão na pasta do caso.',
        'Escolhe o tipo (procuração, contrato, ofício, etc.).',
        'Sistema pré-preenche com dados do cliente + caso (endereço, CPF, filhos).',
        'Ajusta campos específicos (valor honorários, vencimento, forma pagamento).',
        'Gera: download .doc com papel timbrado F&S 2026 (MHTML multipart — funciona no Word).',
        'Salvo em `document_history.params_json` → backup crítico pra recuperar valores.',
    ),
    'atencao' => 'Se você gerar um contrato e depois perder os valores no lead, use `document_history` pra recuperar — os params estão lá.',
    'dica' => 'Se precisar do mesmo documento com ajuste pequeno, use o histórico: clique em "Gerar igual" e só muda o que mudou.',
    'missao' => 'Gere uma procuração de teste pra um cliente fictício. Baixa o .doc e abre no Word pra ver o timbrado.',
),

'kanban-operacional' => array(
    'por_que' => 'Depois que o comercial fechou o contrato, o processo vira pra cá. 12 colunas acompanham: coleta de docs → elaboração → distribuição → andamento → conclusão.',
    'passos' => array(
        '**Aguardando Docs** (ponto de partida do operacional).',
        'Cliente manda documentos → marca como recebidos → **Em Elaboração** (Pasta Apta).',
        'Peças prontas → **Em Andamento** (trabalhando no caso).',
        'Se faltar algo → **Doc Faltante** (reflete no Pipeline automaticamente).',
        '**Aguardando Prazo** → fim de prazo externo.',
        '**Distribuído** → processo ajuizado (modal captura número CNJ + vara).',
        '**Parceria Previdenciário** → envia pro PREV.',
        '**Cancelado / Suspenso / Arquivado / Renunciamos** → fluxos terminais.',
    ),
    'atencao' => 'Mover pra **Doc Faltante** ou **Suspenso** reflete AUTOMATICAMENTE no Pipeline Comercial. Espelhamento bilateral.',
    'dica' => 'Antes de pedir número do processo ao cliente, o modal de Distribuição verifica se já está cadastrado — evita pedir 2x.',
    'missao' => 'Localize um caso em **Em Andamento** e verifique se há documentos pendentes. Se sim, marque um como "recebido" (se real) e observe o movimento.',
),

'kanban-prev' => array(
    'por_que' => 'Processos previdenciários têm fluxo próprio (13 colunas) — da análise INSS até implantação do benefício. São demorados e seguem regras específicas do INSS/CRPS/CAJ.',
    'passos' => array(
        'Aguardando Docs → Pasta Apta → Análise INSS → Perícia Médica → Recurso Administrativo → Recurso CRPS/CAJ → Ação Judicial → Sentença → Cumprimento/Precatório → Implantação.',
        'Tipos de benefício: INSS, BPC, LOAS, Aposentadoria, Auxílio-Doença, Pensão por Morte, Salário-Maternidade, Auxílio-Reclusão, Aposentadoria por Invalidez.',
        'Card PREV aparece no Kanban Operacional APENAS no mês em que foi enviado — depois só no Kanban PREV.',
        'Dashboard do PREV mostra: ativos, enviados este mês, distribuição por tipo de benefício.',
    ),
    'atencao' => 'A coluna **Parceria** é pra casos que vão pra advogado parceiro. Registra o parceiro e executor (FeS ou parceiro).',
    'dica' => 'Ao mover pra "Recurso", use os modelos de petição específicos da Fábrica — já estão com regras do CRPS.',
    'missao' => 'Abra o Dashboard do PREV e conte quantos casos ativos temos hoje por tipo de benefício.',
),

'fabrica-peticoes' => array(
    'por_que' => 'Geração de petições completas com IA — Claude Sonnet 4.6. 14 tipos de ação × 12 tipos de peça = 168 combinações. Com Visual Law, logo embutido, papel timbrado e prompt caching (economia de 90% no input).',
    'passos' => array(
        'Acessa a **Fábrica de Petições** direto da pasta do caso (botão).',
        'Escolhe: tipo de ação + tipo de peça (inicial, contestação, réplica, alegações finais, recurso, etc.).',
        'Preenche dados do caso (fatos, pedidos específicos, provas).',
        'Clica "Gerar" → Claude Sonnet produz petição completa em HTML.',
        'Visual Law: Calibri, cores do escritório, logo base64 (funciona no Word).',
        'Baixa como .doc → finaliza no Word se precisar.',
    ),
    'atencao' => 'Em **JEC/JEF**, NUNCA inclua seção de Gratuidade de Justiça em petição inicial. Só em Recurso Inominado. Regra inegociável.',
    'dica' => 'Em **alimentos**, o polo ativo é sempre a CRIANÇA (mesmo representada). Não esqueça pedidos obrigatórios: fixação + retroativos + ofício INSS.',
    'missao' => 'Abra a Fábrica e escolha uma ação qualquer. Sem gerar, explore o formulário e veja os campos que ela pede.',
),

'tarefas' => array(
    'por_que' => 'Kanban de Tarefas é onde o operacional organiza o trabalho diário. Cada peça a peticionar, cada ofício a escrever, cada prazo — vira uma tarefa. 4 colunas: A Fazer · Em Andamento · Aguardando · Concluído.',
    'passos' => array(
        '6 tipos: Peticionar · Juntar Documento · Prazo Processual · Ofício · Acordo/Conciliação · Outros.',
        'Crie tarefa dentro da pasta do caso OU direto no Kanban de Tarefas.',
        'Tipo **Prazo Processual** é ESPECIAL: cascade automático.',
        'Cascade cria: `case_tasks` + `prazos_processuais` + `agenda_eventos` (dia todo).',
        'Alerta automático 3 dias antes do prazo fatal (configurável).',
        'Concluir a tarefa → marca prazo como cumprido + evento agenda como realizado.',
        'Concluídas só aparecem no mês da conclusão (histórico colapsável).',
    ),
    'atencao' => 'Tarefas sem tipo (`tipo IS NULL`) são checklist de documentos — NÃO aparecem no Kanban de Tarefas, só no checklist da pasta.',
    'dica' => 'Subtipos de prazo disponíveis: Contestação, Réplica, Memoriais, Apelação, Embargos, Contrarrazões.',
    'missao' => 'Crie uma tarefa de teste com tipo "Peticionar" pra um processo qualquer. Observe onde ela aparece.',
),

'calculadora-prazos' => array(
    'por_que' => 'CPC art. 224 + suspensões TJRJ específicas. Calcular na mão dá erro — a calculadora já conhece feriados, suspensões e 47 comarcas do RJ. Dupla checagem: sempre calcule na calculadora antes de agendar.',
    'passos' => array(
        'Acessa pelo menu Prazos ou pelo botão "Calcular Prazo" na pasta do processo.',
        'Informa: data de disponibilização (D) e quantidade de dias úteis do prazo.',
        'Sistema calcula: D+1 (publicação) → primeiro dia útil seguinte = início da contagem.',
        'Conta só dias úteis, excluindo: fins de semana, feriados nacionais, suspensões TJRJ cadastradas.',
        'Resultado: data fatal + data de segurança (sugerida 3 dias antes).',
        'Ao salvar, cria tarefa de prazo + evento agenda (cascade).',
    ),
    'atencao' => 'Se a comarca tiver suspensão específica (ex: Barra Mansa em recesso), avise — atualizamos a tabela central.',
    'dica' => 'Use a data de segurança (3 dias antes do fatal) como seu compromisso. O fatal é pro juiz, não pra você.',
    'missao' => 'Calcule um prazo de 15 dias úteis a partir de hoje. Anote a data fatal e a data de segurança.',
),

'datajud' => array(
    'por_que' => 'Em vez de ficar consultando o PJe/TJRJ/tribunais manualmente, o DataJud (API pública do CNJ) puxa andamentos automaticamente. Você nem precisa fazer nada — o cron roda diário às 07h.',
    'passos' => array(
        'Cron diário às 07h sincroniza até 50 processos por execução (rate limit 1s).',
        'Na pasta do processo: botão **"🔄 Sincronizar DataJud"** pra puxar na hora.',
        'Andamentos novos entram em `case_andamentos` com badge 🔍 (importado do CNJ).',
        'Painel **DataJud Monitor** (admin): KPIs, feed de movimentações, filtros.',
        'Segredo de justiça: sistema tenta sempre — se não retornar, registra status e tenta de novo amanhã.',
    ),
    'atencao' => 'Se o processo não tem `case_number` cadastrado, não consegue sincronizar. Sempre cadastre o número CNJ completo (NNNNNNN-DD.AAAA.J.TR.OOOO).',
    'dica' => 'A máscara CNJ nos campos do sistema aplica formatação automaticamente — cole o número sem pontuação se preferir.',
    'missao' => 'Abra um caso que tenha número de processo cadastrado e clique em "Sincronizar DataJud". Observe os andamentos que vieram.',
),

'agenda' => array(
    'por_que' => 'Agenda unificada do escritório. 8 tipos de evento + Google Meet integrado + Balcão Virtual TJRJ + lembretes automáticos pra atendentes e clientes.',
    'passos' => array(
        'Tipos: Audiência · Reunião com Cliente · Prazo · Onboarding · Reunião Interna · Mediação/CEJUSC · Balcão Virtual · Ligação.',
        'Modalidade: Presencial / Online (gera Meet) / Não se aplica.',
        'Google Meet: botão "Gerar Meet" → cria link via Apps Script (conta reuniaofes@gmail.com).',
        'Botão "Enviar Convite" adiciona o evento na agenda pessoal dos participantes (Google).',
        'Lembretes automáticos (cron horário): 24h antes (notificação portal) · 2h antes (portal + WhatsApp cliente).',
        '**Balcão Virtual TJRJ**: só aceita agendamento entre 11h-17h.',
        'Compromisso vinculado a processo → gera andamento automático (com link de orientação se for audiência).',
    ),
    'atencao' => 'Audiência com cliente vira andamento automático VISÍVEL pro cliente (na Central VIP). Reunião interna NÃO — só equipe.',
    'dica' => 'O botão "Balcão Virtual" no topo da Agenda abre direto o link do TJRJ. Atalho útil.',
    'missao' => 'Agende uma reunião teste com tipo Reunião com Cliente + modalidade Online. Veja o Meet ser gerado.',
),

'sala-vip' => array(
    'por_que' => 'Portal do cliente — ele acompanha o processo, envia documentos, conversa com a equipe. Reduz ligações/WhatsApp pedindo "como está meu processo?" porque o cliente vê por si próprio.',
    'passos' => array(
        'Cliente acessa: `ferreiraesa.com.br/salavip/`',
        'Login: CPF + senha. Senha é criada pelo cliente após ativação via link 72h.',
        'Pra gerar o link: no WhatsApp, botão 🔑 Portal → cria/renova `salavip_usuarios` + manda msg pronta.',
        'Dashboard do cliente: 4 KPIs, andamentos recentes, compromissos, mensagens.',
        'Cliente vê: processos + timeline + docs pendentes (solicitados pelo escritório) + GED (docs que o escritório compartilhou).',
        'Cliente manda mensagem pra equipe → vira `ticket` no Helpdesk (aba Clientes).',
    ),
    'atencao' => 'Os andamentos marcados como "interno" (visivel_cliente=0) NÃO aparecem pro cliente. Sempre confira antes de gravar se deve ser público ou não.',
    'dica' => 'A Central VIP tem modo claro/escuro e é mobile-first — cliente pode acessar pelo celular sem problema.',
    'missao' => 'Acesse a Central VIP com um usuário de teste (se tiver) ou veja a tela de login e explore o FAQ.',
),

'helpdesk' => array(
    'por_que' => 'Centraliza comunicação interna da equipe. Dúvidas, bugs do sistema, solicitações de material, solicitações de gestão. Em vez de WhatsApp avulso, abre um chamado — fica documentado.',
    'passos' => array(
        'Menu **Helpdesk** → botão **+ Novo Chamado**.',
        'Preenche: título, categoria, prioridade, descrição, vínculos (cliente/processo opcional).',
        'Responsável atribuído é notificado (sino + e-mail Brevo).',
        'Thread de mensagens: cada resposta fica salva. **@menção** abre autocomplete + notifica o mencionado.',
        'Status: Aberto → Em Atendimento → Aguardando → Resolvido → Cancelado.',
        'Se resolvido/cancelado + vinculado a processo: registra andamento no processo (em segredo).',
    ),
    'atencao' => 'Chamados sobre clientes (aba separada) vêm da Central VIP — respondidos por CX/Operacional. SLA por categoria.',
    'dica' => 'Use @menção pra chamar alguém específico sem precisar mudar o responsável do chamado.',
    'missao' => 'Abra um chamado de teste pedindo algo simples (ex: "Preciso aprender tal coisa"). Atribua a você mesma.',
),

'wiki' => array(
    'por_que' => 'Base de conhecimento do escritório. Antes de perguntar pra alguém, consulta aqui — a resposta provavelmente já foi escrita por quem já passou pela mesma dúvida.',
    'passos' => array(
        'Menu **Wiki** → lista de artigos organizados por categoria.',
        'Busca em tempo real (Ctrl+F dentro da Wiki).',
        'Artigos podem ter: texto, imagens, tabelas, código.',
        'Admin/Gestão podem criar/editar artigos.',
        'Tags e busca por texto.',
    ),
    'atencao' => 'Antes de abrir chamado no Helpdesk, pesquise na Wiki primeiro. Muita dúvida já foi respondida lá.',
    'dica' => 'Quando aprender algo novo e útil, peça pra adicionarem à Wiki. Conhecimento documentado escala.',
    'missao' => 'Abra a Wiki e leia 1 artigo qualquer que te interesse. Compartilhe uma dúvida com a Amanda pra virar artigo.',
),

'financeiro' => array(
    'por_que' => 'Controle total das cobranças do escritório via Asaas. KPIs de recebido/pendente/vencido, cobranças por cliente, Proposta de Acordo, alertas de token Asaas.',
    'passos' => array(
        'Menu **Financeiro** (restrito a Amanda, Rodrigo, Luiz — whitelist).',
        'Visão geral: seletor de mês, gráfico previsto x recebido (6 meses), inadimplentes.',
        '**"📋 Todas as cobranças"** — tabela completa com filtros (data, status, forma, busca por nome/CPF/nº Asaas/processo).',
        'Integração Asaas produção: `api.asaas.com/v3` (o `/api/v3` dá 404).',
        'Webhook: `ferreiraesa.com.br/conecta/modules/financeiro/webhook.php`.',
        'Ficha do Cliente (financeiro): histórico completo Asaas + proposta de acordo com desconto por faixa de atraso.',
        'Alerta Asaas: token expira em 90d. Banner aparece faltando ≤15d (só pra Amanda).',
    ),
    'atencao' => 'Importação em lotes: use `importar_asaas.php` com `offset+max_pages=5-10` pra não dar timeout no TurboCloud.',
    'dica' => 'Vinculação cobrança ↔ processo: use `asaas_cobrancas.case_id` pra filtrar cobranças de um processo específico (evita misturar múltiplos processos do mesmo cliente).',
    'missao' => 'Abra o Financeiro → "Todas as cobranças" → filtra por "Vencidas" → veja quantas estão atrasadas.',
),

'cobranca-honorarios' => array(
    'por_que' => 'Gestão da inadimplência em 4 etapas progressivas. Em vez de lembrar de cobrar cada cliente, o sistema detecta automaticamente e abre o caso aqui.',
    'passos' => array(
        'Cron diário detecta cobranças Asaas vencidas há 90+ dias → cria entrada automática.',
        'Kanban 5 colunas: Em dia → Atrasado → Notificado 1 → Notificado 2 → Extrajudicial → Judicial.',
        'Notificação 1 = WhatsApp amigável.',
        'Notificação 2 = WhatsApp formal.',
        'Extrajudicial = carta de cobrança.',
        'Judicial = escritório toca cobrança na Justiça.',
        'Agrupamento por cliente (1 card = todas as parcelas do devedor), com drag-and-drop, proposta de acordo sincronizada, notificação em massa via WhatsApp.',
    ),
    'atencao' => 'Cláusula 5.1: cálculo de atualização = valor nominal + multa 20% + juros 1%/mês pro-rata + correção monetária. Valor da Proposta bate sempre com o Kanban.',
    'dica' => 'Desfecho do processo: se foi "extinto sem julgamento" ou "desistência", aviso vermelho bloqueia cobrança (CPC 485/487).',
    'missao' => 'Abra o Kanban Cobrança e observe quantos clientes estão em cada coluna. Qual tem o maior acumulado?',
),

'newsletter' => array(
    'por_que' => 'Campanhas de e-mail pra clientes e contatos — via Brevo. Tracking de abertura, clique, descadastro. 9 templates prontos (notícias, promoções, avisos legais).',
    'passos' => array(
        'Menu **Newsletter**.',
        'Escolhe template (9 opções), segmentação (todos, aniversariantes, por categoria).',
        'Sobe imagem de destaque (se tiver), escreve conteúdo.',
        'Preview → Enviar.',
        'Brevo dispara via API. Webhook recebe eventos (opened, clicked, unsubscribed).',
        'LGPD: rodapé com link de descadastro obrigatório.',
    ),
    'atencao' => 'Segmente sempre. Mandar promoção pra cliente em luto não pega bem — use tags.',
    'dica' => 'Aniversariante tem template próprio com cupom/benefício — ideal pra manter relacionamento.',
    'missao' => 'Abra a Newsletter e veja os 9 templates disponíveis. Qual você usaria pro Dia das Mães?',
),

'ranking' => array(
    'por_que' => 'Gamificação incentiva resultado. 2 rankings separados pra ser justo (Comercial ≠ Operacional). 11 eventos dão pontos, 8 níveis, efeitos visuais (confetti, moedas).',
    'passos' => array(
        'Menu **Ranking/Gamificação**.',
        '4 abas: Mensal · Carreira · Histórico · Premiação.',
        'Eventos que dão ponto: contrato assinado (50pts), petição distribuída (40pts), cliente aprovado INSS (30pts), etc.',
        'Ranking separado: Comercial (fechamentos) vs Operacional (entregas jurídicas).',
        'Efeitos: Web Audio API (moedas, fanfarra), canvas (confetti, fireworks).',
        'Polling 10s pra atualizações em tempo real.',
    ),
    'atencao' => 'Pontuação retroativa: se um evento antigo foi cadastrado manualmente, conta também. Não fique marcando várias vezes.',
    'dica' => 'Use o ranking como motivação — não como competição tóxica. Reconheça o esforço dos colegas também.',
    'missao' => 'Abra o Ranking e veja em que posição você está este mês. Qual evento daria pra você mais pontos?',
),

'links-tribunais' => array(
    'por_que' => 'Portal de Links centraliza TODOS os acessos do escritório: tribunais, PJe, e-SAJ, bancos, correspondente, ferramentas. Com credenciais criptografadas (AES-256).',
    'passos' => array(
        'Menu **Portal** ou topbar.',
        'Categorias: Tribunais · Bancos · Governamentais · Ferramentas · Outros.',
        'Cada link: ícone, URL, user, senha (criptografada), dica de uso.',
        'Audience: interno (só equipe) / cliente (se algum link público).',
        'Favoritos no topo + busca rápida.',
        'Admin gerencia. Colaborador só consulta.',
    ),
    'atencao' => 'Senhas são AES-256. Se o `ENCRYPT_KEY` do config.php mudar, perde acesso — sempre faça backup antes.',
    'dica' => 'Marque os 3-5 tribunais que você acessa mais como favoritos — ficam no topo da lista.',
    'missao' => 'Abra o Portal de Links e confira se o PJe TJRJ está lá. Se não, avise a Amanda pra cadastrar.',
),

'aniversarios' => array(
    'por_que' => 'Pequeno gesto, grande impacto. Parabenizar cliente no aniversário gera retenção e fortalece relacionamento. Automatizado — ninguém esquece.',
    'passos' => array(
        'Cron diário às 09h roda `zapi_aniversarios.php`.',
        'Detecta clientes com `birth_date` = hoje.',
        'Envia WhatsApp pelo DDD 24 (CX).',
        '5 templates rotacionados (alterna pra não ficar repetitivo).',
        'Tabela `birthday_greetings` previne envio duplo (1×/cliente/ano).',
        'Rate limit interno: 1 msg/segundo.',
    ),
    'atencao' => 'Se o cliente não quiser receber, marcar no cadastro + pedir pra você. Respeitem LGPD.',
    'dica' => 'Na agenda do dia 1º de cada mês, aparece a lista de aniversariantes — confira antecipadamente.',
    'missao' => 'Abra o módulo Aniversários e veja quem faz aniversário esta semana.',
),

);
