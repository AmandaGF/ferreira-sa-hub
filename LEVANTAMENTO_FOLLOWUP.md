# LEVANTAMENTO DO CONECTA — Insumo para o Manual de Follow-up Comercial
### Ferreira & Sá · respostas extraídas direto do código (21/06/2026)

> **Legenda:**
> - **[PRINT — VOCÊ CAPTURA]** = preciso de captura de tela sua; o código não substitui.
> - **[RESPOSTA]** = respondido abaixo a partir do código atual.
> - **[CÓDIGO]** = arquivo + linha citados.
> - ⚠️ = ponto que muda decisão de manual / divergência a confirmar na tela.

---

## BLOCO 0 — Mapa geral e acesso

**0.1 [PRINT — VOCÊ CAPTURA]** Tela inicial após login com o menu lateral inteiro.

**0.2 [RESPOSTA] — Menu (fonte: `templates/sidebar.php:76-186`)**
O menu é organizado em seções. O que um **comercial** enxerga, na ordem:

| Seção | Item | Rota |
|---|---|---|
| Principal | Painel do Dia | `/conecta/modules/painel/` |
| Principal | Dashboard | `/conecta/modules/dashboard/` |
| Principal | Portal de Informações Rápidas | `/conecta/modules/portal/` |
| WhatsApp | Comercial (21) | `/conecta/modules/whatsapp/?numero=21` |
| WhatsApp | CX/Operac. (24) | `/conecta/modules/whatsapp/?numero=24` |
| WhatsApp | Caixa de Envios | `/conecta/modules/whatsapp/fila.php` |
| Atendimento | Helpdesk | `/conecta/modules/helpdesk/` |
| Atendimento | Agenda | `/conecta/modules/agenda/` |
| Comercial | CRM | `/conecta/modules/crm/` |
| Comercial | **Kanban Comercial** | `/conecta/modules/pipeline/` |
| Operacional | Kanban Operacional | `/conecta/modules/operacional/` |
| Operacional | Processos / Calc. Prazos / Extrajudicial / Pré-Processual / Fáb. Petições / Planilha de Cálculo | `/conecta/modules/<nome>/` |
| Cadastros | Agenda de Contatos | `/conecta/modules/clientes/` |
| Controle | Prazos / Ofícios / Alvarás | `/conecta/modules/<nome>/` |
| Dados | Documentos / Planilha | `/conecta/modules/<nome>/` |
| Comunicação | Mensagens / Notificações / Notif. Clientes / Aniversariantes | `/conecta/modules/<nome>/` |
| Conhecimento | Wiki | `/conecta/modules/wiki/` |
| Equipe | Ranking (Gamificação) | `/conecta/modules/gamificacao/` |

> ⚠️ A **Dashboard** aparece no menu, mas a aba **Comercial** dela depende de whitelist (ver 6.x). O comercial pode ver o módulo Dashboard mas não necessariamente a aba comercial completa sem override em `user_permissions`.

**0.3 [RESPOSTA] — Papéis (fonte: `core/functions_auth.php:10-22`, `:193-196`)**
São **7 roles**, com hierarquia por nível (`role_level()`):

| Role | Nível | Label |
|---|---|---|
| `admin` | 5 | Admin |
| `gestao` | 4 | Gestão |
| `comercial` | 3 | Comercial |
| `cx` | 3 | CX |
| `operacional` | 3 | Operacional |
| `estagiario` | 2 | Estagiário |
| `colaborador` | 1 | Colaborador |

- O atendente comercial = role **`comercial`** (nível 3, empatado com cx/operacional).
- **NÃO existe** papel separado "gestão comercial". A gestão comercial é exercida pela role `gestao` (nível 4), que enxerga tudo do comercial + telas de configuração (Formulários, Dashboard WhatsApp, metas).
- `has_min_role($min)` compara níveis: `role_level(atual) >= role_level($min)`.

**0.4 [RESPOSTA] — O que o comercial VÊ × NÃO VÊ (fonte: `functions_auth.php` `_permission_defaults()` `:37-95` + `sidebar.php`)**

**VÊ:** Painel do Dia, Dashboard (módulo), Portal, WhatsApp 21/24 + Caixa de Envios, Helpdesk, Agenda, CRM, **Kanban Comercial**, Kanban Operacional, Processos, Prazos, Ofícios, Alvarás, Documentos, Planilha, Fáb. Petições, Mensagens, Notificações, Notif. Clientes, Aniversariantes, Wiki, Ranking.

**NÃO VÊ (admin/gestão apenas):** Formulários, Dashboard WhatsApp + Configurações WhatsApp, Cobrança Honorários, Parceiros, Painel Executivo, Relatórios, Newsletter, Usuários, Onboarding F&S, Log de Acessos.
**Acesso por whitelist de pessoa (não por role):** Financeiro/Faturamento (só Amanda, Rodrigo, Luiz Eduardo), Códigos 2FA (lista nominal).

---

## BLOCO 1 — Entrada do lead

**1.1 [RESPOSTA] — Caminhos de entrada**
1. **Formulário do site / landing** → `publico/lead_site.php` → `process_form_submission()`. Origem gravada: parsed do POST, fallback `landing`.
2. **Formulários públicos / calculadoras** → `publico/api_form.php` (convivência, gastos_pensão, despesas_mensais, calculadora_lead, divórcio, alimentos, etc.) → mesmo `process_form_submission()`.
3. **Cadastro manual na UI** → `modules/pipeline/lead_form.php` → `modules/pipeline/api.php` (`action=create_lead`). Origem escolhida pelo atendente no enum.
4. **Indicação** → forma de origem `indicacao` (qualquer um dos caminhos acima).
5. **WhatsApp (Z-API)** → `api/zapi_webhook.php`. ⚠️ Cria **conversa** (`zapi_conversas`), **NÃO cria lead no pipeline automaticamente** — a virada pra lead é manual.
6. **Meta/Instagram** → `api/meta_webhook.php`. ⚠️ Hoje só **loga** o evento (Fase A, pré-App Review); **não cria lead** ainda.
7. **Importação em massa** → `modules/planilha/importar.php`.
> Não há webhook dedicado do Google Ads: leads de Google entram via formulário (com `source=google`) ou cadastro manual.

**1.2 [RESPOSTA] — Estágio inicial e dono (fonte: `core/form_handler.php:208-215`)**
- Estágio inicial **fixo = `cadastro_preenchido`** (hardcoded no INSERT).
- **Sem dono automático**: `assigned_to` entra **NULL**. O responsável precisa ser atribuído manualmente (dropdown no card / na tela do lead). ⚠️ *Isto é central pro manual: o lead cai sem dono — alguém precisa "pegar".*

**1.3 [RESPOSTA] — Campo origem (fonte: `schema.sql:118`, `modules/pipeline/lead_ver.php:78,155`)**
- Coluna = **`source`** (não "origem") em `pipeline_leads`, tipo ENUM:
  `'calculadora','landing','indicacao','instagram','google','whatsapp','outro'` (default `outro`).
- É gravado automaticamente quando o lead entra por formulário (parsed/hardcoded). No cadastro manual, o atendente escolhe.
- Aparece no card/tela do lead como campo **"Origem"** (rótulos legíveis: Calculadora, Site, Indicação, Instagram, Google, WhatsApp, Outro), **somente leitura**.
- **[PRINT — VOCÊ CAPTURA]** card aberto mostrando o campo Origem.

**1.4 [CÓDIGO] — Onde recebe e grava**
- `publico/lead_site.php:61` (`$origem = trim($_POST['origem'] ?? 'site')`), `:86` (chama `process_form_submission`).
- `core/form_handler.php:210` — INSERT em `pipeline_leads (... source='landing', stage='cadastro_preenchido' ...)`; `:214-215` grava `pipeline_history` com `to_stage='cadastro_preenchido'`.
- `modules/pipeline/api.php` (~`:521`) — criação manual.

---

## BLOCO 2 — Pipeline / Kanban comercial (coração do manual)

**2.1 / 2.3 [PRINT — VOCÊ CAPTURA]** O Kanban completo (todas as colunas) e um card aberto (modal de detalhe).

**2.2 [RESPOSTA] — Estágios reais (fonte: `core/pipeline_stages.php:18-33`, `modules/pipeline/index.php:20-39`)**

| # | Chave interna | Rótulo na tela | O que significa | Tipo |
|---|---|---|---|---|
| 1 | `cadastro_preenchido` | Cadastro Preenchido | Lead com dados básicos; aguardando elaboração | **VENDA** |
| 2 | `elaboracao_docs` | Elaboração Procuração/Contrato | Documentos sendo preparados | **VENDA** |
| 3 | `link_enviados` | Link Enviados | Contrato/links enviados; aguardando assinatura | **VENDA** |
| 4 | `contrato_assinado` | Contrato Assinado | Assinou → conversão (cria caso + pasta Drive) | **PÓS-VENDA (onboarding)** |
| 5 | `agendado_docs` | Agendado + Docs Solicitados | Reunião marcada; docs pedidos ao cliente | PÓS-VENDA |
| 6 | `reuniao_cobranca` | Reunião / Cobrando Docs | Follow-up / cobrança de documentos | PÓS-VENDA |
| 7 | `doc_faltante` | Documento Faltante | Falta doc; aguardando CX | PÓS-VENDA |
| 8 | `pasta_apta` | Pasta Apta | Docs completos; pronto p/ virar caso operacional | PÓS-VENDA |
| 9 | `suspenso` | Suspenso | Pausado temporariamente (prazo opcional) | PÓS-VENDA |
| 10 | `cancelado` | Cancelado | Cancelado **com motivo obrigatório** | TERMINAL |

Estados terminais/auxiliares fora das 10 colunas vivas: `para_arquivar` (flag visual `marcado_para_arquivar=1`, não muda stage), `finalizado` (foi pro Operacional/Jurídico), **`perdido`** (lead perdido, motivo), `arquivado` (some do Kanban).

> ⚠️ Confirmação útil na tela: o CLAUDE.md descreve "10 stages". Aqui as **10 colunas vivas** vão de `cadastro_preenchido` → `cancelado`; **`perdido`/`finalizado`/`arquivado`** são destinos terminais (não colunas no quadro). Os 3 primeiros são VENDA; do `contrato_assinado` em diante é onboarding/pós-venda. **Para o follow-up comercial, o que importa é toque 1–3 (`cadastro_preenchido`, `elaboracao_docs`, `link_enviados`).**

**2.3 [RESPOSTA] — Campos/botões do card (fonte: `modules/pipeline/lead_ver.php:90-392`)**
- **Cabeçalho:** nome (editável), botão Pasta Drive, botão WhatsApp.
- **Infos:** Etapa (badge, RO), Telefone, E-mail, **Origem (RO)**, Tipo de Ação, **Responsável (dropdown)**, Nome da Pasta, Criado em (RO).
- **Financeiro:** Honorários (R$), Êxito (%), Vencto 1ª Parcela, Forma de Pagamento, Cadastro Asaas (sim/não), Urgência; badges Asaas ✓ / INADIMPLENTE ⚠️ / Adimplente.
- **Observações & Pendências** (multiline editável).
- **Se perdido:** caixa vermelha "❌ Motivo da perda: …".
- **Agendamento/Onboard:** Data do agendamento, checkbox Onboarding realizado, checkbox Não precisa de onboarding, Origem do lead (select).
- **Botões de ação:** 📜 Elaborar Documento · 📝 Fábrica de Petições (se houver caso) · 📋 + Nova Ação (duplicar) · 🗑️ Excluir Lead.
- **Mover etapa:** um botão por destino válido + "❌ Perdido".
- **Histórico:** linha do tempo de todas as transições (usuário, data, nota).

**2.4 [RESPOSTA] — Como move e travas (fonte: `index.php:1094-1151,1340-1422`; `api.php:109-161`)**
- Dois jeitos: **arrastar (drag&drop)** o card ou **select "Mover para"** no card/tabela. Ambos chamam `handleStageMove()` → POST `api.php` action `move` → `UPDATE pipeline_leads SET stage=…` + grava `pipeline_history`.
- **Não há trava de pular etapa**: o select oferece todos os destinos válidos (exceto a etapa atual e `doc_faltante`, que tem fluxo próprio). Dá pra ir direto de `cadastro_preenchido` p/ `contrato_assinado`.
- **Confirmação obrigatória** (não trava, só `confirm()`) para estágios sensíveis: `perdido`, `suspenso`, `para_arquivar`, `arquivado`, `finalizado`.

**2.5 [RESPOSTA] — Disparos ao mover p/ `contrato_assinado` (fonte: `api.php:168-275`)**
1. `converted_at = NOW()` (marca data da conversão).
2. Cria/garante **cliente** em `clients` (se lead sem `client_id`).
3. Cria **caso** em `cases` (`status='aguardando_docs'`, responsável = `assigned_to`), com checklist via `generate_case_checklist()`.
4. Cria **pasta no Google Drive** (`create_drive_folder()`), grava `drive_folder_url`; se falhar, notifica admin pra refazer.
5. Vincula lead↔caso (`linked_case_id`).
6. **Gamificação:** +50 pts (`contrato_fechado`) + bônus se honorários > R$ 2.000; push pro responsável; notifica admin.
7. Dispara notificação de **boas-vindas** ao cliente (se configurada).

**2.6 [RESPOSTA] — Mover p/ `perdido` exige motivo? (fonte: `index.php:874-895,1167-1217`; `api.php:152-155`)**
- **`perdido`: motivo NÃO é obrigatório hoje** — não abre modal; só `confirm()`. O campo `lost_reason` é gravado *se* enviado, e aparece como caixa vermelha no card / coluna "Motivo" em `perdidos.php`. ⚠️ *Se o manual quiser exigir motivo no perdido, hoje é opcional — é um gap.*
- **`cancelado`: motivo OBRIGATÓRIO** via modal com dropdown fixo:
  - Inadimplência
  - Ausência de documentos
  - Parou de responder/bloqueou o escritório
  - Pediu cancelamento por questões financeiras
  - Demitida por não ter educação
  - Outro motivo *(abre textarea)*
- **[PRINT — VOCÊ CAPTURA]** o modal de motivo de cancelamento (esse dropdown).

**2.7 [RESPOSTA] — Agendar retomada (D+30/D+90) de card perdido?**
- **NÃO existe.** Não há coluna de data de retomada nem cron que reabra perdidos. Em `modules/pipeline/perdidos.php:54-67` há só dois botões: **🔄 Reativar** (volta o lead para `cadastro_preenchido`) e **🗑️ Excluir**. ⚠️ *A cadência D+30/D+90 de "perdido" terá de ser construída — não existe hoje.*

---

## BLOCO 3 — Motor de fluxos e construtor de WhatsApp

**3.1 [RESPOSTA] — Existe tela de fluxos? (fonte: `modules/whatsapp/fluxos.php`, `fluxo_ver.php`)**
**SIM.** Há construtor de fluxos (lista + criação em `fluxos.php`; edição de blocos/arestas em `fluxo_ver.php`). É **editor por formulário server-side** (não é Drawflow no cliente ainda). Acesso gestão+.
- **[PRINT — VOCÊ CAPTURA]** a tela de fluxos e um fluxo aberto.

**3.2 [RESPOSTA] — Composição e tempo (fonte: `core/functions_fluxos.php`; tabelas em `migrar_zapi_fluxos.php:26-110`)**
- Tabelas: `zapi_fluxo` (cabeçalho), `zapi_fluxo_bloco` (nós), `zapi_fluxo_aresta` (conexões), `zapi_fluxo_execucao` (estado ao vivo), `zapi_campo` / `zapi_conversa_valor` (campos capturados).
- **7 tipos de bloco:** `mensagem`, `esperar`, `capturar`, `condicional`, `transferir_humano`, `anotar`, `fim`.
- **Espera/delay:** bloco `esperar` com `timeout_min` (minutos). Ex.: "espera 3h" = `timeout_min: 180`.
- ⚠️ **Só delays relativos em minutos a partir de agora.** **NÃO** há "D+2" em dias úteis nem data absoluta. O tick destrava quando `aguardando_ate <= NOW()`.

**3.3 [RESPOSTA] — O que inicia (fonte: `functions_fluxos.php:392,441`; `api/zapi_webhook.php:651`)**
- Gatilhos (`gatilho_tipo`): `manual`, `primeira_msg` (1ª mensagem do cliente), `palavra_chave`.
- ⚠️ **Gatilho é por CONVERSA, não por estágio do pipeline.** Não há gatilho "entrou no estágio X". Iniciar por mudança de pipeline exigiria construir.

**3.4 [RESPOSTA] — Já existe fluxo de follow-up?**
- Só o **"DEMO Motor de Fluxos"** (`seed_fluxo_demo.php`): 5 blocos (msg → espera 5 min → captura → msg → fim). É demo/teste. **Nenhuma cadência de follow-up de pipeline (A1…A8 / B2…B7) existe como fluxo.**

**3.5 [RESPOSTA/CÓDIGO] — Cron e killswitch (fonte: `cron/zapi_fluxo_tick.php:10-11`; `fluxos.php:92,136-156`)**
- Roda como cron sugerido **a cada 1 min** (`* * * * * curl .../cron/zapi_fluxo_tick.php?key=…`).
- **Executor DESLIGADO por padrão:** flag `configuracoes.zapi_fluxo_executor_ativo` = `'0'`. Toggle pela UI (🟢 ligado / 🟡 desligado).

**3.6 [RESPOSTA] — Speed-to-lead (1º toque imediato) (fonte: `core/functions_followup.php`; `core/form_handler.php:260-268`; `modules/whatsapp/automacoes.php:50-51`)**
- **Já está IMPLEMENTADO, porém DESLIGADO.** Hook em `process_form_submission` chama `followup_speed_to_lead()` ao criar o lead.
- Lógica: elegível se `stage=cadastro_preenchido` e `source != whatsapp`, telefone válido; escolhe template "Follow A1 - Abertura" (horário) ou "…Fora de horario"; envia pelo **canal 21**; grava `pipeline_leads.primeiro_contato_em`.
- **Dois killswitches, ambos precisam estar `'1'`:** `configuracoes.followup_ativo` + `configuracoes.followup_speed_to_lead` (hoje ambos `'0'`).
- UI liga/desliga: card "🚀 Follow-up de Leads" em `modules/whatsapp/automacoes.php`. ⚠️ *Quando ligado, o envio é síncrono no submit (~1-2s). Hoje, em produção, NÃO dispara nada (está OFF).*

---

## BLOCO 4 — Módulo Mensagens (templates)

> ⚠️ **Existem DOIS sistemas de template, não confundir:**
> - **`message_templates`** (módulo Mensagens, `modules/mensagens/`) = biblioteca curada; placeholders `{nome}`; **texto literal, NÃO renderiza** sozinho (atendente substitui na mão ao copiar).
> - **`zapi_templates`** (módulo WhatsApp, `modules/whatsapp/templates.php`) = respostas rápidas; placeholders `{{var}}`; **renderiza de verdade** via `zapi_get_template()`. **É este que o follow-up usa.**

**4.1 / 4.2 [PRINT — VOCÊ CAPTURA]** Lista de templates e a tela de criar/editar template (ambos os sistemas, se possível).

**4.3 [RESPOSTA] — Variáveis (fonte: `modules/mensagens/index.php:223`; `core/functions_zapi.php:1289-1325`)**
- **message_templates (literal):** `{nome}`, `{tipo_acao}`, `{data_audiencia}`, `{local_audiencia}`, `{numero_processo}`, `{vara}`, `{atualizacao}` — detectados por regex `\{(\w+)\}` ao salvar; **não renderizam dinamicamente.**
- **zapi_templates (renderiza):** placeholders **`{{var}}`** arbitrários, substituídos em `zapi_get_template($nome, $vars)`. Suporta **`{{masc|fem}}`** (resolve por `clients.gender` / inferência por nome). ⚠️ Não há lista fixa de variáveis pré-definidas; `{{tema}}` e `{{data_limite}}` **não existem nativamente** — são montados pelo `core/functions_followup.php` (dicionário slug→rótulo legível) quando o follow-up roda.

**4.4 [RESPOSTA] — Amarração a canal / passo (fonte: `migrar_whatsapp_zapi.php:112`; `modules/mensagens/index.php:228-235`)**
- **message_templates:** colunas `for_whatsapp` (0/1) e `for_email` (0/1).
- **zapi_templates:** coluna **`canal` ENUM('21','24','ambos')** + **`categoria`** (texto livre, ex.: `followup`) + **`atalho`** (atalho `/` no chat).
- ⚠️ **NÃO há amarração de template a passo de fluxo nem a estágio do pipeline.** `categoria` é só agrupamento livre.

**4.5 [RESPOSTA] — Enviar template manual do card (fonte: `modules/whatsapp/index.php:2995-3000`)**
- **SIM**, dentro do chat do WhatsApp: modal "WA Sender" (`waSenderOpen({telefone, mensagem, canal, clientId})`); o atendente pode editar antes de enviar; aplica assinatura automática se configurada. Também há expansão por atalho `/atalho` no textarea.
- ⚠️ É no módulo **WhatsApp**, não dentro do card do Pipeline. **[PRINT — VOCÊ CAPTURA]** desse caminho.

---

## BLOCO 5 — `cliente_esfriando`, alertas e Painel do Dia (ponte crítica)

**5.1 [RESPOSTA/CÓDIGO] — Score `cliente_esfriando` (fonte: `cron/cliente_esfriando.php:9-19`; `core/functions_ia.php:251,322-325`)**
- Coluna `clients.esfriando_score` (0–100) + `esfriando_motivos` + `esfriando_em`. Calculado por `ia_recalcular_esfriando_clientes()`, cron diário.
- Pontuação: WhatsApp última msg **>90d = +60**, **45–89d = +40**; andamento de processo **>90d = +60**, **45–89d = +40**; cobranças/tarefas vencidas só informam (não somam). Máx 100.
- Faixas: **≥80 = "Esfriando" (vermelho)**, 40–79 = "Atenção", <40 = OK.
- ⚠️ **É score de CLIENTE com caso/WhatsApp** — não de lead frio no pipeline.

**5.2 [RESPOSTA — DECISIVO] (fonte: `cron/alertas_inatividade.php:55-176`; `cron/cliente_esfriando.php:50-62`)**
**Quando um card passa do prazo, o sistema NÃO cria tarefa no Painel do Dia.** Ele apenas **notifica (sino/push via `notify()`)** e/ou marca badge/cor. Não há `INSERT` de tarefa em lugar nenhum da lógica de prazo/esfriamento.
> ⚠️ *Esta é a peça que falta pro manual ter "rotina executável": hoje o esfriamento vira aviso, não tarefa. A decisão B do projeto (já aprovada) é criar a tarefa de lead em `agenda_eventos`/`lead_followups` — mas **ainda não foi implementada**.*

**5.3 [RESPOSTA] — Fontes do Painel do Dia (fonte: `modules/painel/index.php:71-265`)**
Lê: **`agenda_eventos`** (compromissos do dia), **`prazos_processuais`** (prazos, inclui vencidos), **`case_tasks`** (tarefas do dia + top-10 atrasadas), **`eventos_dia`** (lembretes/post-its pessoais). O bloco "cliente esfriando" aparece em card separado (briefing IA), fora da timeline.
- **[PRINT — VOCÊ CAPTURA]** o Painel do Dia mostrando tarefas e leads esfriando.

**5.4 [RESPOSTA] — Dar baixa (fonte: `modules/painel/api.php:312-353`)**
Botão "Dar Baixa" nas tarefas atrasadas → POST `baixar_atrasada` → `UPDATE case_tasks SET status='concluido', completed_at=NOW()`. Gestão dá baixa em qualquer uma; demais só nas próprias (`assigned_to`). Conta no bloco "dopamina/baixas".
- **[PRINT — VOCÊ CAPTURA]** a ação de baixa.

**5.5 [RESPOSTA] — SLA configurável? (fonte: `cron/alertas_inatividade.php:55-272`)**
**NÃO é configurável** — os prazos são **hardcoded no cron de alertas**: `elaboracao_docs`=3d, `aguardando_docs`=7d, `distribuido`(sem nº)=2d, `em_elaboracao`=5d, `suspenso`=30d. São **limiares de alerta** (disparam notificação), não SLA editável em tela nem coluna de banco. ⚠️ *Se o manual citar SLA por etapa, hoje só existe esse conjunto fixo, e só para etapas do operacional — não há SLA por etapa do pipeline comercial.*

---

## BLOCO 6 — Dashboard → aba Comercial

**6.1 [PRINT — VOCÊ CAPTURA]** A aba Comercial inteira.

**6.2 [RESPOSTA] — Indicadores (fonte: `modules/dashboard/index.php:625-691`)**
1. **"Contratos em [Mês]"** (KPI principal, com barra de meta)
2. **"Faturamento [Mês] (contratado)"** (se permissão `faturamento`)
3. **"Ticket Médio"**
4. **"Tempo Médio p/ Fechar"** (dias até contrato)
5. **"Cancelados [Mês]"**
6. **"Taxa de Conversão (6 meses)"** (gráfico)
7. **"Entradas vs Contratos"** (gráfico)
8. **"Onboarding — Resumo"** (Realizados / Não compareceu / Agendados)
9. **"Onboarding por Mês"** (gráfico)
10. **"Tipos de Ação mais Contratados"** (tabela)
11. **"Funil Comercial"** (Cadastro → Elaboração → Link Enviado → Contrato → Agendado → Cobrando Docs → Pasta Apta → Cancelado)

**6.3 [RESPOSTA] — Conversão por origem / por atendente**
**NÃO existe.** A "Taxa de Conversão (6 meses)" é **agregado mensal global** — não desagrega por origem (Meta/Google/Indicação) nem por atendente. ⚠️ *São KPIs a construir (Req 5 do projeto).*

**6.4 [RESPOSTA] — Meta editável (fonte: `modules/dashboard/index.php:56-87,815-852`)**
**SIM, existe** (admin). Modal `#modalMetas` salva em `configuracoes`: `meta_contratos_mes`, `meta_faturamento_mes`, `meta_distribuicoes_mes` (`INSERT … ON DUPLICATE KEY UPDATE`, guard `has_role('admin')`).
- **[PRINT — VOCÊ CAPTURA]** o modal de metas.

**6.5 [RESPOSTA] — Filtro de período (últimos 3 meses)**
**NÃO existe** na aba Comercial. Período é **fixo**: mês atual + mês anterior (comparativo) + 6 meses (gráficos). Sem seletor de período.

---

## BLOCO 7 — Gamificação / ranking

**7.1 [PRINT — VOCÊ CAPTURA]** A tela de Ranking/Gamificação.

**7.2 [RESPOSTA] — O que pontua (fonte: `core/functions_gamificacao.php:11-32`)**
- **Comercial:** lead_cadastrado **5** · contrato_fechado **50** · contrato_bonus_alto **30** · onboarding_realizado **20** · avaliacao_5_estrelas **40** · meta_atingida **100**.
- **Operacional:** processo_distribuido **30** · peticao_distribuicao **50** · prazo_cumprido **25** · tarefa_concluida **10**.
- **Treinamento:** modulo_concluido **50** · quiz_nota_maxima **20** · treinamento_completo **200**.
- **Manual:** pontos_manuais (override).
- **Configurável?** Os valores por ação são **fixos no código**; o que o admin edita é **meta mensal** (`gamificacao_config.meta_principal`) e **prêmios** (premio_1/2/3) na aba "Premiação".

**7.3 [RESPOSTA] — Ranking (fonte: `index.php:38-52,1269-1310`)**
Ranking **por atendente**, corte **MENSAL** (`gamificacao_totais` por `mes_referencia/ano_referencia`). Também há ranking de pontos totais (carreira). **Não há corte semanal.**

---

## BLOCO 8 — Agenda e consulta com o advogado

**8.1 [RESPOSTA] — Agendar consulta do card? (fonte: `modules/agenda/index.php:205-289`)**
**NÃO há botão "agendar consulta" dentro do card do pipeline.** A agenda vive em `modules/agenda/`; existe o tipo **"Reunião lead"** (`+ Novo compromisso` → tipo Reunião lead, pré-preenchível por `?client_id=X`). O caminho é: abrir Agenda → novo compromisso → tipo Reunião lead.
- **[PRINT — VOCÊ CAPTURA]** o caminho de novo compromisso.

**8.2 [RESPOSTA] — Lembretes (fonte: `cron/agenda_lembretes.php:19-234`)**
**SIM.**
- **Atendente/responsável:** notificação no portal **1 dia antes** e **2 horas antes** (tipo urgência).
- **Cliente:** **2h antes**, mensagem de WhatsApp via `notificacao_cliente` (template com `[nome]`, `[data]`, `[hora]`, `[link_meet]`) — ⚠️ salva pra envio (não necessariamente disparo 100% automático; confirmar na operação).
- **Prazos processuais:** alertas escalonados 7d/3d/1d/HOJE/VENCIDO pro responsável + operacional/admin.

---

## BLOCO 9 — WhatsApp / envio

**9.1 [RESPOSTA] — Integração (fonte: `core/functions_zapi.php:383-464`; `zapi_instancias`)**
**Z-API.** Duas instâncias: **DDD 21 (Comercial)** e **DDD 24 (CX/Operacional)**, cada uma com `instancia_id`+`token` em `zapi_instancias`; config em `configuracoes.zapi_*`. Ativo se as credenciais estiverem preenchidas/conectadas (confirmar status atual em `modules/whatsapp/configurar.php`).

**9.2 [RESPOSTA] — Número único × por atendente**
**Um número por DDD** (instância compartilhada): todo o comercial sai do mesmo WhatsApp 21. **Não há número por atendente.**
- ⚠️ **Risco de ban:** sem cap de envio por número; rajada de mensagens iguais pode gerar bloqueio. Defesa atual: dedup na `fila_envio` (não repete msg idêntica ao mesmo cliente em 24h) e envios em massa passam por revisão na UI. *(Guard-rail do projeto: automatizar toques 1–3 + ruptura; prova social semi-manual; ligações sempre manuais; respeitar horário 10–18 seg–sex; parar no 1º "respondeu".)*

**9.3 [RESPOSTA] — Mesma conversa / histórico (fonte: `core/functions_fluxos.php:507-514`)**
**SIM.** Mensagem de fluxo e mensagem do atendente entram na **mesma conversa** (`zapi_mensagens`, mesmo `conversa_id`), diferenciadas por `direcao` (enviada/recebida) e `enviado_por_bot` (1/0). Histórico completo fica no thread em `modules/whatsapp/` (não dentro do card do pipeline).

---

## SÍNTESE — O que já existe × o que falta para o follow-up

**Já existe (reaproveitável):**
- Pipeline com estágios e histórico (`pipeline_history` permite derivar entrada no estágio).
- Speed-to-lead **codado** (`functions_followup.php` + hook no submit) — só **desligado**.
- Motor de fluxos genérico (`zapi_fluxo*`) + cron de 1 min — mas gatilho por conversa e **desligado**.
- Templates renderizáveis `zapi_templates` (canal 21, categoria `followup`, `{{masc|fem}}`).
- Painel do Dia lê agenda/prazos/tarefas; dar baixa funciona.
- Dashboard com metas editáveis; gamificação mensal por atendente.

**Falta (gaps que o manual vai expor):**
1. ⚠️ Lead entra **sem dono** (atribuição manual) — disciplina a treinar.
2. ⚠️ Cadência de follow-up de pipeline (Trilha A/B) **não existe** — decisão aprovada é scheduler dedicado `lead_followups`, ainda não implementado.
3. ⚠️ Esfriamento/prazo **não vira tarefa** no Painel do Dia (só sino/push) — a "ponte" do Bloco 5.2 precisa ser construída.
4. ⚠️ **Perdido** não exige motivo e não agenda retomada D+30/D+90.
5. ⚠️ Dashboard **sem** conversão por origem/atendente, **sem** filtro de período, **sem** KPIs de speed-to-lead / taxa de resposta / nº de toques.
6. ⚠️ SLA por etapa do pipeline **não é configurável** (só limiares fixos no operacional).

*(Decisões arquiteturais A–F já aprovadas estão registradas no projeto — ver memória `followup-project-status`.)*

---

## STATUS DE IMPLEMENTAÇÃO — atualizado 21/06/2026

### Decisões aprovadas (A–F)
- **A** — Motor: **scheduler dedicado `lead_followups` + cron próprio**. NÃO mexer no motor `zapi_fluxo*` (fica como está; está desligado).
- **B** — Tarefa de lead: **Painel do Dia lê direto de `lead_followups`** (não usar `agenda_eventos` nem `case_tasks`).
- **C** — Gatilho de lead esfriando = **tempo-no-estágio via `pipeline_history`**. `esfriando_score` fica reservado a cliente com caso.
- **D** — Mensagens como **`zapi_templates` canal 21**; `{{tema}}` via dicionário legível (fallback "sua questão familiar"); **`{{data_limite}}` removido do B7** (decisão de negócio: 20% não é padrão, é concessão manual sob aprovação do financeiro).
- **E** — Parada "lead respondeu": **validar vínculo telefone→conversa** antes de ligar o auto-stop; começar conservador (atendente para manual + lead sai do estágio).
- **F** — Speed-to-lead: hook em `process_form_submission` + coluna `primeiro_contato_em`; reusa `zapi_fora_horario()` e o gancho `zapi_auto_boasvindas`.

**Ordem:** 1 → 4 → 2 → 3 → 5.

### ✅ Item 1 (Speed-to-lead) — IMPLEMENTADO (kill switch DESLIGADO)
- Coluna `pipeline_leads.primeiro_contato_em` (base do KPI speed-to-lead).
- `core/functions_followup.php`: `followup_speed_to_lead()` envia o 1º toque (A1) no lead novo pelo canal 21; dicionário de `{{tema}}`; filtro de source (`whatsapp` não entra; `indicacao` usa abridor mais quente).
- Hook em `process_form_submission` (envolto em try/catch — nunca quebra o formulário).
- 3 templates A1 (canal 21, categoria `followup`): **form/anúncio**, **indicação**, **fora de horário**.
- **Liga/desliga por UI:** WhatsApp → ⚙️ Automações → card "🚀 Follow-up de Leads" (`followup_ativo` + `followup_speed_to_lead`, nascem `0`). Botões **"Testar entrega"** e **"Ver simulação (dry-run)"**.
- **Editar textos:** WhatsApp → 📋 Templates (categoria `followup`).
- **Pendente:** teste de entrega real → ativar as duas chaves. Só depois seguir item 4.

### Guard-rail (negócio)
Risco de banimento do número se a Trilha A parecer spam. Automatizar só **toques 1–3 + ruptura (8)**; **5–6 semi-manuais**; **ligações (A4/A7) sempre manuais**. Configurável por `configuracoes.followup_auto_passos`. Respeitar horário 10–18 seg–sex; parar no 1º "respondeu".

### Textos das Trilhas A e B
**Aprovados** (sem o número "20%"; `{{data_limite}}` fora do B7; B7 com urgência honesta da pensão retroativa). A1 já cadastrado como `zapi_templates`. Demais (A2–A8, B2–B7) prontos para cadastrar quando os itens 2/3 forem implementados.

### Itens de limpeza revelados (vão pro treino/Parte 1)
1. Corrigir o **Script de Objeções** (PDF) que apresenta o 20% como garantido.
2. Definir a **regra de quando oferecer o 20%** (manual, sob aprovação do financeiro).

> Detalhes vivos no projeto: memória `followup-project-status`.