# CHANGELOG — Ferreira & Sá Hub (Conecta)

Registro de todas as alterações significativas no sistema.

---

## [2026-04-02c] — Interface de Permissões por Usuário

### Adicionado
- **modules/admin/permissoes.php** — Página de gerenciamento de permissões (Admin only)
  - Tabela com todos os usuários ativos (nome, perfil, botão Gerenciar)
  - Modal com grid de todos os módulos, mostrando padrão do perfil e override
  - Override: Liberar (acima do padrão) ou Bloquear (abaixo do padrão)
  - Botão Resetar para padrão do perfil (apaga overrides)
- **modules/admin/permissoes_api.php** — API REST para save/reset
  - GET ?action=get&user_id=X → retorna overrides atuais
  - POST { user_id, overrides } → salva (DELETE + INSERT dos que diferem do padrão)
  - POST { user_id, action: "reset" } → apaga todos os overrides
  - Cria tabela user_permissions automaticamente se não existir
- **Sidebar** — Link "Permissões" no menu Sistema (admin only)

### Arquivos tocados
```
conecta/modules/admin/permissoes.php       (NOVO)
conecta/modules/admin/permissoes_api.php   (NOVO)
conecta/templates/sidebar.php              (ALTERADO — novo item menu)
```

---

## [2026-04-02b] — Fix espelhamento doc_faltante no Drawer (Comercial)

### Problema
Ao mover card para "doc faltante" no Operacional, o card espelhava no Comercial, mas a aba DOCS do drawer não mostrava qual documento faltava e não era possível marcar como recebido.

### Causa raiz
1. `card_api.php` buscava `docs_pendentes` apenas por `case_id`, mas leads no Pipeline muitas vezes têm `linked_case_id = NULL`
2. Mesmo quando o `case_id` era resolvido, não havia fallback por `lead_id` ou `client_id`
3. `resolve_doc` não retornava JSON para chamadas AJAX (fazia redirect)
4. Drawer reabria sempre por `case_id`, perdendo contexto do Pipeline

### Correção
- **card_api.php** — Documentos pendentes agora buscados por `case_id OR lead_id OR client_id` com deduplicação
- **card_api.php** — Lead sem `linked_case_id` agora resolve o caso via `client_id` (fallback)
- **operacional/api.php** — `resolve_doc` agora resolve `case_id` pelo `doc_id` quando não recebe (drawer do Comercial)
- **operacional/api.php** — `resolve_doc` retorna JSON para chamadas AJAX
- **drawer.js** — Envia header `X-Requested-With` + reabre drawer no contexto correto (lead_id se veio do Pipeline)

### Arquivos tocados
```
conecta/modules/shared/card_api.php
conecta/modules/operacional/api.php
conecta/assets/js/drawer.js
```

---

## [2026-04-02] — Sistema de proteção contra regressões

### Adicionado
- **CHANGELOG.md** — Este arquivo, para documentar mudanças antes de cada deploy
- **modules/admin/health.php** — Página de health check (Admin only) que testa:
  - Conexão com banco de dados
  - Autenticação (login funcional)
  - API Anthropic (Claude) responde
  - API Asaas responde
  - Google Drive webhook responde
  - Drawer carrega dados corretos via card_api.php
  - Gatilho "Contrato Assinado" → cria caso no Operacional
  - Espelhamento doc_faltante bilateral (Pipeline ↔ Operacional)
- **deploy_check.php** — Health check pré-deploy (roda antes de atualizar)

### Refatorado
- **core/functions.php** → Quebrado em 5 arquivos por domínio:
  - `core/functions_utils.php` — e(), redirect(), flash, CSRF, sanitização, formatação, URL helpers, criptografia, paginação, audit_log
  - `core/functions_auth.php` — roles, permissões, can_access(), _permission_defaults()
  - `core/functions_notify.php` — notify(), notify_admins(), notify_gestao(), notificar_cliente()
  - `core/functions_cases.php` — find_or_create_client(), get_checklist_template(), generate_case_checklist()
  - `core/functions_pipeline.php` — funções legadas can_view_pipeline(), etc.
- **core/functions.php** mantido como ponto único de inclusão (require_once de todos os sub-arquivos)

### Alterado (Migração Firebase → MySQL)
- **Cadastro Clientes/index.html** — Removido Firebase como destino primário. Agora envia APENAS para Conecta API (`/conecta/publico/api_form.php`). Firebase removido.
- **Calculadora Pensão Alimentícia/index.html** — Removido backup Firebase. Agora envia APENAS para Conecta API.
- **Convivência - formulário/submit.php** — Removido dual-write cURL para Conecta. Agora grava APENAS no Conecta (`form_submissions`) via `process_form_submission()`. Tabela `intake_visitas` deixa de receber novos dados.
- **Gastos Pensão/submit.php** — Removido dual-write cURL para Conecta. Agora grava APENAS no Conecta (`form_submissions`) via `process_form_submission()`. Tabela `pensao_respostas` deixa de receber novos dados.

### Arquivos tocados
```
conecta/CHANGELOG.md                          (NOVO)
conecta/modules/admin/health.php               (NOVO)
conecta/deploy_check.php                       (NOVO)
conecta/core/functions.php                     (REFATORADO — agora carrega sub-arquivos)
conecta/core/functions_utils.php               (NOVO — extraído de functions.php)
conecta/core/functions_auth.php                (NOVO — extraído de functions.php)
conecta/core/functions_notify.php              (NOVO — extraído de functions.php)
conecta/core/functions_cases.php               (NOVO — extraído de functions.php)
conecta/core/functions_pipeline.php            (NOVO — extraído de functions.php)
Cadastro Clientes/index.html                   (ALTERADO — Firebase removido)
Calculadora Pensão Alimentícia/index.html      (ALTERADO — Firebase removido)
Convivência - formulário/submit.php            (ALTERADO — escrita direta no Conecta)
Gastos Pensão/submit.php                       (ALTERADO — escrita direta no Conecta)
```
