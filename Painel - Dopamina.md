# Painel do Dia — Bloco de Dopamina

Tags: #conecta #painel #gamificacao
Arquivo: `modules/painel/index.php`

Bloco motivacional no topo do **Painel do Dia** que mostra, por usuário, **o que foi cumprido no dia**. Respeita o seletor de usuário da gestão (conta do `$viewUserId`). Some na impressão.

## As 8 categorias contadas (baixas do dia)
Todas atribuídas a **quem realizou a ação** (não ao responsável nominal), por dia:

| Categoria | Fonte / atribuição |
|---|---|
| ✅ Tarefas | `case_tasks` status=`concluido`, `assigned_to`, `completed_at` |
| ⚖️ Prazos | `prazos_processuais` `concluido=1`, `usuario_id`, `concluido_em` |
| 📅 Compromissos | `audit_log` `AGENDA_BALCAO_REALIZADO` + `AGENDA_STATUS` "Status: realizado%" (inclui balcão virtual) |
| 🏛️ Distribuições | `audit_log` `processo_distribuido` (petição inicial) |
| 🔄 Movimentações | `audit_log` `ANDAMENTO_CRIADO` (andamentos manuais; **exclui** DataJud/DJen/e-mail) |
| 👋 Leads (21) | conversas distintas no canal **21** com msg enviada pelo user (`zapi_mensagens.enviado_por_id`) |
| 💬 Clientes (24) | idem no canal **24** |
| 🎫 Chamados | `audit_log` `ticket_updated` + ticket `resolvido` no dia |

> **Por que audit_log em vários:** as tabelas (tickets, agenda) não têm campo "quem resolveu/baixou"; o `audit_log` registra a ação + usuário + data. Para agenda, contar por `responsavel_id` inflava (eventos tipo `prazo` marcados realizado por sync, com `updated_at` mexido) — por isso migrou para audit_log.

## Componentes visuais
- **Número grande** com contagem animada + **frase que escala** (0 → "primeira baixa…" … 10+ → "máquina!").
- **Barra "📋 Agenda de hoje: X de Y · Z%"** — só itens **datados pra hoje** (tarefas due hoje + prazos hoje + compromissos hoje). Atendimentos/distribuições/movimentações **não** entram no denominador (não são "agendados"). Ao 100% → 🎉 + confete.
- **Gráfico de 7 dias** (CSS, sem biblioteca): barras crescem animadas, **hoje destacado**, recorde em dourado. **Clicável** → popover com a quebra do dia por categoria.
- **Badges:** 🔥 streak (dias seguidos) + 🏆 recorde **all-time** (union das fontes por dia) com "NOVO RECORDE!" ao bater.
- **Confete** (Web Animations API, sem lib) ao fechar 100% — 1×/dia via `localStorage`.
- **Recolher/expandir** — estado em `localStorage` (`pdDopaCollapsed`).

## Decisões de design
- Atendimentos somam no **total** (justiça com o comercial, que faz muito atendimento e poucas tarefas/prazos).
- Leads × Clientes separados **por canal** (21 vs 24) pra não contar dobrado.
- Movimentações = só **manuais** (trabalho da equipe), não as importadas do tribunal.

## Possíveis evoluções
- Detalhe do dia mostrar **os itens** (títulos), não só a contagem.
- Recorde com **data**; streak all-time (hoje a janela do streak é 7 dias).
- Distinguir lead × cliente por **vínculo** (`lead_id`/`client_id`) em vez de canal.
