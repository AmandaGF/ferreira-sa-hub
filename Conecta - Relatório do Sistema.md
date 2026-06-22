# Conecta — Relatório do Sistema (referência)

Tags: #conecta #referencia
Atualizado: 21/06/2026 · PHP 7.4 + MySQL, sem framework · TurboCloud (LiteSpeed) em `/conecta/`

Hub interno do **Ferreira & Sá Advocacia**. 47 pastas de módulos + núcleo, automações (cron), APIs/webhooks e formulários públicos. PWA (instalável, push). Deploy via GitHub + `deploy2.php`. Testes em produção (sem staging).

## Acesso & Segurança
- Login/registro ([[auth]]), com aprovação por admin, **2FA (TOTP)** opcional.
- **7 perfis:** admin > gestao > comercial = cx = operacional > estagiario > colaborador.
- Permissões por role + override individual (`user_permissions`); middleware retorna JSON 401/403 em AJAX; heartbeat mantém sessão + CSRF.
- **Códigos 2FA** — cofre TOTP de 12+ sistemas (eproc, PJe…), com auditoria.

## Gestão & Dashboards
- **Dashboard** (3 abas: Geral / Comercial / Operacional), metas editáveis.
- **Executivo** (30/60/90 dias), **Painel do Dia** (agenda+tarefas+prazos+briefing IA + [[Painel - Dopamina|bloco de dopamina]]), **Relatórios** (4 abas + export), **Gamificação** (ranking mensal).

## Comercial — Pipeline
- **Pipeline** (Kanban 10 estágios). Gatilho "contrato_assinado" → cria cliente+caso, pasta Drive, checklist, boas-vindas. Cancelamento exige motivo.
- **CRM** (relacionamento de quem preencheu formulário).

## Operacional / Jurídico
- **Operacional** (Kanban de casos, 12 colunas), **Processos** (lista CNJ), **PREV** (Kanban previdenciário), **Pré-Processual**, **Serviços/Extrajudicial**, **Prazos**, **Tarefas** (Kanban), **Ofícios**, **Alvarás**.

## Intimações & DJen (IA)
- **Central de Intimações** — robô "Claudin" (cron 08h/19h) puxa DJen, resume com Claude Haiku, casa CNJ→pasta (ou vira órfã).

## Documentos & Petições (IA)
- **Fábrica de Petições** (Claude Opus, revisão Sonnet, Visual Law), **Documentos** (templates), **Planilha de Débito** (extrai de PDF/imagem via IA).

## Financeiro & Cobrança
- **Financeiro** (Asaas: previsto×recebido, inadimplência), **Cobrança de Honorários** (Kanban, multa 20%+juros).

## Comunicação & Atendimento
- **WhatsApp** (Inbox Z-API canais 21/24, etiquetas, delegação, fluxos, bot IA), **Redes Sociais** (IG/FB via Meta), **Mensagens** (templates), **Newsletter** (Brevo), **Helpdesk** (chamados), **Central VIP/salavip** (portal do cliente: GED, threads), **Notificações**. *(Ligações = pasta vazia.)*

## Clientes, Equipe & Ferramentas
- **Clientes**, **Parceiros**, **Formulários**.
- **Usuários**, **Treinamento** (23 módulos), **Onboarding**, **Aniversários**.
- **Agenda** (11 tipos + Meet), **Notas**, **Wiki**, **Portal** (links/senhas), **Planilha**.
- **Admin** (31 sub-páginas: permissões, DataJud, Asaas, diag WhatsApp, custo IA, Claudin, seguro de vida, reconciliação, comemoração de contrato, health check).
- **Monitor de E-mails** (`modules/email_monitor.php`) — cron lê e-mails do PJe e importa andamentos em `case_andamentos`.

## Formulários públicos (`publico/`)
Cadastro de Cliente (`/cadastro`), Curatela, Despesas Mensais, Onboarding, Lead do site; APIs `api_form.php`, `api_cpf.php`, `brevo_webhook.php`.

## Automações (cron)
DJen (`djen_monitor`), `ia_classificar`, DataJud; lembretes/avisos (`agenda_lembretes`, `resumo_semanal_prazos`, `alertas_inatividade`, `cliente_esfriando`); financeiro (`asaas_sync_mensal`, `cobranca_honorarios`); WhatsApp/Z-API (`zapi_fluxo_tick`, `zapi_aniversarios`, `zapi_health_check`, `wa_saude_check`, `wa_backup_arquivos`, `wa_lid_refresh`); `reconciliar_kanbans`.

## APIs & Webhooks (`api/`)
`zapi_webhook`, `meta_webhook`, `djen_ingest`, `datajud_sync/cron`, `busca_global`, `buscar_documento`, `heartbeat`, `favoritos`, `push_subscribe`.

## Integrações externas
Google Apps Script/Drive · Z-API (2 instâncias) · Anthropic Claude (Haiku bot/DJen, Opus/Sonnet petições) · Brevo · Asaas · DataJud + DJen · Meta Graph API.

## ❤️ Fluxo bilateral Pipeline ↔ Operacional
Contrato assinado → cria caso+pasta+checklist. Pasta apta → "em elaboração". Doc faltante (qualquer lado) → espelha + avisa cliente. Cancelado/Suspenso/Distribuído → espelham status.

> Regras de negócio completas: `REGRAS_DO_SISTEMA.md`. Instruções de dev: `CLAUDE.md`.
