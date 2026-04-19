# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repositório

Sistema interno completo do escritório **Ferreira & Sá Advocacia** (Hub "Conecta"). PHP 7.4 + MySQL (utf8mb4), sem framework. Hospedado em TurboCloud (LiteSpeed) na raiz `/conecta/` do domínio ferreiraesa.com.br.

Documentação de regras de negócio completa em [REGRAS_DO_SISTEMA.md](REGRAS_DO_SISTEMA.md) (obrigatória consultar antes de mexer em fluxo comercial/operacional).

Histórico de mudanças em [CHANGELOG.md](CHANGELOG.md) — convenção de commits segue esse padrão.

## Deploy & testes

**Não há build, lint, ou suíte de testes.** Desenvolvimento é feito direto em PHP no working dir (que é um clone do repo no Google Drive sincronizado) e publicado via GitHub + script de deploy remoto. Testes são feitos em produção (não há ambiente de staging).

### Fluxo de deploy padrão
1. Commit + push pro GitHub (`main`)
2. Trigger remoto:
   ```
   curl -s "https://ferreiraesa.com.br/conecta/deploy2.php?key=fsa-hub-deploy-2026"
   ```
3. O script baixa o ZIP do GitHub, extrai, preserva `core/config.php` e `deploy2.php`
4. Para mudanças de schema, crie um `migrar_*.php` na raiz e chame via URL:
   ```
   curl -s "https://ferreiraesa.com.br/conecta/migrar_X.php?key=fsa-hub-deploy-2026"
   ```
5. Todos os scripts admin/diag exigem `?key=fsa-hub-deploy-2026`

### Gotchas críticos
- **PHP 7.4** — não use `match()`, `str_contains()`, `never` return type, enums, readonly properties, named args
- **LiteSpeed WAF** bloqueia arquivos chamados literalmente `config.php` fora de `/core/` — renomeie se for endpoint web acessível
- Logs em `/files/*.log` são **bloqueados pelo web server** (403). Pra ler em diagnóstico, crie um `ver_log_X.php` que faz `file_get_contents`
- Quando o deploy não propaga um arquivo (404 após deploy), espere 3–10s e rode deploy de novo (cache do GitHub raw)

## Arquitetura

### Entry points
- `modules/<nome>/index.php` — UI do módulo (26+ módulos)
- `modules/<nome>/api.php` — endpoints AJAX/POST do módulo (CSRF + `require_*`)
- `api/*.php` — webhooks públicos (não exigem sessão): `zapi_webhook.php`, `heartbeat.php`
- `cron/*.php` — scripts pra cron jobs cPanel (key-protected): `zapi_aniversarios.php`, `datajud_cron.php`
- `publico/*.php` — forms públicos (sem login): captura lead do site, formulários

### Core (`core/`)
- `config.php` — credenciais DB, chaves API, URL Apps Script. **Não fica no git** (deploy preserva a versão do servidor). Template tem valores `ALTERAR_*`.
- `database.php` — `db()` factory singleton PDO
- `auth.php` + `middleware.php` — `require_login()`, `require_access($module)`, `require_min_role($role)`. **Middleware já retorna JSON com 401/403 se header X-Requested-With=XMLHttpRequest** — frontends AJAX devem checar response para não sofrer saves silenciosos
- `functions_utils.php` — CSRF (`generate_csrf_token`, `validate_csrf`), `sync_honorarios()` (mantém `honorarios_cents` + `estimated_value_cents` iguais — dashboard usa o segundo), `audit_log()`
- `functions_auth.php` — `_permission_defaults()` mapa role→módulo, `can_access()` (admin → override em `user_permissions` → default)
- `functions_pipeline.php`, `functions_cases.php` — `sync_estimated_value`, `buscarLeadVinculado`, `generate_case_checklist`
- `functions_zapi.php` — WhatsApp: send/delete, parse payload (suporta variantes `image`/`imageMessage`/`isImage`), `zapi_fora_horario()`, `zapi_auto_cfg()`
- `functions_bot_ia.php` — bot WhatsApp: chama Claude Haiku (`ANTHROPIC_API_KEY` em config.php), detecta palavras-gatilho pra transferir pra humano
- `google_drive.php` — `create_drive_folder()` e `upload_file_to_drive()` chamam o **Google Apps Script** em `GOOGLE_APPS_SCRIPT_URL` (mesmo endpoint, ações diferentes: sem `action` = cria pasta; `action=uploadFile` = baixa URL e salva no Drive)
- `functions_notify.php` — `notify($userId)`, `notify_gestao()`, `notify_admins()`; templates cliente em `notificacao_config`

### Integrações externas
- **Google Apps Script** (ID no `GOOGLE_APPS_SCRIPT_URL` do config) — cria pasta do caso no Drive quando lead vai pra `contrato_assinado` E faz upload de arquivos do WhatsApp. O script é editado em script.google.com pelo dono (requer reautorização quando novas permissões como `UrlFetchApp` são adicionadas)
- **Z-API** — 2 instâncias (DDD 21 Comercial, DDD 24 CX/Operacional). Credenciais em `zapi_instancias` (DB) + `configuracoes.zapi_*`. Webhook URL: `/conecta/api/zapi_webhook.php?numero={21|24}`
- **Anthropic Claude** — 2 usos: Haiku (bot WhatsApp DDD 21) + Sonnet (Fábrica de Petições). `ANTHROPIC_API_KEY` em config.php
- **Brevo** — e-mails transacionais (chave em `configuracoes.brevo_api_key`)
- **Asaas** — cobranças/assinaturas (`core/asaas_helper.php`)
- **DataJud** — sync de processos judiciais (`modules/admin/datajud_monitor.php` + `cron/datajud_cron.php`)

### Fluxo bilateral Pipeline ↔ Operacional (CENTRAL do sistema)
Matriz completa em REGRAS_DO_SISTEMA.md seção 4. Sempre que mexer em movimento de lead ou caso, **ler essa seção primeiro**. A função `buscarLeadVinculado($pdo, $caseId, $clientId)` é a fonte da verdade pra achar qual lead está vinculado a um caso (por `linked_case_id` → fallback `client_id`).

### Tabela `cases` — colunas comumente confundidas
- Coluna do título é **`title`**, NÃO `client_title` (esse nome não existe — erro silencioso se usar)
- Pasta do Drive: `drive_folder_url` (texto da URL completa, ID é extraído com regex `/folders\/([\w-]+)/`)
- **`sync_honorarios` e `sync_estimated_value`** — sempre chame ao atualizar valor, pra manter `honorarios_cents` e `estimated_value_cents` espelhados (dashboard usa o segundo)

### Padrão de AJAX/save seguro (crítico)
Em março/abril 2026 houve bug sistêmico de "saves silenciosos": quando sessão expirava, `require_login()` redirecionava pra login (HTML), XHR tratava como 200 OK, JS mostrava ✓ "salvo" mas NADA era gravado. Corrigido via:

1. Middleware: `require_login`/`require_access`/`require_min_role`/`require_role` detectam `HTTP_X_REQUESTED_WITH=XMLHttpRequest` e retornam JSON 401/403
2. `templates/footer.php` carrega **heartbeat** — ping em `/api/heartbeat.php` a cada 4 min mantém sessão viva + atualiza CSRF em formulários abertos + mostra modal "Sessão expirada" em falha
3. Frontends AJAX **devem** parsear resposta e tratar status 401 chamando `window.fsaMostrarSessaoExpirada()`. Ver exemplos em `modules/pipeline/index.php` (`saveCell`) e `modules/whatsapp/index.php`

### Permissões
- 7 roles: admin > gestao > comercial = cx = operacional > estagiario > colaborador
- `_permission_defaults()` em `functions_auth.php` mapeia módulo → roles permitidos
- Override individual: `user_permissions(user_id, module, allowed)`
- Admin sempre passa (bypass)
- `require_access($module)` bloqueia com 403 (JSON se AJAX)

### Tabelas importantes
- `users` — equipe (role, is_active)
- `clients` — base única de clientes (dedup por telefone → email → nome)
- `pipeline_leads` — Kanban Comercial (10 stages: cadastro_preenchido → finalizado/perdido/arquivado); colunas financeiras: `valor_acao` (texto BR) / `honorarios_cents` / `estimated_value_cents` / `forma_pagamento` / `vencimento_parcela` / `exito_percentual`
- `cases` — Kanban Operacional (9 stages: aguardando_docs → arquivado); `client_id` é o cliente principal; partes secundárias em `case_partes`
- `case_tasks` — Kanban de Tarefas (apenas tarefas com `tipo IS NOT NULL` aparecem no Kanban; checklist de docs tem tipo NULL)
- `case_andamentos` — linha do tempo do processo (fonte da verdade pra "último andamento", NÃO `cases.updated_at`)
- `documentos_pendentes` — checklist dinâmico (`case_id`, `documento`, `resolvido`)
- `agenda_eventos` — eventos da agenda (tipos: audiencia, reuniao, prazo, onboarding, etc)
- `audit_log` — histórico de ações
- `birthday_greetings` — controle de parabéns enviado (1× por cliente/ano)
- `document_history` — **backup crítico**: `params_json` tem todos os campos usados na geração de contrato (valor_honorarios, forma_pagamento, dia_vencimento, num_parcelas, etc) — útil pra recuperar valores que se perderam
- `zapi_conversas` / `zapi_mensagens` / `zapi_instancias` / `zapi_templates` / `zapi_etiquetas` / `zapi_conversa_etiquetas` — WhatsApp CRM
- `configuracoes` — chave/valor genérico (brevo_*, zapi_*, meta_*)

### Convenções de commit
Formato usado no histórico: `<tipo>: <descrição curta>` seguido de descrição em pt-BR.
Tipos: `feat:`, `fix:`, `fix CRÍTICO:`, `diag:`, `chore:`, `test:`, `temp:`, `rename:`.
Mensagem detalhada explicando **por quê**, não só o quê.
