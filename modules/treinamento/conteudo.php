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
    'telas_html' => <<<'HTML'
<figure class="tm-screen">
  <div class="tm-screen-chrome">
    <span class="tm-screen-dots"><span></span><span></span><span></span></span>
    <span class="tm-screen-url">ferreiraesa.com.br/conecta — menu lateral</span>
  </div>
  <div class="tm-screen-body">
    <div class="tm-mock-sidebar">
      <div class="sec">Principal</div>
      <div class="item"><span class="icon">🌅</span>Painel do Dia</div>
      <div class="item"><span class="icon">📊</span>Dashboard</div>
      <div class="sec">💬 WhatsApp</div>
      <div class="item"><span class="icon">💬</span>Comercial (21)<span class="badge" style="background:#dc2626">4</span></div>
      <div class="item"><span class="icon">💬</span>CX / Operac. (24)<span class="badge" style="background:#dc2626">12</span></div>
      <div class="sec">💼 Comercial</div>
      <div class="item"><span class="icon">📈</span>Kanban Comercial</div>
      <div class="sec">⚙️ Operacional</div>
      <div class="item"><span class="icon">📋</span>Kanban Operacional</div>
      <div class="item"><span class="icon">⚖️</span>Processos</div>
      <div class="item"><span class="icon">📅</span>Agenda</div>
    </div>
  </div>
  <p class="tm-screen-caption">Tela — Menu lateral com todos os módulos agrupados. Cada seção reúne ferramentas do mesmo assunto. Bolinhas vermelhas mostram itens que exigem atenção agora (mensagens não lidas, prazos vencendo).</p>
</figure>
HTML,
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
    'telas_html' => <<<'HTML'
<figure class="tm-screen">
  <div class="tm-screen-chrome">
    <span class="tm-screen-dots"><span></span><span></span><span></span></span>
    <span class="tm-screen-url">/conecta/modules/painel/</span>
  </div>
  <div class="tm-screen-body">
    <div class="tm-mock-hdr">
      <div>
        <h4>🌅 Painel do Dia</h4>
        <div class="s">Bom dia, Amanda! Hoje é 07/07/2026 — sexta-feira.</div>
      </div>
    </div>
    <div class="tm-mock-painel-sec">
      <h5>📅 Audiências e reuniões</h5>
      <div class="tm-mock-linha">
        <span class="hora">10:30</span>
        <span class="titulo">Audiência de conciliação — Maria da Silva x Alimentos</span>
        <span class="cli">2ª Vara Família VR</span>
      </div>
      <div class="tm-mock-linha">
        <span class="hora">14:00</span>
        <span class="titulo">Reunião cliente — João Pereira (Divórcio)</span>
        <span class="cli">Google Meet</span>
      </div>
    </div>
    <div class="tm-mock-painel-sec">
      <h5>⚠️ Prazos processuais</h5>
      <div class="tm-mock-linha urg">
        <span class="hora">HOJE</span>
        <span class="titulo">Contestação — Gildson Faria x Alimentos (5d)</span>
        <span class="badge-urg">Urgente</span>
      </div>
      <div class="tm-mock-linha">
        <span class="hora">09/07</span>
        <span class="titulo">Réplica — Sara Silva x Destituição</span>
        <span class="cli">3d</span>
      </div>
    </div>
    <div class="tm-mock-painel-sec">
      <h5>✅ Tarefas do dia</h5>
      <div class="tm-mock-linha">
        <span class="titulo">Ligar pra cliente Ana confirmando audiência de segunda</span>
      </div>
    </div>
  </div>
  <p class="tm-screen-caption">Tela — Sua sexta-feira em 1 tela: audiências, prazos (vermelho = urgente hoje!) e tarefas. Clica em qualquer item pra abrir a pasta.</p>
</figure>
HTML,
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
    'telas_html' => <<<'HTML'
<figure class="tm-screen">
  <div class="tm-screen-chrome">
    <span class="tm-screen-dots"><span></span><span></span><span></span></span>
    <span class="tm-screen-url">Drawer aberto sobre qualquer tela</span>
  </div>
  <div class="tm-screen-body">
    <div class="tm-mock-drawer">
      <div class="tm-mock-drawer-top">
        <h4>Maria da Silva Santos</h4>
        <div class="sub">CPF 139.***.***-05 · (24) 99289-9663</div>
      </div>
      <div class="tm-mock-drawer-abas">
        <span class="a">📇 Geral</span>
        <span class="a">💼 Comercial</span>
        <span class="a">⚙️ Operacional</span>
        <span class="a on">📄 Docs</span>
        <span class="a">💰 Financeiro</span>
        <span class="a">📆 Agenda</span>
        <span class="a">🕘 Histórico</span>
      </div>
      <div class="tm-mock-drawer-cont">
        <div style="font-size:.72rem;color:#8b7a68;text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin-bottom:.5rem;">Pasta: Maria x Alimentos</div>
        <div class="tm-mock-doc-item done"><span class="chk"></span>RG</div>
        <div class="tm-mock-doc-item done"><span class="chk"></span>CPF</div>
        <div class="tm-mock-doc-item done"><span class="chk"></span>Comprovante de residência</div>
        <div class="tm-mock-doc-item"><span class="chk"></span>Certidão de nascimento do menor</div>
        <div class="tm-mock-doc-item"><span class="chk"></span>3 últimos contracheques do genitor</div>
      </div>
    </div>
  </div>
  <p class="tm-screen-caption">Tela — Drawer aberto na aba Docs. Ao lado, as 6 outras abas (Geral, Comercial, Operacional, Financeiro, Agenda, Histórico). Cada aba mostra dados da PASTA selecionada, não do cliente inteiro.</p>
</figure>
HTML,
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
    'telas_html' => <<<'HTML'
<figure class="tm-screen">
  <div class="tm-screen-chrome">
    <span class="tm-screen-dots"><span></span><span></span><span></span></span>
    <span class="tm-screen-url">/conecta/modules/pipeline/</span>
  </div>
  <div class="tm-screen-body">
    <div class="tm-mock-kanban">
      <div class="tm-mock-kb-col">
        <h6>📝 Cadastro Preenchido <span class="cnt">3</span></h6>
        <div class="tm-mock-kb-card">
          <div class="nome">Maria da Silva Santos</div>
          <div class="sub">Alimentos · Site</div>
        </div>
        <div class="tm-mock-kb-card">
          <div class="nome">João Pereira Lima</div>
          <div class="sub">Divórcio · Site</div>
        </div>
      </div>
      <div class="tm-mock-kb-col">
        <h6>✍️ Elaboração Docs <span class="cnt">2</span></h6>
        <div class="tm-mock-kb-card">
          <div class="nome">Ana Beatriz</div>
          <div class="sub">Guarda · R$ 3.500</div>
          <div class="tag">Procuração gerada</div>
        </div>
      </div>
      <div class="tm-mock-kb-col">
        <h6>🔗 Link Enviado <span class="cnt">1</span></h6>
        <div class="tm-mock-kb-card">
          <div class="nome">Vagner Nunes</div>
          <div class="sub">Invest. Paternidade</div>
        </div>
      </div>
      <div class="tm-mock-kb-col destaque">
        <h6>🎯 Contrato Assinado <span class="cnt">4</span></h6>
        <div class="tm-mock-kb-card destaque">
          <div class="nome">Thaís Rocha</div>
          <div class="sub">Assinou hoje ✍️</div>
          <div class="tag">→ Pasta criada</div>
        </div>
      </div>
    </div>
  </div>
  <p class="tm-screen-caption">Tela — Kanban Comercial. Arrasta o card entre colunas conforme avança. Ao chegar em "Contrato Assinado" (coluna destacada), o sistema AUTOMATICAMENTE cria pasta no Drive, abre caso no Operacional e envia msg de boas-vindas.</p>
</figure>
HTML,
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
    'telas_html' => <<<'HTML'
<figure class="tm-screen">
  <div class="tm-screen-chrome">
    <span class="tm-screen-dots"><span></span><span></span><span></span></span>
    <span class="tm-screen-url">/conecta/modules/whatsapp/?canal=21</span>
  </div>
  <div class="tm-screen-body">
    <div class="tm-mock-wa">
      <div class="tm-mock-wa-lista">
        <div class="tm-mock-wa-conv on">
          <div class="nome">Jessica Amaral <span class="lock">🔒</span></div>
          <div class="prev">Muito obrigada, doutora!</div>
        </div>
        <div class="tm-mock-wa-conv">
          <div class="nome">João Ferreira <span class="badge-nl">3</span></div>
          <div class="prev">Oi, gostaria de saber sobre…</div>
        </div>
        <div class="tm-mock-wa-conv">
          <div class="nome">Maria Cabral 🤖</div>
          <div class="prev">Bot atendendo…</div>
        </div>
      </div>
      <div class="tm-mock-wa-chat">
        <div class="tm-mock-wa-chat-hdr">
          <span class="nome">Jessica Amaral</span>
          <span class="timer">🔒 4h12min</span>
        </div>
        <div class="tm-mock-wa-body">
          <div class="tm-mock-bolha recv">Oi! Recebi o link do contrato, vou assinar hoje ainda.<span class="h">14:22</span></div>
          <div class="tm-mock-bolha env">Perfeito! Assim que assinar me avisa que já abro a pasta do processo. 💪<span class="h">14:23</span></div>
          <div class="tm-mock-bolha recv">Muito obrigada, doutora!<span class="h">14:25</span></div>
        </div>
      </div>
    </div>
  </div>
  <p class="tm-screen-caption">Tela — Canal 21 (Comercial). Lista de conversas à esquerda, chat aberto à direita. Cronômetro 🔒 4h12min mostra quanto falta pra trava liberar. Bot 🤖 aparece nas conversas onde a IA ainda está respondendo.</p>
</figure>
HTML,
    'passos' => array(
        'Entra em **WhatsApp Comercial (21)** ou **WhatsApp CX (24)** na sidebar.',
        'Filtros: Todos · Aguardando · Em atend. · Não lidas · Resolv. · 🔓 AT Desbloq. · 🏷 Etiqueta · 👥 Atendente.',
        '**DDD 21 tem bot IA** (Claude Haiku) que responde até transferir pra humano.',
        '**Assumir** trava a conversa pra você (só DDD 21). DDD 24 é colaborativo.',
        '**Trava libera em 2 situações:** (a) cliente é última msg há >**8h úteis** (seg-sex, 9h-18h); (b) equipe é última msg há >36h corridas (follow-up).',
        'Cronômetro no topo mostra **quanto falta pra liberar** — atualiza ao vivo.',
        '**Etiqueta 🔓 AT DESBLOQUEADO** aplicada AUTOMATICAMENTE quando a trava libera — botão atalho filtra.',
        'Envia: texto, imagem, documento, áudio (com preview antes de enviar), figurinha, reação.',
        'Botões: 📋 Chamado · 🔑 Portal · 🎯 Delegar (só Amanda/Luiz) · 🔀 Mesclar duplicatas.',
    ),
    'atencao' => '**NUNCA mesclar conversas entre canais 21 e 24** — são números físicos diferentes do escritório, juntar quebra o fluxo de resposta. Mesclar só vale DENTRO do mesmo canal (duplicatas @lid).',
    'dica' => 'Clicou **⏹** no áudio? Ele PARA (não envia). Você escuta o preview e clica **➤ Enviar** pra mandar. Pra fluxo rápido tipo WhatsApp normal, clica **➤ Enviar durante a gravação** que ele para+envia direto.',
    'missao' => 'Abra uma conversa travada (🔒 no topo), observe o cronômetro de liberação e filtre por **🔓 AT Desbloq.** pra ver leads que perderam atendimento.',
),

'procuracao-regras' => array(
    'por_que' => 'Procuração errada = **nulidade processual**. Pode comprometer o caso inteiro. Essa é a regra mais crítica do escritório — não erre.',
    'telas_html' => <<<'HTML'
<figure class="tm-screen">
  <div class="tm-screen-chrome">
    <span class="tm-screen-dots"><span></span><span></span><span></span></span>
    <span class="tm-screen-url">Quadro de referência — quem assina o quê</span>
  </div>
  <div class="tm-screen-body">
    <div class="tm-mock-procuracao">
      <div class="tm-mock-proc-card crianca">
        <h5>👶 Nome da CRIANÇA</h5>
        <ul>
          <li>Alimentos</li>
          <li>Execução de Alimentos</li>
          <li>Revisional de Alimentos</li>
          <li>Investigação de Paternidade</li>
        </ul>
      </div>
      <div class="tm-mock-proc-card contratante">
        <h5>👨‍👩‍👦 Nome do PAI/MÃE contratante</h5>
        <ul>
          <li>Convivência</li>
          <li>Guarda</li>
          <li>Divórcio (ambos se consensual)</li>
        </ul>
      </div>
    </div>
  </div>
  <p class="tm-screen-caption">Regra — Ação em nome de QUEM? Verde é criança (representada pelo responsável). Azul é o adulto contratante. Errou = nulidade processual. Em dúvida, sempre pergunta ANTES de gerar.</p>
</figure>
HTML,
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
    'telas_html' => <<<'HTML'
<figure class="tm-screen">
  <div class="tm-screen-chrome">
    <span class="tm-screen-dots"><span></span><span></span><span></span></span>
    <span class="tm-screen-url">/conecta/modules/documentos/</span>
  </div>
  <div class="tm-screen-body">
    <div class="tm-mock-docs-grid">
      <div class="tm-mock-doc-card hi"><div class="ico">📝</div><div class="n">Procuração</div></div>
      <div class="tm-mock-doc-card"><div class="ico">💼</div><div class="n">Contrato Honor.</div></div>
      <div class="tm-mock-doc-card"><div class="ico">↔️</div><div class="n">Substabelec.</div></div>
      <div class="tm-mock-doc-card"><div class="ico">📉</div><div class="n">Hipossuficiência</div></div>
      <div class="tm-mock-doc-card"><div class="ico">📮</div><div class="n">Citação WhatsApp</div></div>
      <div class="tm-mock-doc-card"><div class="ico">✉️</div><div class="n">Ofício</div></div>
      <div class="tm-mock-doc-card"><div class="ico">📄</div><div class="n">Declaração</div></div>
      <div class="tm-mock-doc-card"><div class="ico">📋</div><div class="n">Termo Renúncia</div></div>
    </div>
  </div>
  <p class="tm-screen-caption">Tela — 15 templates prontos. Escolhe o tipo, o sistema pré-preenche com dados do cliente + caso. Baixa .doc com timbrado F&S 2026. Backup em document_history pra sempre recuperar valores usados.</p>
</figure>
HTML,
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
    'telas_html' => <<<'HTML'
<figure class="tm-screen">
  <div class="tm-screen-chrome">
    <span class="tm-screen-dots"><span></span><span></span><span></span></span>
    <span class="tm-screen-url">/conecta/modules/operacional/</span>
  </div>
  <div class="tm-screen-body">
    <div class="tm-mock-kanban">
      <div class="tm-mock-kb-col">
        <h6>📥 Aguard. Docs <span class="cnt">6</span></h6>
        <div class="tm-mock-kb-card">
          <div class="nome">Maria x Alimentos</div>
          <div class="sub">2 docs faltando</div>
          <div class="tag" style="background:#fee2e2;color:#991b1b;">Cliente inerte</div>
        </div>
      </div>
      <div class="tm-mock-kb-col destaque">
        <h6>✍️ Pasta Apta <span class="cnt">3</span></h6>
        <div class="tm-mock-kb-card destaque">
          <div class="nome">João x Divórcio</div>
          <div class="sub">Redigindo petição</div>
        </div>
      </div>
      <div class="tm-mock-kb-col">
        <h6>⏳ Aguard. Distribuição <span class="cnt">2</span></h6>
        <div class="tm-mock-kb-card">
          <div class="nome">Ana x Guarda</div>
          <div class="sub">Redigida — protocolar</div>
        </div>
      </div>
      <div class="tm-mock-kb-col">
        <h6>⚖️ Em Andamento <span class="cnt">47</span></h6>
        <div class="tm-mock-kb-card">
          <div class="nome">Vagner x Invest. Paternidade</div>
          <div class="sub">CNJ 0817952-56...</div>
          <div class="tag" style="background:#d1fae5;color:#065f46;">🐻 Jorjão tocou</div>
        </div>
      </div>
    </div>
  </div>
  <p class="tm-screen-caption">Tela — Kanban Operacional. Card se movimenta da coleta até o processo tramitando. Quando ganha número CNJ e vira "Em Andamento", o Jorjão toca sino automaticamente no grupo 🐻.</p>
</figure>
HTML,
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
    'telas_html' => <<<'HTML'
<figure class="tm-screen">
  <div class="tm-screen-chrome">
    <span class="tm-screen-dots"><span></span><span></span><span></span></span>
    <span class="tm-screen-url">/conecta/modules/peticoes/</span>
  </div>
  <div class="tm-screen-body">
    <div class="tm-mock-ia">
      <div class="tm-mock-ia-bolha user">
        <div class="who">Você</div>
        Gerar petição inicial de <strong>alimentos</strong> pra Maria da Silva (autora, mãe) x José Pereira (réu, pai). Filho: Lucas, 8 anos. Renda comprovada do réu: R$ 4.500. Pedido: 30% do salário.
      </div>
      <div class="tm-mock-ia-bolha bot">
        <div class="who">🤖 Claude Sonnet</div>
        <strong>EXCELENTÍSSIMO SENHOR DOUTOR JUIZ DE DIREITO DA VARA DE FAMÍLIA…</strong><br><br>
        LUCAS DA SILVA PEREIRA, brasileiro, menor impúbere, representado por sua genitora <strong>MARIA DA SILVA</strong>, vem à presença de V. Exa. propor a presente <strong>AÇÃO DE ALIMENTOS</strong> em face de <strong>JOSÉ PEREIRA</strong>…
        <br><br>
        <em>[texto completo — 6 páginas geradas em 34 segundos]</em>
      </div>
    </div>
  </div>
  <p class="tm-screen-caption">Tela — Você descreve o caso em linguagem natural, o Claude Sonnet gera petição completa em segundos. Sempre revise antes de protocolar!</p>
</figure>
HTML,
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
    'telas_html' => <<<'HTML'
<figure class="tm-screen">
  <div class="tm-screen-chrome">
    <span class="tm-screen-dots"><span></span><span></span><span></span></span>
    <span class="tm-screen-url">/conecta/modules/tarefas/</span>
  </div>
  <div class="tm-screen-body">
    <div class="tm-mock-tarefas">
      <div class="tm-mock-tarefa hi">
        <span class="pri alta">ALTA</span>
        <span class="txt">⏰ PRAZO: Contestação Gildson Faria (fatal 09/07)</span>
        <span class="quando">Hoje</span>
      </div>
      <div class="tm-mock-tarefa">
        <span class="pri media">MÉD</span>
        <span class="txt">Ligar pra cliente Ana confirmando audiência</span>
        <span class="quando">Amanhã</span>
      </div>
      <div class="tm-mock-tarefa">
        <span class="pri media">MÉD</span>
        <span class="txt">Revisar procuração Vagner Nunes</span>
        <span class="quando">Amanhã</span>
      </div>
      <div class="tm-mock-tarefa">
        <span class="pri baixa">BAIXA</span>
        <span class="txt">Atualizar planilha de honorários</span>
        <span class="quando">Sexta</span>
      </div>
    </div>
  </div>
  <p class="tm-screen-caption">Tela — Tarefas ordenadas por prioridade. Prazos processuais viram tarefa automaticamente com prioridade ALTA vermelha. Concluir aqui destrava o prazo (e o Jorjão toca sino no grupo se ativado 🐻).</p>
</figure>
HTML,
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
    'telas_html' => <<<'HTML'
<figure class="tm-screen">
  <div class="tm-screen-chrome">
    <span class="tm-screen-dots"><span></span><span></span><span></span></span>
    <span class="tm-screen-url">/conecta/modules/operacional/prazos_calc.php</span>
  </div>
  <div class="tm-screen-body">
    <div class="tm-mock-calc">
      <div class="tm-mock-calc-row">
        <div>
          <label>Data da intimação</label>
          <div class="campo">06/07/2026</div>
        </div>
        <div>
          <label>Tipo de prazo</label>
          <div class="campo">Dias úteis</div>
        </div>
      </div>
      <div class="tm-mock-calc-row">
        <div>
          <label>Quantidade</label>
          <div class="campo">15</div>
        </div>
        <div>
          <label>Tribunal</label>
          <div class="campo">TJRJ</div>
        </div>
      </div>
      <div style="margin-bottom:.55rem;">
        <label style="font-size:.66rem;text-transform:uppercase;letter-spacing:.06em;font-weight:700;color:#64615a;display:block;margin-bottom:.15rem;">Ação</label>
        <div class="campo hi">Contestação — 15 dias úteis</div>
      </div>
      <div class="tm-mock-calc-result">
        <div>Prazo fatal:</div>
        <b>28/07/2026 (terça-feira)</b>
        <span class="aviso">⚠ Considerou suspensão 20/12/2026 – 20/01/2027 (recesso) e 6 feriados TJRJ no período.</span>
      </div>
    </div>
  </div>
  <p class="tm-screen-caption">Tela — Calculadora de prazos. Escolhe data + tipo + tribunal, ela calcula sozinha considerando dias úteis, feriados TJRJ e recesso judiciário. Sempre confere antes de anotar!</p>
</figure>
HTML,
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

'distribuir-peticao-inicial' => array(
    'telas_html' => <<<'HTML'
<figure class="tm-screen">
  <div class="tm-screen-chrome">
    <span class="tm-screen-dots"><span></span><span></span><span></span></span>
    <span class="tm-screen-url">Do PJe/PROJUDI/e-SAJ de volta pro Hub</span>
  </div>
  <div class="tm-screen-body">
    <div style="display:flex;flex-direction:column;gap:.7rem;">
      <div style="background:#fef3c7;border-left:3px solid #d97706;padding:.7rem 1rem;border-radius:0 8px 8px 0;font-size:.82rem;">
        <strong>1.</strong> Protocola no <strong>PJe</strong> (ou tribunal apropriado)
      </div>
      <div style="background:#fef3c7;border-left:3px solid #d97706;padding:.7rem 1rem;border-radius:0 8px 8px 0;font-size:.82rem;">
        <strong>2.</strong> Copia o <strong>número CNJ</strong> gerado
      </div>
      <div style="background:#f0f9ff;border-left:3px solid #0284c7;padding:.7rem 1rem;border-radius:0 8px 8px 0;font-size:.82rem;">
        <strong>3.</strong> Volta pro Hub → pasta do processo → cola <strong>número CNJ</strong> no campo
      </div>
      <div style="background:#ecfdf5;border-left:3px solid #059669;padding:.7rem 1rem;border-radius:0 8px 8px 0;font-size:.82rem;">
        <strong>4.</strong> Sistema faz automaticamente:<br>
        &nbsp;&nbsp;• Move card pra "Em Andamento"<br>
        &nbsp;&nbsp;• Ativa DataJud pra puxar andamentos<br>
        &nbsp;&nbsp;• 🐻 Jorjão toca sino no grupo comemorando
      </div>
    </div>
  </div>
  <p class="tm-screen-caption">Fluxo — Protocolar no PJe é só metade do trabalho. A outra metade é registrar o CNJ no Hub pra ativar toda a cadeia de automação (sync, Jorjão, agenda de prazos).</p>
</figure>
HTML,
    'por_que' => 'Quando a petição inicial está pronta e protocolada no tribunal, o caso precisa subir pra **Processo Distribuído** no Kanban Operacional. É essa movimentação que: (1) registra o número CNJ no sistema, (2) gera andamento automático no histórico do processo, (3) avisa o cliente pelo WhatsApp + Sala VIP que o processo foi ajuizado.',
    'passos' => array(
        'Abra **Kanban Operacional** na sidebar.',
        'Localize o card do caso — geralmente está em **Em Elaboração** ou **Em Andamento**.',
        '**Arraste** o card pra coluna **🏛️ Processo Distribuído** (verde escuro, à direita).',
        'Modal **"Dados do Processo Distribuído"** abre automaticamente.',
        'Toggle **🏛️ Judicial** já vem selecionado — use **📋 Extrajudicial** se for medida administrativa (divórcio em cartório, inventário extrajudicial, etc).',
        'Preencha **Número do processo** — formato CNJ `0000000-00.0000.0.00.0000` (o sistema mascara automaticamente conforme você digita).',
        'Preencha **Vara/Juízo** — ex: "1ª Vara de Família de Resende".',
        'Preencha **Tipo de demanda**, **Data da distribuição** (vem com hoje), **Comarca** (autocomplete RJ), **UF** (RJ default), **Regional** (se aplicável), **Sistema** (PJe / PROJUDI / e-SAJ / EPROC).',
        'Preencha **Parte(s) contrária(s)** — nome do réu/autor adverso.',
        '**Valor da causa** é opcional (formato R$).',
        'Salva. Sistema registra `case_number`, `court`, `comarca` no caso, cria andamento "Processo distribuído" no histórico, e dispara notificação pro cliente.',
    ),
    'atencao' => 'Se o caso já tinha número cadastrado (ex: pelo Email Monitor → aba Pendentes), o modal verifica e pré-preenche — evita pedir 2x. **Cuidado com a regra de visibilidade:** o card "Distribuído" só fica visível no Kanban Operacional durante o **MÊS** da distribuição. Depois disso some do Kanban (mas continua na lista de Processos com status `distribuido`).',
    'dica' => 'Se o processo já estava distribuído mas você só ficou sabendo agora, pode arrastar o card direto de qualquer coluna pra **Processo Distribuído** (sem regra de bloqueio). Use a data da distribuição real, não a de hoje.',
    'missao' => 'Pegue um caso em "Em Elaboração" ou "Em Andamento" que JÁ foi distribuído mas ainda não está marcado no Hub. Mova o card pra **Processo Distribuído**, preencha os dados e salve. Depois abre a pasta do caso e confirma: (1) número CNJ aparece no cabeçalho, (2) há um andamento "Processo distribuído" na timeline, (3) status mudou no Kanban.',
),

'vincular-incidentais-recursos' => array(
    'por_que' => 'Processos não andam isolados. Carta precatória, execução, embargos, recursos — cada um tem **vida própria** mas pertence a uma cadeia que parte de **um processo principal**. Quando o sistema sabe dessa relação: você abre a pasta principal e vê todos os filhos, abre um filho e vê o principal, busca o cliente e tudo aparece organizado. Vinculação errada ou ausente = informação espalhada e pasta perdida.',
    'passos' => array(
        '1. Abra a pasta do processo que quer marcar como **incidental ou recurso de outro**.',
        '2. Toolbar superior → **⚙️ Ações ▾** → clique em **📎 Marcar como incidental de outro**.',
        '3. Modal abre. Primeira escolha: **Tipo de vínculo**.',
        '   • **📎 Processo Incidental** (default) — pega carona no principal pra resolver questão acessória (execução, tutela, embargos de terceiro).',
        '   • **📜 Recurso** — ataca decisão do principal em instância superior (Apelação, Agravo, ED, REsp, RE).',
        '4. **Tipo de relação** — dropdown filtra conforme o vínculo escolhido. Se nenhum se aplica → **"Outros"** abre campo amarelo obrigatório pra você digitar a natureza jurídica real.',
        '5. **Processo principal** — campo de busca com **autocomplete em tempo real**. Já abre com **Sugestões automáticas** (mesmo cliente + partes em comum, com badges visuais).',
        '6. Selecione o principal → confira **Vincular →** → pasta recarrega com banner azul "📎 Este é processo incidental de [PRINCIPAL]".',
        '7. Pra **desvincular**: o banner azul tem botão Desvincular (gestao+).',
    ),
    'atencao' => '**Vinculação é bilateral.** Se você cadastra **A como incidental de B**, automaticamente B passa a mostrar A na seção "Processos Incidentais (1)". Você não precisa fazer dos dois lados. Cuidado: **não use Incidental pra Recurso e vice-versa** — Recurso tem regras próprias (instância superior, prazos diferentes), e relatórios filtram por `tipo_vinculo`.',
    'dica' => 'Sempre use as **Sugestões automáticas** primeiro. Se a vinculação faz sentido (mesmo cliente, partes em comum), elas vão estar lá. Digitar texto livre é só pra casos exóticos.',
    'missao' => 'Pegue na sua pasta um processo que VOCÊ SABE ser incidental ou recurso de outro. Vincule. Confira: (1) banner azul aparece no topo, (2) o processo principal mostra este na seção Processos Incidentais ou Recursos.',
),

// ─── Catálogo de "Outros" — pro tipo de relação ───
// Esses exemplos cobrem 95% dos casos onde "Outros" se aplica e ajudam Amanda
// a NÃO cadastrar tudo como literalmente "Outros" (que perde a especificidade).
//
// INCIDENTAL "Outros" (digitar a natureza jurídica):
//   • Carta Precatória — cumprimento de ato em outra comarca
//   • Embargos de Terceiro — terceiro reivindica bem em execução
//   • Habilitação de Crédito — credor pede habilitação em execução/inventário
//   • Cumprimento Provisório — CPC art. 520 (sentença sem trânsito em julgado)
//   • Impugnação ao Cumprimento de Sentença — defesa do executado
//   • Embargos do Devedor / Embargos à Execução
//   • Embargos à Adjudicação
//   • Restauração de Autos — autos perdidos/destruídos
//   • Liquidação de Sentença — fixar valor antes da execução
//   • Reconvenção (vinculada à ação principal)
//   • Suscitação de Dúvida (CNJ — Provimento)
//
// RECURSO "Outros" (digitar a natureza jurídica):
//   • Conflito de Competência
//   • Mandado de Segurança Recursal
//   • Pedido de Suspensão
//   • Habeas Corpus
//   • Carta Testemunhal (recurso contra denegação de outro recurso)
//   • Reclamação Constitucional
//   • Pedido de Reconsideração

'cadastrar-processo-existente' => array(
    'telas_html' => <<<'HTML'
<figure class="tm-screen">
  <div class="tm-screen-chrome">
    <span class="tm-screen-dots"><span></span><span></span><span></span></span>
    <span class="tm-screen-url">/conecta/modules/operacional/caso_novo.php</span>
  </div>
  <div class="tm-screen-body">
    <div class="tm-mock-form">
      <div class="tm-mock-form-title">➕ Cadastrar processo existente</div>
      <div class="tm-mock-grid">
        <div class="tm-mock-field full">
          <label>Cliente ★ (busca por nome/CPF)</label>
          <div class="tm-mock-input">Maria da Silva Santos — 139.***.***-05</div>
        </div>
        <div class="tm-mock-field full">
          <label>Título da pasta ★ (padrão da casa)</label>
          <div class="tm-mock-input focus">MARIA SILVA x Alimentos</div>
        </div>
        <div class="tm-mock-field">
          <label>Nº CNJ</label>
          <div class="tm-mock-input">0817952-56.2025.8.19.0202</div>
        </div>
        <div class="tm-mock-field">
          <label>Sistema</label>
          <div class="tm-mock-input">PJe</div>
        </div>
        <div class="tm-mock-field">
          <label>Vara</label>
          <div class="tm-mock-input">2ª Vara Família</div>
        </div>
        <div class="tm-mock-field">
          <label>Status</label>
          <div class="tm-mock-input">Em andamento</div>
        </div>
      </div>
    </div>
  </div>
  <p class="tm-screen-caption">Tela — Cadastro direto no Operacional (pra processos que já tramitam). Nomeie sempre <strong>"NOME x AÇÃO"</strong>. Se o cliente é novo, cadastre antes pelo Pipeline pra manter métrica do funil.</p>
</figure>
HTML,
    'por_que' => 'Tem casos onde o processo **já está em andamento** quando o cliente chega pra cá: cliente migrou de outro escritório, recurso de processo antigo, pasta herdada, etc. O fluxo do Pipeline Comercial assume processo NOVO (vai cadastrar lead → mover até "Contrato Assinado" → criar pasta automaticamente). Pra esses casos onde já existe número CNJ, vara, andamentos, etc, faz mais sentido cadastrar **direto no Operacional** já com tudo preenchido.',
    'passos' => array(
        'Abra **Kanban Operacional** ou **Processos** (lista) na sidebar.',
        'Clique em **+ Novo Processo** (botão no topo da página).',
        'Form abre. Comece pelo campo **Cliente *** — use a busca: digite **nome, CPF (com ou sem pontuação) ou parte do CPF (3+ dígitos)**. Selecione o cliente. Se for cliente novo, cadastre-o antes via Pipeline Comercial pra manter a métrica do funil.',
        '**Papel do Cliente** — Autor / Réu / Rep. Legal (a depender do polo do seu cliente).',
        '**Nome da Pasta / Título *** — convenção da casa: **"NOME DO CLIENTE x TIPO DE AÇÃO"** (ex: "MARIA SILVA x Divórcio"). Não invente formato próprio — relatórios e dashboards seguem esse padrão.',
        '**Tipo de Ação** (dropdown — Família, Sucessões, Cível, Imobiliário, etc).',
        '**Nº do Processo** — CNJ. Quando preencher, o sistema verifica se já existe em outro caso e avisa.',
        '**Vara**, **Comarca** (autocomplete RJ), **UF** (default RJ), **Regional** (Volta Redonda, Madureira, etc), **Sistema** (PJe / PROJUDI / e-SAJ).',
        '**Status** — geralmente "Em andamento" se o processo já está tramitando. Pra processos antigos arquivados, use "Concluído / Arquivado".',
        '**Data de Distribuição** — quando o processo foi originalmente ajuizado (busque no PJe se não souber).',
        '**Departamento** (Operacional default), **Categoria** (Judicial / Extrajudicial), **Responsável** (advogada/o que cuida do caso).',
        'Bloco **Partes do Processo** — adicione autor / réu / litisconsorte / representante legal. O nome tem **autocomplete contra `clients`**: se bater com cliente cadastrado, linka automaticamente, marca o checkbox "CLIENTE" e puxa o CPF (badge **NOSSO CLIENTE** aparece depois na pasta).',
        '**Filhos** (em ações de família com criança envolvida) — nome + nascimento + CPF. Salvo em `filhos_json` da pasta.',
        'Salva. Sistema cria registros em `cases` + `case_partes` (e, se o CNJ tinha sido capturado pelo Email Monitor antes, importa os andamentos antigos automaticamente).',
    ),
    'atencao' => 'Se o cliente JÁ existe no sistema mas nunca passou pelo Pipeline Comercial, considere lançar como lead em **"Cadastro Preenchido"** e mover até **"Contrato Assinado"** — isso dispara automaticamente: criação da pasta no Drive, abertura do caso no Operacional e mensagem de boas-vindas. Cadastrar direto no Operacional pula isso e perde a métrica do funil.',
    'dica' => 'Se você está cadastrando um caso que veio de email do PJe (CNJ aparecendo na aba **Pendentes** do Email Monitor), use o botão **+ Cadastrar** lá — ele já abre este form com **CNJ, Vara, Comarca, UF e partes pré-preenchidos** pelo email. Bem mais rápido que digitar tudo de novo.',
    'missao' => 'Cliente novo chegou aqui com processo Cível ajuizado em outro escritório, com número CNJ e vara em Volta Redonda. Cadastre no Hub: (1) cliente novo (cadastre antes pelo Pipeline ou direto pelo módulo Clientes), (2) processo com número CNJ + vara + comarca, (3) cliente como Autor + parte contrária como Réu, (4) status "Em andamento". Depois abra a pasta criada e confirme que está tudo lá.',
),

'agendar-mensagem-wa' => array(
    'por_que' => 'Você combinou de dar retorno pro cliente na próxima segunda, mas não vai lembrar sábado à noite de programar isso. Ou tem audiência de manhã cedo e precisa mandar um check-in um dia antes. **Agendar Mensagem** deixa você escrever a mensagem agora e escolher exatamente quando ela sai — o Hub manda automaticamente no horário marcado, sem precisar deixar aba aberta nem lembrar.',
    'telas_html' => <<<'HTML'
<figure class="tm-screen">
  <div class="tm-screen-chrome">
    <span class="tm-screen-dots"><span></span><span></span><span></span></span>
    <span class="tm-screen-url">ferreiraesa.com.br/conecta</span>
  </div>
  <div class="tm-screen-body">
    <div class="tm-mock-sidebar">
      <div class="sec">💬 WhatsApp</div>
      <div class="item"><span class="icon">💬</span>Comercial (21)<span class="badge" style="background:#dc2626">4</span></div>
      <div class="item"><span class="icon">💬</span>CX / Operac. (24)<span class="badge" style="background:#dc2626">12</span></div>
      <div class="item"><span class="icon">📬</span>Caixa de Envios</div>
      <div class="item hot"><span class="icon">📅</span>Agendar Mensagem<span class="badge">3</span></div>
      <div class="arrow">← É aqui que você abre</div>
      <div class="item"><span class="icon">📊</span>Dashboard WhatsApp</div>
    </div>
  </div>
  <p class="tm-screen-caption">Tela 1 — Menu lateral. A entrada nova fica destacada em bronze e a bolinha azul mostra quantas mensagens estão na fila.</p>
</figure>

<figure class="tm-screen">
  <div class="tm-screen-chrome">
    <span class="tm-screen-dots"><span></span><span></span><span></span></span>
    <span class="tm-screen-url">/conecta/modules/agendar_msg/</span>
  </div>
  <div class="tm-screen-body">
    <div class="tm-mock-form">
      <div class="tm-mock-form-title">➕ Novo agendamento</div>
      <div class="tm-mock-grid">
        <div class="tm-mock-field full">
          <label>Cliente</label>
          <div class="tm-mock-input focus">maria da s</div>
          <div class="tm-mock-cli-sug">
            <div class="r hov"><div><strong>Maria da Silva Santos</strong></div><small>21 99754-1122 · Ação previdenciária</small></div>
            <div class="r"><div><strong>Maria da Silveira Costa</strong></div><small>24 99311-4488 · Divórcio consensual</small></div>
          </div>
          <div class="tm-mock-hint">Telefone é preenchido automaticamente ao selecionar.</div>
        </div>
      </div>
    </div>
  </div>
  <p class="tm-screen-caption">Tela 2 — Começa a digitar o nome, o Hub sugere da agenda. Ao clicar, o telefone entra sozinho.</p>
</figure>

<figure class="tm-screen">
  <div class="tm-screen-chrome">
    <span class="tm-screen-dots"><span></span><span></span><span></span></span>
    <span class="tm-screen-url">/conecta/modules/agendar_msg/</span>
  </div>
  <div class="tm-screen-body">
    <div class="tm-mock-form">
      <div class="tm-mock-grid">
        <div class="tm-mock-field"><label>Telefone</label><div class="tm-mock-input">21 99754-1122</div></div>
        <div class="tm-mock-field"><label>Canal</label><div class="tm-mock-input">24 — CX / Operacional</div></div>
        <div class="tm-mock-field"><label>Data</label><div class="tm-mock-input">15/07/2026</div></div>
        <div class="tm-mock-field"><label>Hora</label><div class="tm-mock-input">09:30</div></div>
      </div>
    </div>
  </div>
  <p class="tm-screen-caption">Tela 3 — Canal 24 (CX/Operacional) é o padrão. 21 é pra cliente do Comercial. Data e hora são horário de Brasília.</p>
</figure>

<figure class="tm-screen">
  <div class="tm-screen-chrome">
    <span class="tm-screen-dots"><span></span><span></span><span></span></span>
    <span class="tm-screen-url">/conecta/modules/agendar_msg/</span>
  </div>
  <div class="tm-screen-body">
    <div class="tm-mock-form">
      <div class="tm-mock-grid">
        <div class="tm-mock-field full">
          <label>Mensagem</label>
          <div class="tm-mock-input tall focus">Bom dia, {{primeiro_nome}}! Só passando pra confirmar sua audiência de hoje às 14h.

Se precisar de qualquer ajuda, é só responder por aqui.

Equipe Ferreira &amp; Sá Advocacia</div>
          <div class="tm-mock-hint">
            Variáveis clicáveis:
            <span class="tm-mock-var">{{primeiro_nome}}</span>
            <span class="tm-mock-var">{{nome}}</span>
            <span class="tm-mock-var">{{data_hoje}}</span>
          </div>
        </div>
      </div>
      <div class="tm-mock-btn-good">Agendar</div>
    </div>
  </div>
  <p class="tm-screen-caption">Tela 4 — Escreve a mensagem, clica nas chips laranjas pra inserir variáveis. Sempre assinar "Equipe Ferreira &amp; Sá Advocacia".</p>
</figure>

<figure class="tm-screen">
  <div class="tm-screen-chrome">
    <span class="tm-screen-dots"><span></span><span></span><span></span></span>
    <span class="tm-screen-url">/conecta/modules/agendar_msg/</span>
  </div>
  <div class="tm-screen-body">
    <div class="tm-mock-hdr">
      <div>
        <h4>📅 Agendar Mensagem WhatsApp</h4>
        <div class="s">Escolha cliente, data/hora e a mensagem.</div>
      </div>
      <div class="stats">
        <div class="tm-mock-stat"><b>3</b>pendentes</div>
        <div class="tm-mock-stat"><b>17</b>enviados 7d</div>
        <span class="tm-mock-kill">● LIGADO</span>
      </div>
    </div>
    <div class="tm-mock-tabs">
      <span class="tm-mock-tab active">⏳ Pendentes <span class="n">3</span></span>
      <span class="tm-mock-tab">📜 Histórico</span>
    </div>
    <div class="tm-mock-item pend">
      <div class="top">
        <span class="quem">Maria da Silva Santos</span>
        <span class="canal">Canal 24</span>
        <span class="quando">Para: <b>15/07 às 09:30</b></span>
        <span class="badge pend">Pendente</span>
        <span class="cancel">✕ Cancelar</span>
      </div>
      <div class="msg">Bom dia, Maria! Só passando pra confirmar sua audiência de hoje às 14h…</div>
    </div>
    <div class="tm-mock-item env">
      <div class="top">
        <span class="quem">João Pereira Lima</span>
        <span class="canal">Canal 24</span>
        <span class="quando">Enviado ontem, 14:00</span>
        <span class="badge env">✓ Enviado</span>
      </div>
      <div class="msg">Boa tarde, João! Sua parcela vence sexta (10/07). Se precisar…</div>
    </div>
  </div>
  <p class="tm-screen-caption">Tela 5 — Depois de salvar, mensagem aparece em "Pendentes" com botão vermelho pra cancelar. Depois de enviar, vai pro Histórico como verde ✓.</p>
</figure>
HTML,
    'passos' => array(
        'Menu lateral **💬 WhatsApp → 📅 Agendar Mensagem**. A bolinha azul do lado do nome mostra quantas mensagens estão na fila.',
        '**Cliente** — comece a digitar o nome. O Hub sugere da agenda; ao clicar, o **telefone é preenchido sozinho** (pode editar se estiver desatualizado).',
        '**Canal** — 24 (CX/Operacional) é o padrão; 21 pra cliente do Comercial. Não troque de canal se cliente já conversa por um deles — número diferente confunde.',
        '**Data e hora** — quando você quer que a mensagem saia (horário de Brasília, mesmo do servidor).',
        '**Mensagem** — escreva o texto. Pra personalizar sem digitar, clique nas chips laranjas: `{{primeiro_nome}}` vira **Maria**, `{{nome}}` vira **Maria da Silva**, `{{data_hoje}}` vira **15/07/2026** (a data em que a mensagem sair).',
        'Botão **Agendar** — mensagem entra na aba **Pendentes** (badge amarela).',
        'Enquanto está pendente, botão **✕ Cancelar** desfaz. Depois de enviada, vai pra aba **Histórico** como ✓ verde.',
        'Se falhar 3 vezes (número inválido, WhatsApp fora do ar), fica marcada em **vermelho** com o erro que a Z-API devolveu.',
    ),
    'atencao' => '**Nunca** coloque CPF completo, número de processo com dados sensíveis, senha ou código de acesso na mensagem — a lista é visível pra toda a equipe. Assine sempre **"Equipe Ferreira & Sá Advocacia"**, nunca "Dra. Amanda" ou nome pessoal — mensagens agendadas saem em nome do escritório, não da pessoa que agendou.',
    'dica' => 'Não dá pra editar depois de salvar. Se errou uma palavra, **cancela e cria de novo** — é rápido, o botão vermelho na linha já faz. E antes de agendar cobrança pra um cliente, dá uma olhada na conversa dele no WhatsApp normal — se ele já respondeu algo importante, você não vê nesta tela e pode mandar sem contexto.',
    'missao' => 'Agende uma mensagem de teste pra você mesma (seu próprio WhatsApp), pra daqui a 3 minutos, usando pelo menos uma variável tipo `{{primeiro_nome}}`. Confira: (1) chegou no horário, (2) a variável foi substituída pelo seu nome, (3) sumiu de Pendentes e apareceu em Histórico como ✓ Enviado.',
),

);
