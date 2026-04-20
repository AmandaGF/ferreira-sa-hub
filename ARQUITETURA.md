# ARQUITETURA — Ferreira & Sá Hub (Conecta)

> **Sistema interno completo do escritório Ferreira & Sá Advocacia.**
> Stack: PHP 7.4 + MySQL (utf8mb4) sem framework · Hospedado em TurboCloud (LiteSpeed) · Deploy via GitHub → `deploy2.php`
>
> **Banco:** `ferre3151357_conecta` · **URL:** https://ferreiraesa.com.br/conecta/
>
> **Última atualização:** 20/abr/2026

---

## ÍNDICE

1. [Estrutura de arquivos](#1-estrutura-de-arquivos)
2. [Schema do banco de dados](#2-schema-do-banco-de-dados)
3. [Rotas e endpoints](#3-rotas-e-endpoints)
4. [Integrações externas](#4-integrações-externas)
5. [Variáveis de ambiente](#5-variáveis-de-ambiente)
6. [Módulos ativos](#6-módulos-ativos)
7. [Regras de negócio críticas](#7-regras-de-negócio-críticas)

---

## RESUMO QUANTITATIVO

| Categoria | Quantidade |
|---|---|
| Arquivos PHP totais | ~424 |
| Módulos ativos | 38 |
| Tabelas do banco | 40+ |
| Cron jobs | 7 |
| Webhooks públicos (`api/`, `publico/`) | 9 |
| Scripts de migração (`migrar_*.php`) | ~100 |
| Endpoints/actions por módulo | 80+ totais |
| Integrações externas | 11 |
| Perfis de acesso (roles) | 7 |
| Commits desde março/2026 | 420+ |

---

## 1. ESTRUTURA DE ARQUIVOS

### 1.1 Core (`core/`) — fundação do sistema

| Arquivo | Propósito |
|---|---|
| `config.php` | Constantes base: DB, sessão, CSRF, criptografia. Preservado no deploy. |
| `database.php` | `db()` — factory singleton PDO |
| `auth.php` | `current_user()`, `login_user()`, `user_display_name()` |
| `middleware.php` | `require_login()`, `require_access($module)`, `require_role()`, `require_min_role()` — retorna JSON 401/403 se `X-Requested-With=XMLHttpRequest` |
| `functions.php` | Loader único — carrega todos os sub-módulos de funções |
| `functions_utils.php` | `e()`, `redirect()`, `flash_set()`, CSRF, `sync_honorarios()`, `audit_log()`, paginação |
| `functions_auth.php` | `can_access()`, `_permission_defaults()`, whitelists rígidas (`can_access_financeiro()`, `can_access_dashboard()`, `can_delegar_whatsapp()`) |
| `functions_notify.php` | `notify()`, `notify_admins()`, `notify_gestao()`, `notificar_cliente()` |
| `functions_cases.php` | `find_or_create_client()`, `buscarLeadVinculado()`, `get_checklist_template()`, `generate_case_checklist()` |
| `functions_pipeline.php` | `sync_estimated_value()`, `can_view_pipeline()` |
| `functions_prazos.php` | Cálculo e alertas de prazos processuais (CPC 224) |
| `functions_gamificacao.php` | Ranking, pontuação por evento, 8 níveis |
| `functions_datajud.php` | Integração DataJud (sync processual CNJ) |
| `functions_zapi.php` | Helpers Z-API — envio/recebimento, delegação, bloqueios, `zapi_pode_enviar_conversa()`, `zapi_expirar_delegacoes_estale()`, `zapi_eh_grupo()` |
| `functions_cpfcnpj.php` | Validação CPF/CNPJ + cache 4 camadas |
| `functions_bot_ia.php` | Bot Claude Haiku no WhatsApp DDD 21 |
| `functions_groq.php` | Transcrição de áudios Whisper via Groq (free tier) |
| `functions_salavip_email.php` | E-mail transacional Central VIP |
| `form_handler.php` | Handler forms públicos — cria/liga `clients` e `pipeline_leads` |
| `pdf_reader.php` | Leitura de PDFs (planilha de débito Jusfy) |
| `XLSXWriter.php` | Gera .xlsx sem dependência externa |
| `google_drive.php` | `create_drive_folder()`, `upload_file_to_drive()` — via Apps Script |
| `asaas_helper.php` | API Asaas: cobranças, assinaturas, clientes |

### 1.2 Webhooks e endpoints públicos

**`api/*.php`** (acessos web, alguns públicos):

| Arquivo | Propósito | Auth |
|---|---|---|
| `zapi_webhook.php` | Webhook Z-API (recebe msgs WhatsApp, status, fromMe) | Pública |
| `heartbeat.php` | Keep-alive + renova CSRF a cada 4min | Login |
| `buscar_documento.php` | Busca CPF/CNPJ (4 camadas: validação → cache → DB interno → API externa) | Login |
| `datajud_sync.php` | Sync manual de um processo | Login + CSRF |
| `datajud_cron.php` | Trigger cron DataJud | key |

**`publico/*.php`** (sem login, acesso externo):

| Arquivo | Propósito |
|---|---|
| `api_form.php` | Recebe todos os forms públicos (cadastro, calculadora, convivência, gastos, despesas) |
| `api_cpf.php` | Proxy público consulta CPF |
| `brevo_webhook.php` | Eventos de e-mail (opened, clicked, unsubscribed) |
| `descadastro.php` | Descadastro de newsletter |

### 1.3 Cron jobs (`cron/*.php`) — key-protected

| Arquivo | Frequência esperada | Função |
|---|---|---|
| `alertas_inatividade.php` | Diário 07h | Marca clientes 30d+ sem contato |
| `agenda_lembretes.php` | Horário | Envia lembretes 24h/2h antes de eventos |
| `resumo_semanal_prazos.php` | Seg 07h | E-mail com prazos da semana |
| `reconciliar_kanbans.php` | Diário 06h | Detecta divergências Pipeline↔Operacional |
| `cobranca_honorarios.php` | Diário 08h | Cobranças 90d+ vencidas viram entrada automática |
| `zapi_aniversarios.php` | Diário 09h | WhatsApp aniversariantes (1×/cliente/ano) |
| `zapi_health_check.php` | Diário 06h | Revalida webhooks Z-API + `notify-sent-by-me` |

### 1.4 Templates (`templates/`)

| Arquivo | Propósito |
|---|---|
| `layout_start.php` | Abertura HTML, imports CSS/JS, banners globais (prazos urgentes, Asaas token expirando) |
| `layout_end.php` | Fechamento + heartbeat + scripts |
| `header.php` | Topbar fixo: logo, breadcrumb, notificações, busca global |
| `sidebar.php` | Menu lateral colapsável + **barra de busca de menu** + favoritos fixos |
| `footer.php` | Rodapé, carrega heartbeat |

### 1.5 Assets

- **`assets/js/`**: `conecta.js` (geral), `helpers.js`, `drawer.js` (card lateral), `gamificacao-efeitos.js`, `ibge_cidades.js`, `busca_cpf.js`, `wa_sender.js`
- **`assets/css/conecta.css`** — folha principal
- **`assets/img/`** — logos

### 1.6 Módulos (`modules/`) — 38 diretórios

Cada módulo tem tipicamente: `index.php` (UI), `api.php` (endpoints AJAX/POST), opcionalmente `form.php`, `ver.php`, `novo.php`, arquivos auxiliares. Ver seção 6.

### 1.7 Raiz do projeto — scripts operacionais

| Arquivo | Propósito |
|---|---|
| `deploy2.php` | Recebe trigger HTTP, faz pull do GitHub, preserva `config.php` e `deploy2.php` |
| `migrar_*.php` (~100) | Migrações one-shot, key-protected |
| `ver_log_*.php` | Leitura de logs (`/files/*.log` são bloqueados pelo web server) |
| `check_*.php`, `debug_*.php`, `test_*.php` | Diagnósticos ad-hoc (podem ser removidos) |
| `CLAUDE.md`, `REGRAS_DO_SISTEMA.md`, `CHANGELOG.md` | Documentação para desenvolvimento |
| `.htaccess` | Headers de segurança (Permissions-Policy libera camera/microphone self) |

---

## 2. SCHEMA DO BANCO DE DADOS

Banco: `ferre3151357_conecta` · Engine: InnoDB · Charset: utf8mb4.

> Migrations são "self-heal": colunas novas são adicionadas via `try { $pdo->exec("ALTER TABLE...") }` dentro dos módulos. Scripts `migrar_*.php` na raiz para migrações mais complexas/one-shot.

### 2.1 Core — Identidade e pessoas

**`users`** — equipe do escritório
```
id (PK), name, email (UNIQUE), password_hash, role (admin|gestao|comercial|cx|operacional|estagiario|colaborador),
is_active, phone, setor, wa_display_name (nome curto p/ WhatsApp), last_login_at, created_at, updated_at
```

**`clients`** — base única de clientes (dedup por telefone → email → nome)
```
id, name, cpf, rg, birth_date, email, phone, phone2, address_street, address_city, address_state, address_zip,
profession, marital_status, nacionalidade, client_status (ativo|inativo|cancelou|...), source, foto_path,
asaas_customer_id, asaas_sincronizado, children_json, created_by, created_at, updated_at
```

### 2.2 CRM / Pipeline Comercial

**`pipeline_leads`** — Kanban Comercial
```
id, client_id (FK), linked_case_id, name, phone, email, source, stage (cadastro_preenchido|elaboracao_docs|
link_enviados|contrato_assinado|agendado_docs|reuniao_cobranca|doc_faltante|pasta_apta|cancelado|suspenso|
finalizado|perdido|arquivado), assigned_to, case_type, tipo_especial, estimated_value_cents, valor_acao,
honorarios_cents, exito_percentual, vencimento_parcela, forma_pagamento, urgencia, observacoes, nome_pasta,
pendencias, lost_reason, doc_faltante_motivo, stage_antes_doc_faltante, coluna_antes_suspensao, data_suspensao,
prazo_suspensao, arquivado_por, arquivado_em, converted_at, created_at, updated_at
```

**`pipeline_history`** — auditoria de movimentações
```
id, lead_id (FK), from_stage, to_stage, changed_by, notes, created_at
```

### 2.3 Operacional / Processos

**`cases`** — Kanban Operacional
```
id, client_id, title, case_type, case_number, court, comarca, comarca_uf, regional, sistema_tribunal,
segredo_justica, pro_bono, departamento, category, distribution_date, status (aguardando_docs|em_elaboracao|
em_andamento|doc_faltante|aguardando_prazo|distribuido|parceria_previdenciario|cancelado|suspenso|renunciamos|
arquivado|concluido), priority, parceiro_id, responsible_user_id, drive_folder_url, deadline,
processo_principal_id, tipo_relacao, tipo_vinculo, is_incidental, kanban_oculto, kanban_prev, prev_status,
prev_enviado_em, prev_tipo_beneficio, prev_numero_beneficio, desfecho_processo, desfecho_processo_em,
parte_re_nome, parte_re_cpf_cnpj, filhos_json, notes, closed_at, created_at, updated_at
```

**`case_partes`** — autores/réus/representantes
```
id, case_id, papel (autor|reu|recorrente|recorrido|etc.), tipo_pessoa, nome, cpf, rg, nascimento, profissao,
estado_civil, razao_social, cnpj, nome_fantasia, representante_nome, representante_cpf, email, telefone,
endereco, cidade, uf, cep, client_id, representa_parte_id, observacoes, created_at
```

**`case_andamentos`** — linha do tempo do processo (**fonte da verdade** para "último andamento")
```
id, case_id, data_andamento, tipo (movimentacao|despacho|decisao|sentenca|intimacao|citacao|audiencia|peticao|
certidao|observacao|chamado|publicacao), descricao, visivel_cliente, created_by, created_at
```

**`case_tasks`** — tarefas do Kanban de Tarefas
```
id, case_id, title, tipo (peticionar|juntar_documento|prazo|oficio|acordo|outros — NULL = item de checklist),
status (a_fazer|em_andamento|aguardando|concluido|pendente|feito), due_date, assigned_to, prazo_id,
evento_agenda_id, subtipo_prazo, sort_order, completed_at, created_at
```

**`case_documents`** — petições e docs gerados (IA ou template)
```
id, case_id, client_id, tipo_peca, tipo_acao, titulo, conteudo_html, gerado_por, drive_file_id, drive_file_url,
tokens_input, tokens_output, custo_usd, created_at
```

**`documentos_pendentes`** — checklist dinâmico
```
id, client_id, case_id, lead_id, descricao, solicitado_por, recebido_por, status (pendente|recebido),
solicitado_em, recebido_em
```

**`prazos_processuais`** — prazos formais com alerta
```
id, client_id, case_id, numero_processo, descricao_acao, subtipo_prazo, prazo_fatal, prazo_alerta,
alertado_em, concluido, cumprido_em, usuario_id, task_id, evento_agenda_id, created_at
```

### 2.4 Agenda

**`agenda_eventos`** — todos os compromissos
```
id, titulo, tipo (audiencia|reuniao_cliente|prazo|onboarding|reuniao_interna|mediacao_cejusc|balcao_virtual|
ligacao), modalidade (presencial|online|nao_aplicavel), data_inicio, data_fim, hora_inicio, dia_todo, local,
meet_link, descricao, client_id, case_id, prazo_id, responsavel_id, participantes, google_event_id,
google_calendar_id, lembrete_email, lembrete_whatsapp, lembrete_portal, lembrete_cliente, msg_cliente,
lembrete_1d_enviado, lembrete_2h_enviado, status (agendado|realizado|remarcado|nao_compareceu|cancelado),
visivel_cliente, created_by, created_at, updated_at
```

### 2.5 WhatsApp CRM (Z-API)

**`zapi_instancias`** — credenciais dos 2 canais
```
id, nome, numero, ddd (21|24), instancia_id, token, tipo, ativo, conectado, ultima_verificacao, created_at
```

**`zapi_conversas`** — conversas abertas
```
id, instancia_id, telefone, nome_contato, client_id, lead_id, atendente_id, status, canal (21|24),
ultima_mensagem, ultima_msg_em, nao_lidas, bot_ativo, bot_etapa, delegada, delegada_por, delegada_em,
foto_perfil_url, foto_perfil_atualizada, eh_grupo, created_at, updated_at
```

**`zapi_mensagens`** — todas as trocas
```
id, conversa_id, zapi_message_id, direcao (recebida|enviada), tipo (texto|imagem|documento|audio|video|sticker|
localizacao|contato|outro), conteudo, arquivo_url, arquivo_nome, arquivo_mime, arquivo_tamanho,
arquivo_salvo_drive, drive_file_id, transcricao, enviado_por_id, enviado_por_bot, minha_reacao, reacao_cliente,
lida, entregue, status, created_at
```

**`zapi_templates`** · **`zapi_etiquetas`** · **`zapi_conversa_etiquetas`** · **`zapi_fila_envio`** · **`zapi_stickers`** — auxiliares

### 2.6 Financeiro (Asaas)

**`asaas_cobrancas`** — cobranças individuais
```
id, client_id, contrato_id, case_id, asaas_payment_id (UNIQUE), asaas_customer_id, descricao, valor,
vencimento, status (PENDING|RECEIVED|CONFIRMED|RECEIVED_IN_CASH|OVERDUE|CANCELED|DELETED|REFUNDED|...),
forma_pagamento (BOLETO|PIX|CREDIT_CARD|...), data_pagamento, valor_pago, link_boleto, link_pix, invoice_url,
ultima_sync, created_at
```

**`contratos_financeiros`** — contratos de honorários
```
id, client_id, case_id, tipo_honorario (fixo|exito|misto), valor_total, valor_entrada, num_parcelas,
valor_parcela, dia_vencimento, forma_pagamento, data_fechamento, status (ativo|pausado|cancelado|finalizado),
pct_exito, observacoes, asaas_subscription_id, created_by, created_at, updated_at
```

**`honorarios_cobranca`** — kanban de cobrança (4 etapas)
```
id, client_id, case_id, contrato_id, asaas_payment_id, tipo_debito, valor_total, valor_pago, vencimento,
status (em_dia|atrasado|notificado_1|notificado_2|notificado_extrajudicial|judicial|pago|cancelado),
data_envio_notificacao_1, data_envio_notificacao_2, data_extrajudicial, data_judicial, motivo_judicial,
data_desistencia_cobranca, observacoes, responsavel_cobranca, created_by, created_at, updated_at
```

### 2.7 Central VIP (Portal do Cliente)

**`salavip_usuarios`** — acessos dos clientes
```
id, cliente_id, cpf (UNIQUE), senha_hash, email, nome_exibicao, ativo, token_ativacao, token_expira,
ultimo_acesso, tentativas_login, bloqueado_ate, criado_em, criado_por, atualizado_em
```

**`salavip_mensagens`** · **`salavip_threads`** · **`salavip_ged`** · **`salavip_logs_acesso`** — chat, docs, auditoria

### 2.8 Operacional complementar

- **`oficios_enviados`** — ofícios + AR + rastreio
- **`alvaras`** — cálculo de honorários/repasse
- **`parceiros`** — advogados externos com % de honorários
- **`case_publicacoes`** — publicações de diário oficial (preparada para integração POL/Escavador)

### 2.9 Helpdesk

- **`tickets`** — chamados internos + chamados da Central VIP
- **`ticket_messages`** — histórico com @menções
- **`ticket_attachments`**

### 2.10 Auditoria e sistema

- **`audit_log`** — user_id, action, entity_type, entity_id, details, ip, created_at
- **`notifications`** — sino interno (por user_id)
- **`configuracoes`** — key/value: chaves de API, metas, flags (ex: `zapi_signature_on`, `link_orientacao_audiencia`, `asaas_api_key`, `brevo_api_key`, etc.)
- **`user_permissions`** — override por usuário (acima/abaixo do default do role)
- **`portal_links`** — Portal de Links (credenciais AES-256)
- **`birthday_greetings`** · **`birthday_messages`** — aniversários
- **`form_submissions`** — todos os forms públicos com `payload_json`
- **`document_history`** — histórico de documentos gerados (inclui `params_json` — backup crítico para recuperar valores perdidos)

---

## 3. ROTAS E ENDPOINTS

### 3.1 Entry points

- **UI:** `modules/<nome>/index.php` (26+ módulos)
- **API AJAX/POST:** `modules/<nome>/api.php` (com CSRF + `require_*`)
- **Webhook público:** `api/zapi_webhook.php?numero={21|24}` · `publico/brevo_webhook.php`
- **Cron:** `cron/*.php?key=fsa-hub-deploy-2026`
- **Forms públicos:** `publico/api_form.php` (POST JSON)

### 3.2 Endpoints por módulo (destaques)

| Módulo | Actions principais |
|---|---|
| **crm** | `add_contact`, `add_case`, `update_client_status`, `remove_from_crm`, `delete_client`, `criar_salavip`, `reset_salavip` |
| **pipeline** | `move` (com gatilhos: cria case + Drive folder + notifica), `inline_edit` (whitelist de campos), `duplicate_case` |
| **operacional** | `update_status`, `add_task`, `delete_task`, `toggle_task`, `add_andamento`, `add_prazo` (cascade → tarefa + agenda), `merge_cases`, `vincular_incidental`, `ocultar_kanban` |
| **financeiro** | `criar_cobranca`, `cancelar_cobranca`, `vincular_case`, `importar_asaas_overdue` |
| **whatsapp** | `enviar_mensagem`, `enviar_arquivo`, `enviar_audio`, `enviar_sticker`, `enviar_reacao`, `assumir_atendimento`, `delegar_conversa`, `remover_delegacao`, `mesclar_conversas`, `listar_duplicatas`, `sync_fotos_todas`, `salvar_display_name` |
| **peticoes** | `gerar` (Claude Sonnet 4.6 via Anthropic API) |
| **tarefas** | `listar`, `get`, `create`, `inline_edit`, `delete`, `duplicate`, `move`, `calcular_prazo` |
| **helpdesk** | `update_status`, `update_links`, `add_message` (com @menção) |
| **usuarios** | `toggle_active`, `reset_password`, `approve`, `reject`, `update_permissions` |
| **agenda** | `salvar`, `remarcar`, `remarcar_novo`, `anexar_documento` (balcão virtual) |

### 3.3 Padrão de segurança AJAX

- **CSRF:** token regenerado a cada validação (não consome em reads). Presente em formulários + header X-Requested-With.
- **Middleware:** retorna JSON 401/403 se AJAX (detecta `X-Requested-With=XMLHttpRequest`).
- **Heartbeat** (`api/heartbeat.php`): ping 4min/4min mantém sessão + renova CSRF + modal "Sessão expirada" em falha.

### 3.4 Scripts admin protegidos (`?key=fsa-hub-deploy-2026`)

- `deploy2.php` — trigger de deploy (pull GitHub + extração ZIP)
- `migrar_*.php` — migrações one-shot
- `ver_log_*.php` — leitura de logs bloqueados pelo web server

---

## 4. INTEGRAÇÕES EXTERNAS

11 integrações ativas:

| Integração | Uso | Arquivos principais | Config |
|---|---|---|---|
| **Google Apps Script** | Cria pasta do caso no Drive + upload de arquivos do WhatsApp | `core/google_drive.php` | `GOOGLE_APPS_SCRIPT_URL` no config.php produção |
| **Z-API (WhatsApp)** | 2 instâncias: DDD 21 (Comercial) + DDD 24 (CX/Operacional) | `core/functions_zapi.php`, `api/zapi_webhook.php`, `modules/whatsapp/*` | Tabela `zapi_instancias` + `configuracoes.zapi_*` |
| **Anthropic Claude** | Haiku (bot WhatsApp DDD 21) + Sonnet 4.6 (Fábrica de Petições) | `core/functions_bot_ia.php`, `modules/peticoes/api.php` | `ANTHROPIC_API_KEY` no config.php produção |
| **Groq (Whisper)** | Transcrição automática de áudios (free tier 8h/dia) | `core/functions_groq.php` | `GROQ_API_KEY` em `configuracoes` |
| **Brevo (ex-Sendinblue)** | E-mails transacionais: newsletter, @menções, Central VIP, resumo de prazos | `modules/newsletter/*`, `publico/brevo_webhook.php` | `configuracoes.brevo_api_key` |
| **Asaas** | Cobranças, assinaturas, webhook de pagamento | `core/asaas_helper.php`, `modules/financeiro/*` | `configuracoes.asaas_api_key` (produção: `https://api.asaas.com/v3`) |
| **DataJud (CNJ)** | Sync automático de movimentações | `core/functions_datajud.php`, `cron/datajud_cron.php` | `configuracoes.datajud_token` |
| **cpfcnpj.com.br** | Consulta CPF/CNPJ (paga, fallback) | `core/functions_cpfcnpj.php` | `configuracoes.cpfcnpj_token` |
| **ReceitaWS** | Consulta CNPJ (primária, free) | `core/functions_cpfcnpj.php` | - |
| **ViaCEP** | CEP → endereço | JS `assets/js/busca_cpf.js`, backend `functions_cpfcnpj.php` | - |
| **IBGE (localidades)** | Lista de cidades por UF | JS `assets/js/ibge_cidades.js` | - |

**Google Calendar / Meet** entra via Apps Script (mesma URL), ação `action=gerar_meet`.

---

## 5. VARIÁVEIS DE AMBIENTE

### 5.1 Constantes hardcoded em `core/config.php` (preservado no deploy)

| Chave | Natureza |
|---|---|
| `APP_NAME` | Nome do sistema |
| `APP_VERSION` | Versão |
| `APP_ROOT` | Path raiz (absoluto) |
| `BASE_URL` | Path relativo (ex: `/conecta`) |
| `DB_HOST` | Host MySQL |
| `DB_NAME` | Nome do banco |
| `DB_USER` | Usuário MySQL |
| `DB_PASS` | Senha MySQL (**produção**) |
| `DB_CHARSET` | `utf8mb4` |
| `SESSION_NAME` | `FSA_CONECTA` |
| `SESSION_LIFETIME` | 28800 (8h) |
| `CSRF_TOKEN_NAME` | `csrf_token` |
| `LOGIN_MAX_ATTEMPTS` | 5 |
| `LOGIN_LOCKOUT_MINUTES` | 15 |
| `ENCRYPT_KEY` | AES-256 para credenciais do Portal de Links |
| `ENCRYPT_METHOD` | `aes-256-cbc` |

**Chaves de API externas** (adicionadas em produção — **não** vão pro git):

| Chave | Onde |
|---|---|
| `ANTHROPIC_API_KEY` | `core/config.php` produção (Haiku + Sonnet 4.6) |
| `GOOGLE_APPS_SCRIPT_URL` | `core/config.php` produção (Drive + Meet) |

### 5.2 Chaves em `configuracoes` (tabela — editáveis via UI/DB)

| Chave | Função |
|---|---|
| `asaas_api_key` | Token de produção Asaas (com alerta de expiração 90d) |
| `asaas_api_key_created_at` / `asaas_api_key_expires_at` | Controle de vencimento do token |
| `asaas_webhook_token` | Token do webhook |
| `brevo_api_key` | Chave Brevo |
| `groq_api_key` | Chave Groq (transcrição) |
| `datajud_token` | Chave DataJud |
| `cpfcnpj_token` | Chave cpfcnpj.com.br |
| `zapi_bot_ia_ativo` · `zapi_bot_ia_auto_novas` | Flags do bot IA no DDD 21 |
| `zapi_signature_on` · `zapi_signature_format` | Assinatura automática nas msgs enviadas |
| `zapi_mostrar_nome_interno` | Mostra nome do atendente no chat interno |
| `zapi_fora_horario_*` | Mensagens automáticas fora do horário |
| `link_orientacao_audiencia` | URL de orientação ao cliente antes da audiência |
| `meta_*` | Metas do escritório (dashboard) |

### 5.3 Chave de deploy

- **`fsa-hub-deploy-2026`** — protege `deploy2.php` e todos os `migrar_*.php` / `ver_log_*.php` / crons.

---

## 6. MÓDULOS ATIVOS

38 diretórios em `modules/`. Todos implementados e em produção salvo indicação.

### 6.1 Core operacional

| Módulo | Status | Descrição |
|---|---|---|
| **dashboard** | ✅ whitelist (Amanda/Rodrigo/Luiz) | 3 abas + KPIs gerais. Restrito aos 3 admins. |
| **painel** | ✅ | Painel do Dia (home operacional dos demais usuários) |
| **pipeline** | ✅ | Kanban Comercial 10 etapas + Tabela com filtros (mês, sort, editar inline) |
| **operacional** | ✅ | Kanban Operacional 12 colunas (inclui PREV, doc_faltante, suspenso) |
| **processos** | ✅ | Lista de processos judiciais com incidentais/recursos |
| **pre_processual** | ✅ | Fase de coleta de docs antes de ajuizar |
| **prev** | ✅ | Kanban Previdenciário 13 colunas (9 tipos de benefício) |
| **tarefas** | ✅ | Kanban 4 colunas + cascade com prazos |
| **prazos** | ✅ | Prazos processuais com alertas 7/3/1d + banner |
| **agenda** | ✅ | 8 tipos de evento + Google Meet + Balcão Virtual 11h–17h |

### 6.2 Relacionamento

| Módulo | Status | Descrição |
|---|---|---|
| **crm** | ✅ | Base única, importação CSV, forms públicos |
| **clientes** | ✅ | Ficha completa com filhos, processos, docs pendentes, financeiro |
| **whatsapp** | ✅ | CRM inbox dual (DDD 21 + DDD 24), áudio, transcrição, stickers, reações, delegação |
| **salavip** | ✅ | Central VIP (portal do cliente) |
| **aniversarios** | ✅ | Cron + templates rotacionados |
| **portal** | ✅ | Portal de Links (credenciais criptografadas) |

### 6.3 Documentos e petições

| Módulo | Status | Descrição |
|---|---|---|
| **documentos** | ✅ | 15 templates estáticos (procuração, contrato, citação WhatsApp, etc.) |
| **peticoes** | ✅ | Fábrica com Claude Sonnet 4.6 (14 ações × 12 peças + Visual Law) |
| **oficios** | ✅ | Ofícios enviados + AR + rastreio Correios |
| **alvaras** | ✅ | Alvarás com cálculo de honorários/repasse |
| **formularios** | ✅ | 5 forms públicos (cadastro, calculadora, convivência, gastos, despesas) |

### 6.4 Financeiro

| Módulo | Status | Descrição |
|---|---|---|
| **financeiro** | ✅ whitelist | Asaas completo + visão geral mensal + **cobrancas.php** (todas com filtros) |
| **cobranca_honorarios** | ✅ | Kanban 5 colunas + fluxo 4 etapas (notif.1 → notif.2 → extrajudicial → judicial) |
| **planilha_debito** | ✅ | Upload PDF Jusfy → Claude AI extrai → XLSX |
| **planilha** | ✅ | Visão tipo Excel com filtros e exportação CSV |

### 6.5 Gestão

| Módulo | Status | Descrição |
|---|---|---|
| **relatorios** | ✅ | Relatórios comerciais/operacionais + **WhatsApp relatorio.php** (por período/atendente) |
| **parceiros** | ✅ | Advogados externos + % de honorários |
| **helpdesk** | ✅ | Chamados internos + chamados da Central VIP (SLA por categoria) |
| **notificacoes** | ✅ | Sino + configuração de templates cliente |
| **mensagens** | ✅ | Biblioteca de templates WhatsApp/E-mail |
| **newsletter** | ✅ | 9 templates Brevo + LGPD |
| **usuarios** | ✅ (admin only) | CRUD + aprovação + permissões |
| **admin** | ✅ (admin only) | Health check + DataJud monitor + permissões por usuário + reconciliador |

### 6.6 Complementares

| Módulo | Status | Descrição |
|---|---|---|
| **gamificacao** | ✅ | 11 eventos + 8 níveis + efeitos visuais |
| **treinamento** | ✅ | Materiais internos |
| **wiki** | ✅ | Base de conhecimento |
| **servicos** | ✅ | Produtos/serviços do escritório |
| **shared** | ✅ (interno) | Código compartilhado (drawer, card_api, card_actions) |

### 6.7 Pendências ativas (do progress.md)

- [ ] Filtrar cobrança na pasta do processo por `case_id` (vinculação existe, falta filtro visual)
- [ ] Relatório visual de Despesas Mensais
- [ ] Ativar crons no cPanel (DataJud 07h, etc.)
- [ ] Google Calendar sync bidirecional (fase 2)
- [ ] Integração POL/Escavador (tabela `case_publicacoes` pronta)
- [ ] Criação de tarefas por áudio (Web Speech API + Claude)
- [ ] Ajustar níveis de gamificação (500 pts pode estar alto)

---

## 7. REGRAS DE NEGÓCIO CRÍTICAS

> **Essas regras são a espinha dorsal do sistema. Quebrar qualquer uma delas causa inconsistência generalizada. Sempre consultar [REGRAS_DO_SISTEMA.md](REGRAS_DO_SISTEMA.md) antes de mexer em fluxo comercial/operacional.**

### 7.1 Espelhamento bilateral Pipeline ↔ Operacional

Fonte única de verdade: `buscarLeadVinculado($pdo, $caseId, $clientId)` — busca por `pipeline_leads.linked_case_id` → fallback `client_id`.

| Gatilho no Pipeline | Reflexo no Operacional |
|---|---|
| `contrato_assinado` | Cria case em `aguardando_docs` + pasta no Drive + checklist + notifica gestão + msg "boas_vindas" ao cliente |
| `pasta_apta` | Case → `em_elaboracao` + msg "docs_recebidos" |
| `suspenso` | Case → suspenso (salva `coluna_antes_suspensao`) |
| `cancelado` (admin only) | Case → cancelado |
| Sai de `doc_faltante` | Case restaura `stage_antes_doc_faltante` |

| Gatilho no Operacional | Reflexo no Pipeline |
|---|---|
| `doc_faltante` | Lead → doc_faltante + registra em `documentos_pendentes` + msg "doc_faltante" ao cliente |
| `distribuido` | Notifica gestão + msg "processo_distribuido" (com alerta anti-golpe PIX) |
| `em_andamento` (se lead estava em `pasta_apta`) | Lead → **finalizado** (sai do kanban) |
| `resolve_doc` (0 pendentes) | Case volta a `stage_antes_doc_faltante`, lead vai pra `pasta_apta` ou `finalizado` |
| `suspenso` / `cancelado` | Espelha no lead |

### 7.2 Tabela `cases` — armadilhas comuns

- Coluna do título é **`title`**, NÃO `client_title` (erro silencioso se usar nome errado)
- `sync_honorarios()` e `sync_estimated_value()` — **sempre chamar** ao atualizar valor (mantém `honorarios_cents` e `estimated_value_cents` espelhados; dashboard usa o segundo)
- `drive_folder_url` contém URL completa. ID extraído com regex `/folders\/([\w-]+)/`
- `case_andamentos` é fonte da verdade pra "último andamento" — **NÃO** `cases.updated_at`

### 7.3 AJAX/save seguro

Em março/abril 2026 houve bug sistêmico de **saves silenciosos**: sessão expirava, `require_login()` redirecionava pra login (HTML), XHR tratava como 200 OK, JS mostrava ✓ mas nada era gravado.

**Correção** (aplicada em todo o sistema):

1. Middleware detecta `X-Requested-With=XMLHttpRequest` e retorna **JSON 401/403** em vez de redirect
2. `templates/footer.php` carrega heartbeat (ping 4min) que:
   - Mantém sessão viva
   - Atualiza CSRF em formulários abertos
   - Mostra modal "Sessão expirada" em falha
3. Frontends AJAX **devem** parsear resposta e tratar status 401 → chamar `window.fsaMostrarSessaoExpirada()`

### 7.4 Permissões e whitelists

7 roles: `admin > gestao > comercial = cx = operacional > estagiario > colaborador`

- `_permission_defaults()` mapeia default de cada módulo por role
- `user_permissions(user_id, module, allowed)` — override individual
- `admin` sempre passa (bypass)

**Whitelists rígidas (override acima das roles):**

| Módulo | user_ids autorizados |
|---|---|
| Financeiro (`can_access_financeiro()`) | `[1, 3, 6]` — Amanda, Rodrigo, Luiz Eduardo |
| Dashboard (`can_access_dashboard()`) | `[1, 3, 6]` — idem |
| Delegar WhatsApp (`can_delegar_whatsapp()`) | `[1, 6]` — Amanda, Luiz Eduardo |

### 7.5 WhatsApp — regras do atendimento

- **Canal 21 (Comercial):** trava quando alguém assume. Demais não podem enviar nem assumir enquanto houver atividade nos últimos 30 min. Só Amanda/Luiz podem **delegar**.
- **Canal 24 (CX/Operacional):** colaborativo, **sem trava**. Todos podem enviar/assumir.
- **Expiração automática:** 30min sem mensagem nova na conversa → lock libera automaticamente (lazy check no `listar_conversas` e `assumir_atendimento`).
- **Fallbacks de duplicata** (Multi-Device @lid vs telefone real): match em 4 camadas — telefone exato, últimos 10 dígitos, chatName direto, chatName reverso.
- **Grupos** (`eh_grupo=1`, detectado por `@g.us`): nunca vinculados a cliente. Avatar 👥 fixo.

### 7.6 Asaas — integração financeira

- **URL de produção:** `https://api.asaas.com/v3` (o `/api/v3` retorna 404)
- **Chave:** sem permissão de saque (segurança). Alerta de expiração (90d) visível só pra Amanda
- **Webhook:** `https://ferreiraesa.com.br/conecta/modules/financeiro/webhook.php`
- **Vinculação:** `asaas_cobrancas.case_id` permite uma cobrança por processo específico (evita bagunça com múltiplos processos do mesmo cliente)
- **Cobrança ↔ Proposta:** LEFT JOIN via `BINARY asaas_payment_id` (contorna mix de collations `utf8mb4_general_ci` vs `utf8mb4_unicode_ci`)

### 7.7 Data de Fechamento (Pipeline)

- Coluna "Data Fech." na tabela edita `pipeline_leads.converted_at` (data real do contrato assinado)
- **NÃO** edita `created_at` (preserva histórico de criação do lead)
- Planilha Comercial filtra por `converted_at IS NOT NULL` → lista histórica completa de contratos fechados (nunca somem)
- Kanban Comercial: `pasta_apta` e `cancelado` somem ao virar o mês; demais stages ativos permanecem

### 7.8 Segurança de headers

- **`.htaccess`** seta `Permissions-Policy: camera=(self), microphone=(self), geolocation=()` — `self` permite uso pelo próprio domínio (necessário pra gravar áudio no WhatsApp). `()` vazio bloqueia tudo (cuidado ao alterar).
- **LiteSpeed WAF** bloqueia arquivos chamados `config.php` fora de `/core/` — renomear se for endpoint web acessível.
- **Logs** em `/files/*.log` são bloqueados pelo web server (403). Pra ler, criar `ver_log_X.php`.

### 7.9 PHP 7.4 — limitações

**NÃO use:**
- `match()` (use switch)
- `str_contains()` (use `strpos !== false`)
- `never` return type
- Enums
- Readonly properties
- Named arguments

Spaceship operator `<=>` está OK (PHP 7+).

### 7.10 Deploy

1. Commit + push na branch `main` do GitHub
2. `curl -s "https://ferreiraesa.com.br/conecta/deploy2.php?key=fsa-hub-deploy-2026"`
3. Script baixa ZIP, extrai, **preserva** `core/config.php` e `deploy2.php`
4. Se novo migrar/schema, rodar manualmente: `curl -s "https://ferreiraesa.com.br/conecta/migrar_X.php?key=fsa-hub-deploy-2026"`
5. **Sem build, sem lint, sem testes.** Teste em produção.

---

## CONVENÇÕES DE COMMIT

Formato: `<tipo>: <descrição curta>` + mensagem detalhada em pt-BR explicando o **porquê**.

Tipos: `feat:`, `fix:`, `fix CRÍTICO:`, `diag:`, `chore:`, `test:`, `temp:`, `rename:`, `ux:`, `tweak:`.

---

## REFERÊNCIAS

- [CLAUDE.md](CLAUDE.md) — instruções pra Claude Code operar no repo
- [REGRAS_DO_SISTEMA.md](REGRAS_DO_SISTEMA.md) — regras de negócio detalhadas (17 seções)
- [CHANGELOG.md](CHANGELOG.md) — histórico de sprints
- Obsidian: `F&S Hub — Documentação/` no vault local
