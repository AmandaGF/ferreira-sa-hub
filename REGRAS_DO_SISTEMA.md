# FERREIRA & SA HUB (CONECTA) — REGRAS DO SISTEMA

**Ultima atualizacao:** 03/Abril/2026
**Versao:** 2.0

---

## INDICE

1. [Modulos em Producao](#1-modulos-em-producao)
2. [Fluxo Comercial (Pipeline)](#2-fluxo-comercial-pipeline)
3. [Fluxo Operacional (Casos)](#3-fluxo-operacional-casos)
4. [Espelhamento Bilateral](#4-espelhamento-bilateral-pipeline--operacional)
5. [Kanban de Tarefas](#5-kanban-de-tarefas)
6. [Formularios Publicos](#6-formularios-publicos)
7. [Documentos e Peticoes](#7-documentos-e-peticoes)
8. [Agenda e Google Meet](#8-agenda-e-google-meet)
9. [Financeiro (Asaas)](#9-financeiro-asaas)
10. [Permissoes e Perfis](#10-permissoes-e-perfis)
11. [Notificacoes](#11-notificacoes)
12. [Checklist Automatico](#12-checklist-automatico-de-documentos)
13. [Dashboard e KPIs](#13-dashboard-e-kpis)
14. [Regras do Kanban Operacional](#14-regras-do-kanban-operacional)
15. [Drawer (Card Lateral)](#15-drawer-card-lateral)
16. [Protecao contra Regressoes](#16-protecao-contra-regressoes)
17. [Integrações Externas](#17-integracoes-externas)

---

## 1. MODULOS EM PRODUCAO (26+)

Dashboard (3 abas) | Portal de Links | Helpdesk | Agenda | CRM | Kanban Comercial | Kanban Operacional | Processos Judiciais | Extrajudicial | Pre-Processual | Fabrica de Peticoes | Agenda de Contatos | Financeiro (Asaas) | Kanban de Tarefas | Prazos | Oficios | Alvaras | Parceiros | Documentos | Formularios | Relatorios | Planilha | Mensagens | Notificacoes | Treinamento | Usuarios | Health Check | Permissoes

---

## 2. FLUXO COMERCIAL (PIPELINE)

### 2.1 Etapas do Pipeline (10 colunas)

```
cadastro_preenchido → elaboracao_docs → link_enviados → contrato_assinado
→ agendado_docs → reuniao_cobranca → doc_faltante → pasta_apta
→ cancelado | suspenso | finalizado | perdido | arquivado
```

### 2.2 Gatilho: CONTRATO ASSINADO

Quando o lead e movido para `contrato_assinado`:
1. Cria/encontra cliente no CRM (busca por telefone → email → nome)
2. Cria caso no Operacional com status `aguardando_docs`
3. Gera checklist automatico de documentos (baseado no tipo de acao)
4. Cria pasta no Google Drive (webhook Apps Script)
5. Marca `converted_at` com data/hora
6. Vincula lead ao caso (`linked_case_id`)
7. Notifica gestao
8. Envia notificacao ao cliente: "boas_vindas" (WhatsApp + email)

### 2.3 Gatilho: PASTA APTA

Quando o lead e movido para `pasta_apta`:
- Caso vinculado muda de `aguardando_docs` → `em_elaboracao`
- Notifica gestao
- Envia notificacao ao cliente: "docs_recebidos"

### 2.4 Gatilho: CANCELADO (somente Admin)

- Caso vinculado tambem e cancelado
- Lead marcado com `closed_at`
- Notifica gestao

### 2.5 Gatilho: SUSPENSO (somente Admin)

- Salva etapa anterior em `coluna_antes_suspensao`
- Salva data da suspensao + prazo opcional
- Caso vinculado tambem e suspenso (bilateral)
- Notifica gestao

### 2.6 Gatilho: REATIVAR DO SUSPENSO

- Restaura lead para `coluna_antes_suspensao`
- Restaura caso vinculado para status anterior
- Limpa dados de suspensao
- Notifica gestao

### 2.7 Lead ARQUIVADO (via drawer)

- Status `arquivado` — NAO afeta metrica de perdidos
- Registra quem arquivou e quando (`arquivado_por`, `arquivado_em`)
- Card some do Kanban, dados permanecem

### 2.8 Lead PERDIDO

- Requer motivo (campo `lost_reason`)
- Registrado no historico do pipeline

---

## 3. FLUXO OPERACIONAL (CASOS)

### 3.1 Etapas do Kanban Operacional

```
aguardando_docs → em_elaboracao (Pasta Apta) → em_andamento (Em Execucao)
→ doc_faltante → aguardando_prazo → distribuido
→ parceria_previdenciario | cancelado | suspenso | renunciamos | arquivado
```

### 3.2 Gatilho: DOC FALTANTE (bilateral)

Quando caso e movido para `doc_faltante`:
1. Salva status anterior em `stage_antes_doc_faltante`
2. Cria registro em `documentos_pendentes` com descricao
3. Move lead vinculado para `doc_faltante` no Pipeline
4. Registra no historico do pipeline
5. Notifica gestao
6. Envia notificacao ao cliente: "doc_faltante" com descricao do documento

### 3.3 Gatilho: PROCESSO DISTRIBUIDO

Quando caso e movido para `distribuido`:
- Modal captura: numero do processo, vara, tipo, data, categoria (judicial/extrajudicial)
- Salva todos os dados no caso
- Notifica gestao
- Envia notificacao ao cliente: "processo_distribuido" (se tiver numero)

### 3.4 Gatilho: EM ANDAMENTO (auto-finalizar Pipeline)

Quando caso muda para `em_andamento`:
- Se lead vinculado esta em `pasta_apta` → lead muda para `finalizado`
- Card sai do Pipeline Comercial

### 3.5 Gatilho: RESOLVE_DOC (receber documento)

Quando um documento pendente e marcado como recebido:
1. Marca documento como `recebido` + timestamp + quem recebeu
2. Conta documentos pendentes restantes APENAS pelo `case_id`
3. Se zero pendentes E caso esta em `doc_faltante`:
   - Restaura caso para `stage_antes_doc_faltante`
   - Restaura lead vinculado:
     - Se anterior era `em_andamento` → lead vai para `finalizado`
     - Caso contrario → lead vai para `pasta_apta`
   - Notifica gestao
   - Notifica cliente: "docs_recebidos"

### 3.6 Caso ARQUIVADO (via drawer)

- Status `arquivado` + `closed_at` = hoje
- Card some do Kanban, dados permanecem

---

## 4. ESPELHAMENTO BILATERAL (Pipeline <-> Operacional)

| Acao no Operacional | Reflexo no Pipeline |
|---------------------|---------------------|
| Caso → doc_faltante | Lead → doc_faltante |
| Caso → suspenso | Lead → suspenso |
| Caso → cancelado | Lead → cancelado |
| Caso → em_andamento (se lead em pasta_apta) | Lead → finalizado |
| Docs todos recebidos | Lead → pasta_apta ou finalizado |
| Reativar do suspenso | Lead → coluna_antes_suspensao |

| Acao no Pipeline | Reflexo no Operacional |
|------------------|------------------------|
| Lead → contrato_assinado | Cria caso aguardando_docs |
| Lead → pasta_apta | Caso → em_elaboracao |
| Lead sai de doc_faltante | Caso restaura status anterior |
| Lead → suspenso | Caso → suspenso |
| Lead → cancelado | Caso → cancelado |

**Funcao auxiliar:** `buscarLeadVinculado($pdo, $caseId, $clientId)` — busca por `linked_case_id`, fallback por `client_id`

**Fonte da verdade para resolve_doc:** o documento (`documentos_pendentes.case_id`), nao o que o drawer envia

---

## 5. KANBAN DE TAREFAS

### 5.1 Colunas

| A Fazer | Em Andamento | Aguardando | Concluido |

### 5.2 Tipos de Tarefa

| Tipo | Cor |
|------|-----|
| Peticionar | Roxo (#6366f1) |
| Juntar Documento | Azul (#0ea5e9) |
| Prazo Processual | Vermelho (#dc2626) |
| Oficio | Lilas (#8b5cf6) |
| Acordo / Conciliacao | Verde (#059669) |
| Outros | Cinza (#94a3b8) |

### 5.3 Filtro: so tarefas com `tipo` preenchido

Tarefas sem `tipo` (checklist de documentos) NAO aparecem no Kanban de Tarefas. Aparecem apenas dentro da pasta do processo e no drawer.

### 5.4 Cascade: Prazo Processual

Ao criar tarefa com `tipo=prazo`:
1. Cria `case_tasks` (tarefa no Kanban)
2. Cria `prazos_processuais` (prazo formal)
3. Cria `agenda_eventos` (evento dia todo na agenda)
4. Vincula IDs entre os 3 registros

**Subtipos de prazo:** Contestacao, Replica, Memoriais/Alegacoes Finais, Apelacao, Embargos de Declaracao, Contrarrazoes

**Data de alerta:** padrao = data fatal - 3 dias (configuravel)

### 5.5 Cascade: Concluir Prazo

Ao mover tarefa de prazo para `concluido`:
1. Task → status `concluido` + `completed_at`
2. Prazo → `concluido=1` + `cumprido_em`
3. Evento agenda → status `realizado`

### 5.6 Concluidos: visibilidade por mes

Tarefas concluidas so aparecem no mes da conclusao. Meses anteriores ficam no historico (botao "Ver Historico").

### 5.7 KPIs

- Pendentes (a_fazer + em_andamento)
- Vencidas (due_date < hoje e nao concluidas)
- Concluidas no mes
- Prazos vencendo em 7 dias

---

## 6. FORMULARIOS PUBLICOS

### 6.1 Fluxo Atual (Firebase ELIMINADO)

Todos os 4 formularios gravam APENAS no Conecta via `/conecta/publico/api_form.php`:

| Formulario | Tipo | Cria lead? |
|-----------|------|------------|
| Cadastro Clientes | cadastro_cliente | SIM (stage=cadastro_preenchido) |
| Calculadora Pensao | calculadora_lead | NAO |
| Convivencia | convivencia | NAO |
| Gastos Pensao | gastos_pensao | NAO |

### 6.2 Regra: Anti-duplicacao de clientes

Busca existente por: telefone (ultimos 8 digitos) → email → nome exato. So cria novo se nao encontrar.

### 6.3 Fallback

Convivencia e Gastos Pensao: se Conecta API falhar, gravam na tabela legada como backup.

---

## 7. DOCUMENTOS E PETICOES

### 7.1 Tipos de Documento (templates estaticos, sem IA)

1. Procuracao
2. Contrato de Honorarios
3. Substabelecimento
4. Declaracao de Hipossuficiencia
5. Declaracao de Isencao de IR
6. Declaracao de Residencia
7. Termo de Acordo
8. Peticao de Juntada
9. Peticao de Ciencia
10. Pesquisa PREVJUD
11. **Citacao por WhatsApp** (Art. 246, V, CPC — Lei 14.195/2021)

### 7.2 Enderecamento Padrao

```
JUIZO DA [vara] DA COMARCA DE [comarca]/RJ
```

### 7.3 Pre-preenchimento

Quando vem de um processo (`case_id`), preenche automaticamente:
- Numero do processo, vara, comarca, regional
- Nome do reu (parte_re_nome)
- Tipo de acao (case_type)

### 7.4 Fabrica de Peticoes (com IA)

- Modelo: Claude Sonnet 4.6
- 14 tipos de acao, 12 tipos de peca
- Visual Law (HTML inline, Calibri, cores do escritorio)
- Logo embutido como base64 (funciona no Word)
- Prompt caching (90% economia no input)

---

## 8. AGENDA E GOOGLE MEET

### 8.1 Tipos de Evento

Audiencia | Reuniao com Cliente | Prazo Processual | Onboarding | Reuniao Interna | Mediacao/CEJUSC | Ligacao/Retorno

### 8.2 Google Meet

- Botao "Gerar Meet" no modal da agenda (modalidade=online)
- Chama Google Apps Script (conta reuniaofes@gmail.com)
- Cria evento no Google Calendar + link do Meet
- Responsavel ja recebe na agenda pessoal

### 8.3 Enviar Convite

- Botao "Enviar Convite" na lista diaria (so se tem Google Event)
- Modal com checkboxes dos colaboradores + campo emails extras
- Cada selecionado recebe evento na agenda pessoal do Google

### 8.4 Botoes no Processo

- **Criar Tarefa** → abre Kanban Tarefas com processo pre-selecionado
- **Agendar Audiencia** → abre Agenda com tipo=audiencia, case_id, client_id
- **Reuniao + Meet** → abre Agenda com tipo=reuniao, modalidade=online

### 8.5 Balcao Virtual

Botao no topo da Agenda → abre https://www.tjrj.jus.br/web/guest/balcao-virtual

### 8.6 Lembretes Automaticos (cron horario)

| Quando | O que | Para quem |
|--------|-------|-----------|
| 24h antes | Notificacao portal | Responsavel |
| 2h antes | Notificacao portal + WhatsApp cliente | Responsavel + cliente |
| prazo_alerta <= amanha | Alerta de prazo | Todos admin + gestao + operacional |

---

## 9. FINANCEIRO (ASAAS)

- Integracao via API Asaas (sandbox ou producao)
- Vincular cliente por CPF
- Criar cobranca (unica ou recorrente)
- Criar assinatura mensal
- Webhook para receber status de pagamento
- Sync de cobrancas localmente
- KPIs: previsto x recebido, inadimplentes

---

## 10. PERMISSOES E PERFIS

### 10.1 Hierarquia de Perfis

| Perfil | Nivel | Descricao |
|--------|-------|-----------|
| admin | 5 | Acesso total |
| gestao | 4 | Gestao (quase tudo) |
| comercial | 3 | Equipe comercial |
| cx | 3 | Customer experience |
| operacional | 3 | Equipe operacional |
| estagiario | 2 | Acesso limitado |
| colaborador | 1 | So ve o que e atribuido a ele |

### 10.2 Matriz de Acesso Padrao

| Modulo | admin | gestao | comercial | cx | operacional | estagiario | colaborador |
|--------|:-----:|:------:|:---------:|:--:|:-----------:|:----------:|:-----------:|
| Dashboard Geral | x | x | x | x | x | x | x |
| Dashboard Comercial | x | x | x | x | | | |
| Dashboard Operacional | x | x | | | x | | |
| CRM | x | x | x | x | | | |
| Pipeline Comercial | x | x | x | x | | | |
| Mover Pipeline (Comercial) | x | x | x | | | | |
| Mover Pipeline (CX) | x | x | | x | | | |
| Kanban Operacional | x | x | x | x | x | | |
| Mover Operacional | x | x | | | x | | |
| Processos | x | x | x | x | x | | |
| Faturamento (R$) | x | | | | | | |
| Formularios | x | x | | | | | |
| Relatorios | x | x | | | | | |
| Usuarios | x | | | | | | |

### 10.3 Overrides Individuais

- Admin pode liberar ou bloquear modulos por usuario (UI em Permissoes)
- Override tem prioridade sobre o padrao do perfil
- Funcao `can_access($module)`: admin → override → default
- Funcao `require_access($module)`: middleware que bloqueia com 403

---

## 11. NOTIFICACOES

### 11.1 Internas (sino)

- `notify()` → para um usuario especifico
- `notify_gestao()` → para todos admin + gestao
- `notify_admins()` → para todos admin
- Tipos: info, alerta, urgencia, pendencia, sucesso

### 11.2 Para Clientes (WhatsApp + Email)

Templates configuraveis em `notificacao_config`:

| Tipo | Quando |
|------|--------|
| boas_vindas | Contrato assinado |
| docs_recebidos | Pasta apta / docs resolvidos |
| processo_distribuido | Processo recebe numero |
| doc_faltante | Documento solicitado |

Variaveis: [Nome], [link_drive], [numero_processo], [vara_juizo], [tipo_acao], [descricao_documento]

---

## 12. CHECKLIST AUTOMATICO DE DOCUMENTOS

Gerado automaticamente ao criar caso (via `generate_case_checklist`).

**Documentos basicos (todos os tipos):**
- Documento de identidade (RG/CNH)
- CPF
- Comprovante de residencia atualizado
- Procuracao assinada
- Contrato de honorarios assinado

**Documentos por tipo de acao:**

| Tipo | Documentos adicionais |
|------|----------------------|
| Alimentos/Pensao | Certidao nascimento menor, comprovante renda alimentante/alimentado, despesas menor, IR, certidao casamento |
| Divorcio | Certidao casamento, pacto antenupcial, certidoes filhos, relacao bens, escrituras, CRLV, extratos, IR |
| Guarda | Certidao menor, matricula escolar, laudo medico, relatorio escolar, fotos convivio |
| Inventario | Certidao obito, casamento falecido, certidoes herdeiros, testamento, matriculas, CRLV, extratos, IR, CND, guia ITD |
| Consumidor | Nota fiscal, contrato, prints conversas, fotos defeito, protocolo SAC, comprovante pagamento |
| Trabalhista | CTPS, contrato trabalho, holerites, TRCT, guias FGTS, aviso previo |

**Checklist aparece:**
- Na pasta do processo (caso_ver.php) — com toggle
- No drawer aba Comercial — com toggle (para CX cobrar docs)
- No drawer aba Operacional — com toggle
- NAO aparece no Kanban de Tarefas (filtrado por tipo IS NOT NULL)

---

## 13. DASHBOARD E KPIs

### 13.1 Aba Geral

- Total clientes, leads no mes, contratos no mes
- Faturamento (admin ve R$, comercial ve so %)
- Ticket medio
- Comparativo mes anterior com % diferenca

### 13.2 Aba Comercial

- Pipeline por etapa (contagem)
- Ranking tipos de acao (conversoes)
- Taxa de conversao (6 meses)
- Cancelamentos no mes
- Atividades recentes (linguagem humana)

### 13.3 Aba Operacional

- Em andamento, suspensos, doc faltante, prazos 7 dias
- Distribuidos no mes, finalizados no mes, pastas aptas
- **Processos por Tipo de Acao** (tabela com barra proporcional)
- Prazos processuais vencendo
- Sem movimentacao ha 30+ dias
- Carga por responsavel

---

## 14. REGRAS DO KANBAN OPERACIONAL

### 14.1 Visibilidade por Mes

- **Distribuidos:** so aparecem no Kanban no mes da distribuicao. Depois, so na listagem de Processos.
- **Cancelados:** so aparecem no Kanban no mes do cancelamento.

### 14.2 Colaborador

- Colaborador ve apenas casos onde `responsible_user_id` = seu ID
- Gestao+ ve todos

### 14.3 Ordenacao

- Prioridade: urgente > alta > normal > baixa
- Depois por prazo (ASC) e data de criacao (DESC)

---

## 15. DRAWER (CARD LATERAL)

### 15.1 Abas

| Aba | Conteudo | Condicao |
|-----|----------|----------|
| Geral | Dados do cliente, status, formulario, comentarios | Sempre |
| Comercial | Contrato, checklist docs (toggle), historico pipeline | `can_comercial` |
| Operacional | Processo, checklist docs (toggle), tarefas, andamentos | Se caso existe |
| Docs | Documentos pendentes/recebidos + botao "Recebido" | Se caso existe |
| Agenda | Compromissos agendados | Sempre |
| Historico | Timeline unificada | Sempre |

### 15.2 Edicao Inline

Campos editaveis com icone de lapis. Whitelist por entidade (client, lead, case, task).

### 15.3 Comentarios

Adicionar, listar, excluir (dono ou gestao+). Vinculados ao client_id.

### 15.4 Botao Excluir (no header)

- Remove do fluxo (NAO apaga dados)
- Lead → status `arquivado` (nao afeta metrica de perdidos)
- Caso → status `arquivado`

---

## 16. PROTECAO CONTRA REGRESSOES

### 16.1 CHANGELOG.md

Obrigatorio atualizar antes de cada deploy com:
- O que vai mudar
- Quais arquivos serao tocados

### 16.2 Health Check (modules/admin/health.php)

8 testes automaticos:
1. Conexao com banco + tabelas essenciais
2. Sistema de autenticacao (admin ativo, bcrypt, sessao)
3. API Anthropic (Claude) responde
4. API Asaas responde
5. Google Drive webhook responde
6. Drawer carrega dados (card_api.php)
7. Gatilho contrato_assinado → cria caso
8. Espelhamento doc_faltante bilateral

### 16.3 Pre-deploy Check (deploy_check.php)

- Via CLI: `php deploy_check.php`
- Via HTTP: `?key=fsa-hub-deploy-2026`
- Testa: banco, 22 arquivos criticos, 22 funcoes essenciais, gatilhos, espelhamento, integridade de dados
- Bloqueia deploy se falhar

### 16.4 functions.php dividido em 5 arquivos

| Arquivo | Responsabilidade |
|---------|-----------------|
| functions_utils.php | e(), redirect(), flash, CSRF, sanitizacao, formatacao, URL, criptografia, paginacao, audit_log |
| functions_auth.php | roles, permissoes, can_access(), _permission_defaults() |
| functions_notify.php | notify(), notify_admins(), notify_gestao(), notificar_cliente() |
| functions_cases.php | find_or_create_client(), get_checklist_template(), generate_case_checklist() |
| functions_pipeline.php | can_view_pipeline(), can_move_operacional(), etc. |

---

## 17. INTEGRACOES EXTERNAS

| Servico | Uso | Tipo |
|---------|-----|------|
| **Google Calendar** | Criar eventos + Meet + convites | Apps Script webhook (reuniaofes@gmail.com) |
| **Google Drive** | Criar pastas ao assinar contrato | Apps Script webhook |
| **Asaas** | Cobrancas, assinaturas, webhook pagamentos | API REST (sandbox/producao) |
| **Claude AI** | Fabrica de Peticoes (14 tipos acao, 12 pecas) | API Anthropic (claude-sonnet-4-6) |
| **ViaCEP** | Busca endereco por CEP | API publica |
| **ReceitaWS** | Busca empresa por CNPJ | API publica |
| **IBGE** | Lista cidades por UF | API publica |
| **LegalOne** | Importar andamentos processuais | CSV manual |

---

## TABELA RESUMO: GATILHOS E CASCADES

| Gatilho | O que acontece | Bilateral? |
|---------|----------------|:----------:|
| Lead → contrato_assinado | Cria caso + checklist + Drive + notifica | Sim |
| Lead → pasta_apta | Caso → em_elaboracao + notifica cliente | Sim |
| Lead → cancelado | Caso → cancelado | Sim |
| Lead → suspenso | Caso → suspenso (com memoria) | Sim |
| Caso → doc_faltante | Lead → doc_faltante + cria doc pendente | Sim |
| Caso → distribuido | Salva dados processo + notifica | Nao |
| Caso → em_andamento | Lead → finalizado (se pasta_apta) | Sim |
| Doc recebido (todos) | Caso + lead restauram status anterior | Sim |
| Tarefa prazo criada | Cria prazo_processual + evento agenda | Cascade |
| Tarefa prazo concluida | Prazo cumprido + evento realizado | Cascade |
| Formulario preenchido | Cria cliente + lead (se cadastro) | Auto |
| Evento 24h antes | Notificacao portal | Cron |
| Evento 2h antes | Portal + WhatsApp cliente | Cron |
| Prazo alerta vencendo | Notifica admin + gestao + operacional | Cron |

---

*Documento gerado em 03/04/2026. Manter atualizado a cada nova regra implementada.*
