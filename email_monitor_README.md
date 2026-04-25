# Email Monitor — Andamentos PJe

Lê emails do PJe (Gmail/IMAP) e insere automaticamente em `case_andamentos`,
com deduplicação por hash MD5 pra reprocessamento ser idempotente.

## Arquivos entregues (apenas novos)

| Arquivo | Função |
|---|---|
| `email_monitor_cron.php` (raiz) | Script autônomo que conecta no IMAP, parseia emails do PJe, insere andamentos. Funciona via CLI ou HTTP com chave. |
| `modules/email_monitor.php` | Página admin do Hub com histórico de execuções e botão "Rodar agora" (XHR). |
| `email_monitor_README.md` | Este arquivo. |

Nenhum arquivo existente do sistema foi modificado.

## Setup inicial

### 1. Instalar extensão IMAP no servidor (se ainda não tiver)

A extensão `php-imap` precisa estar habilitada na TurboCloud. No cPanel:

1. **MultiPHP INI Editor** → versão PHP 7.4 → habilitar `imap`
2. OU **Selector PHP** → Extensões → marcar `imap`

Pra confirmar, acesse `https://ferreiraesa.com.br/conecta/email_monitor_cron.php?key=fsa-hub-deploy-2026`.
Se aparecer `[imap] Extensão IMAP do PHP não disponível`, ainda não está instalada.

### 2. Conta Gmail e senha de app

- Conta IMAP: `andamentosfes@gmail.com`
- Senha de app: `lbzwljxafdqkhfdp` (16 caracteres, gerada em myaccount.google.com → Segurança → Senhas de app)
- IMAP precisa estar habilitado em Configurações do Gmail → Encaminhamento e POP/IMAP

### 3. Cadastrar Cron Job na TurboCloud

No cPanel → **Cron Jobs** → adicionar:

```
0 8,13,19 * * * curl -s "https://ferreiraesa.com.br/conecta/email_monitor_cron.php?key=fsa-hub-deploy-2026" > /dev/null
```

Isso roda 3× ao dia, exatamente às **08:00**, **13:00** e **19:00** (horário do servidor).

Se preferir CLI direto (mais rápido, dispensa HTTP):

```
0 8,13,19 * * * /usr/local/bin/php /home/ferre315/public_html/conecta/email_monitor_cron.php
```

(ajuste o caminho do `php` conforme o que o cPanel exibir em "Path para PHP".)

### 4. Acessar a página admin

`https://ferreiraesa.com.br/conecta/modules/email_monitor.php`

Apenas usuários com role `admin` podem acessar. A página mostra:

- 4 stats de hoje (lidos, inseridos, duplicatas, erros)
- Histórico das últimas 30 execuções
- Botão **▶ Rodar agora** (dispara via XHR, mostra resultado inline)

## Comportamento detalhado

### Filtro de emails

- Critério IMAP: `UNSEEN FROM "tjrj.pjeadm-LD@tjrj.jus.br"`
- Bloqueio extra: ignora qualquer email cujo remetente tenha `brevosend.com` no domínio
- Após processar (sucesso ou ignorado), o email é marcado como **lido** (`\Seen`) pra não reprocessar

### Parser

Extrai do corpo do email (texto plano OU HTML convertido):

- **CNJ** — formato `0000000-00.0000.0.00.0000` (procura "Número do Processo:" primeiro, fallback regex no texto)
- **Polo Ativo**, **Polo Passivo**, **Órgão** — guardados pra análise mas não usados no INSERT
- **Movimentos** — todas as linhas no formato `DD/MM/AAAA HH:MM - descrição`. Cabeçalhos como "Data - Movimento" são pulados.

Encoding: tenta UTF-8 primeiro, se inválido converte de ISO-8859-1 (PJe às vezes envia assim).

### Inserção

Pra cada movimento:

1. Hash MD5 = `md5(case_id + '|' + data + '|' + hora + '|' + descricao)`
2. SELECT em `case_andamentos.datajud_movimento_id` — se já existe, pula (duplicata)
3. INSERT com:
   - `tipo_origem = 'email_pje'`
   - `tipo = 'movimentacao'`
   - `visivel_cliente = 0` (segurança)
   - `segredo_justica = cases.segredo_justica` (herda do caso)
   - `created_by = 0` (sistema)
   - `datajud_movimento_id = hash`

### Quando o caso não está cadastrado

Se o CNJ do email não bate com nenhum `case_number` em `cases`, o email é marcado como lido e registrado no log como ignorado. Nada é inserido.

### Lock contra execução simultânea

Lock file em `/tmp/email_monitor.lock` (via `flock` LOCK_EX | LOCK_NB). Se duas execuções tentarem rodar ao mesmo tempo, a segunda aborta com mensagem `[lock] Outra execução em andamento`. Isso evita inserts duplicados em caso de cron + clique manual coincidirem.

### Tabela de log

`email_monitor_log` é criada automaticamente pelo próprio script (idempotente, `CREATE TABLE IF NOT EXISTS`). Cada execução registra:

| Coluna | Conteúdo |
|---|---|
| executado_em | timestamp |
| emails_lidos | quantos emails foram processados |
| andamentos_inseridos | quantos foram efetivamente inseridos |
| emails_ignorados | sem CNJ / processo não cadastrado / brevosend |
| duplicatas_ignoradas | hash já existia |
| erros | exceções no INSERT |
| detalhes | até 80 linhas de detalhe (CNJ não cadastrado, erros etc.) |
| modo | `cron` (CLI) ou `manual` (HTTP) |

## Troubleshooting

| Sintoma | Causa provável | Solução |
|---|---|---|
| `[imap] Extensão IMAP do PHP não disponível` | `php-imap` não habilitado | Habilitar via cPanel MultiPHP INI / Selector |
| `Falha conexão IMAP: ...` | Senha de app errada ou IMAP desabilitado no Gmail | Regerar senha de app, confirmar IMAP no Gmail |
| Sempre `lidos=0` | Filtro não bate (remetente diferente, todos já lidos) | Conferir critério `FROM` e estado dos emails |
| Acesso negado HTTP | `?key=` ausente ou errado | Usar `?key=fsa-hub-deploy-2026` ou header `X-Api-Key` |
| Andamentos com `visivel_cliente=0` | Comportamento intencional | Editar manualmente o andamento na pasta do processo se quiser tornar público |

## Segurança

- A chave `fsa-hub-deploy-2026` é a mesma usada por outros endpoints internos do Hub. Ela protege o acesso HTTP, mas a senha de app do Gmail está hardcoded no script — se algum dia a chave vazar, basta regerar a senha do Gmail.
- Recomendado mover a senha de app pra `configuracoes` (tabela do Hub) numa entrega futura, mantendo o script lendo a partir dali.

## Convenções respeitadas

- PHP 7.4 (sem match, str_contains, named args, readonly)
- `array()` em vez de `[]` em todo PHP
- Prepared statements PDO em todas as queries
- `e()` pra escape de output
- `validate_csrf()` / `generate_csrf_token()` na página admin
- `require_login()` + `has_min_role('admin')`
- `layout_start.php` / `layout_end.php`
- `XMLHttpRequest` em vez de `fetch`
- CSRF renovado e enviado em cada XHR (header `X-Csrf-Token`)
- Conexão direta ao banco no script de cron (sem `middleware.php`), reutilizando as constantes de `core/config.php`
- UTF-8 garantido no parser (com fallback ISO-8859-1)
