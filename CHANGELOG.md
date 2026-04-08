# CHANGELOG — Ferreira & Sá Hub (Conecta)

Registro de todas as alterações significativas no sistema.

---

## [2026-04-07b] — @Menções no Helpdesk

### Adicionado
- **@Menção em comentários** — digitar `@` no campo de mensagem abre autocomplete com todos os usuários ativos
  - Dropdown com avatar (iniciais), nome completo, seleção por teclado (↑↓ Enter) ou clique
  - Menções destacadas visualmente nos comentários (badge azul)
- **Notificação interna** — usuário mencionado recebe notificação no sino ao abrir a plataforma
  - Título: "Menção no chamado #X"
  - Mensagem com preview do texto e link direto
- **E-mail via Brevo** — usuário mencionado recebe e-mail transactional com:
  - Template HTML branded (cabeçalho petrol, preview da mensagem, botão "Ver Chamado")
  - Enviado automaticamente via API Brevo (mesma configuração da Newsletter)

### Arquivos tocados
```
conecta/modules/helpdesk/ver.php   (ALTERADO — autocomplete @menção + highlight)
conecta/modules/helpdesk/api.php   (ALTERADO — parse menções + notify + email Brevo)
```

---

## [2026-04-07] — Kanban PREV (Previdenciário)

### Adicionado
- **modules/prev/index.php** — Kanban PREV com 13 colunas dedicadas ao fluxo previdenciário
  - Colunas: Aguardando Docs, Pasta Apta, Análise INSS, Perícia Médica, Recurso Administrativo, Recurso CRPS/CAJ, Ação Judicial, Sentença, Cumprimento/Precatório, Implantação, Suspenso, Parceria, Cancelado
  - Filtros: responsável, tipo de benefício, comarca, busca livre
  - Cards com badge de tipo de benefício, NB (Número de Benefício), dias na coluna
  - Drag & drop com modais para suspensão, doc faltante e parceria
  - Colaborador vê apenas seus casos
- **modules/prev/api.php** — API do Kanban PREV
  - Movimentação entre colunas com espelhamento bilateral no Pipeline Comercial
  - Doc faltante bilateral (registra documentos_pendentes + espelha no Pipeline)
  - Suspensão com motivo + retorno previsto (bilateral)
  - Cancelamento (admin only) + espelhamento no Pipeline
  - Parceria com seleção de parceiro
  - Criação de caso PREV direto
- **modules/prev/caso_novo.php** — Formulário de novo processo PREV
  - Campo Tipo de Benefício obrigatório (9 tipos: INSS, BPC, LOAS, etc.)
  - Campo Número do Benefício (NB)
  - Autocomplete de clientes, campos judiciais, responsável, Drive
- **Coluna "Kanban PREV"** no Kanban Operacional (cor azul índigo #3B4FA0)
  - Modal para selecionar tipo de benefício ao mover card
  - Card visível no Operacional apenas no mês de envio; depois só no PREV
- **Dashboard** — Cards PREV na aba Operacional
  - Card: Processos PREV ativos
  - Card: Enviados este mês para PREV
  - Tabela: distribuição por tipo de benefício com barras proporcionais
- **Sidebar** — Link "Kanban PREV" visível para todos os perfis
- **migrar_prev.php** — Migração automática dos campos previdenciários
  - kanban_prev, prev_status, prev_enviado_em, prev_mes_envio, prev_ano_envio
  - prev_tipo_beneficio, prev_numero_beneficio + índices
- **deploy2.php** — Migração PREV roda automaticamente no deploy

### Banco de dados
```sql
ALTER TABLE cases ADD COLUMN kanban_prev TINYINT(1) DEFAULT 0;
ALTER TABLE cases ADD COLUMN prev_status VARCHAR(50) DEFAULT NULL;
ALTER TABLE cases ADD COLUMN prev_enviado_em DATETIME DEFAULT NULL;
ALTER TABLE cases ADD COLUMN prev_mes_envio INT DEFAULT NULL;
ALTER TABLE cases ADD COLUMN prev_ano_envio INT DEFAULT NULL;
ALTER TABLE cases ADD COLUMN prev_tipo_beneficio VARCHAR(60) DEFAULT NULL;
ALTER TABLE cases ADD COLUMN prev_numero_beneficio VARCHAR(30) DEFAULT NULL;
```

### Arquivos tocados
```
conecta/modules/prev/index.php             (NOVO)
conecta/modules/prev/api.php               (NOVO)
conecta/modules/prev/caso_novo.php          (NOVO)
conecta/migrar_prev.php                     (NOVO)
conecta/modules/operacional/index.php       (ALTERADO — nova coluna + modal PREV)
conecta/modules/operacional/api.php         (ALTERADO — handler kanban_prev)
conecta/modules/dashboard/index.php         (ALTERADO — KPIs PREV)
conecta/templates/sidebar.php               (ALTERADO — link Kanban PREV)
conecta/deploy2.php                         (ALTERADO — auto-migração)
```

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
