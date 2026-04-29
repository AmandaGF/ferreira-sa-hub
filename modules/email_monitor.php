<?php
/**
 * Email Monitor — interface admin
 *
 * Acessível em: https://ferreiraesa.com.br/conecta/modules/email_monitor.php
 *
 * 3 abas:
 *   1. Histórico — últimas 30 execuções do cron (email_monitor_log)
 *   2. Pendentes — emails com CNJ não cadastrado em `cases` (email_monitor_pendentes)
 *      → ação "Cadastrar" abre caso_novo.php em nova aba (com fallback de clipboard)
 *      → ação "Descartar" muda status='descartado' via XHR
 *   3. Andamentos Importados — últimos 50 inserts feitos pelo cron (case_andamentos)
 *
 * Acesso: apenas admin (require_role('admin')).
 *
 * Padrões obrigatórios:
 *   - middleware.php pra auth
 *   - require_login() + has_min_role()
 *   - validate_csrf() pras ações de mutação (descartar pendente)
 *   - layout_start.php / layout_end.php
 *   - e() pra escape
 *   - array() em vez de []
 *   - XHR em vez de fetch
 *   - closeCursor() após todo execute()/fetchAll()
 */

require_once __DIR__ . '/../core/middleware.php';
require_login();
if (!has_min_role('admin')) {
    flash_set('error', 'Acesso restrito a administradores.');
    redirect(url('modules/painel/'));
}

$pdo  = db();

// ────────────────────────────────────────────────────────────
// Self-heal das tabelas (idempotente — não modifica nada que já exista)
// ────────────────────────────────────────────────────────────
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS email_monitor_log (
        id int unsigned NOT NULL AUTO_INCREMENT,
        executado_em datetime NOT NULL,
        emails_lidos int DEFAULT 0,
        andamentos_inseridos int DEFAULT 0,
        emails_ignorados int DEFAULT 0,
        duplicatas_ignoradas int DEFAULT 0,
        erros int DEFAULT 0,
        detalhes text,
        modo varchar(20) DEFAULT 'cron',
        PRIMARY KEY (id),
        KEY idx_executado_em (executado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS email_monitor_pendentes (
        id int unsigned NOT NULL AUTO_INCREMENT,
        case_number varchar(30) NOT NULL,
        polo_ativo text,
        polo_passivo text,
        orgao varchar(200),
        ultimo_movimento_data date,
        ultimo_movimento_desc text,
        total_emails_recebidos int DEFAULT 1,
        status enum('pendente','descartado','cadastrado') DEFAULT 'pendente',
        primeira_vez datetime NOT NULL,
        ultima_vez datetime NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_case_number (case_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// ────────────────────────────────────────────────────────────
// POST: descartar pendente (XHR JSON)
// ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'descartar_pendente') {
    header('Content-Type: application/json; charset=utf-8');
    // validate_csrf() lê de $_POST['csrf_token'] (CSRF_TOKEN_NAME do config.php)
    if (!validate_csrf()) {
        echo json_encode(array('ok' => false, 'erro' => 'CSRF inválido. Recarregue a página.'));
        exit;
    }
    $idDesc = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($idDesc <= 0) {
        echo json_encode(array('ok' => false, 'erro' => 'ID inválido.'));
        exit;
    }
    try {
        $stmtUpd = $pdo->prepare("UPDATE email_monitor_pendentes SET status = 'descartado' WHERE id = ?");
        $stmtUpd->execute(array($idDesc));
        $afetado = $stmtUpd->rowCount();
        $stmtUpd->closeCursor();
        echo json_encode(array('ok' => true, 'afetado' => (int)$afetado));
    } catch (Throwable $e) {
        echo json_encode(array('ok' => false, 'erro' => $e->getMessage()));
    }
    exit;
}

$csrf = generate_csrf_token();

// ────────────────────────────────────────────────────────────
// Queries das 3 abas
// ────────────────────────────────────────────────────────────
$stmtLogs = $pdo->prepare(
    "SELECT id, executado_em, emails_lidos, andamentos_inseridos,
            emails_ignorados, duplicatas_ignoradas, erros, detalhes, modo
     FROM email_monitor_log
     ORDER BY id DESC
     LIMIT 30"
);
$stmtLogs->execute();
$logs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
$stmtLogs->closeCursor();

$stmtTotaisHoje = $pdo->prepare(
    "SELECT
        COALESCE(SUM(emails_lidos),0)          AS lidos,
        COALESCE(SUM(andamentos_inseridos),0)  AS inseridos,
        COALESCE(SUM(duplicatas_ignoradas),0)  AS dup,
        COALESCE(SUM(erros),0)                 AS erros
     FROM email_monitor_log
     WHERE DATE(executado_em) = CURDATE()"
);
$stmtTotaisHoje->execute();
$rowTotais = $stmtTotaisHoje->fetchAll(PDO::FETCH_ASSOC);
$stmtTotaisHoje->closeCursor();
$totaisHoje = !empty($rowTotais) ? $rowTotais[0] : array('lidos' => 0, 'inseridos' => 0, 'dup' => 0, 'erros' => 0);

// Pendentes: trazemos pendentes + descartados (sem cadastrados — esses já viraram caso).
// JS esconde os descartados por padrão e tem link "mostrar descartados".
$stmtPend = $pdo->prepare(
    "SELECT id, case_number, polo_ativo, polo_passivo, orgao,
            ultimo_movimento_data, ultimo_movimento_desc, total_emails_recebidos,
            status, primeira_vez, ultima_vez
     FROM email_monitor_pendentes
     WHERE status IN ('pendente','descartado')
     ORDER BY (status = 'pendente') DESC, ultima_vez DESC, id DESC"
);
$stmtPend->execute();
$pendentes = $stmtPend->fetchAll(PDO::FETCH_ASSOC);
$stmtPend->closeCursor();

$totalPendentes = 0;
$totalDescartados = 0;
foreach ($pendentes as $p) {
    if ($p['status'] === 'pendente') $totalPendentes++;
    elseif ($p['status'] === 'descartado') $totalDescartados++;
}

// Andamentos importados: feed dos últimos 50 inserts feitos pelo cron.
// Whitelist de ordenação (preview da query string ?ordem=). Default: data_andamento
// DESC (movimentação mais recente primeiro), que é mais útil que "importado em".
$ordensValidas = array(
    'data_desc'      => 'a.data_andamento DESC, a.hora_andamento DESC, a.id DESC',
    'data_asc'       => 'a.data_andamento ASC, a.hora_andamento ASC, a.id ASC',
    'importado_desc' => 'a.created_at DESC, a.id DESC',
    'importado_asc'  => 'a.created_at ASC, a.id ASC',
);
$ordemAndamentos = $_GET['ordem'] ?? 'data_desc';
if (!isset($ordensValidas[$ordemAndamentos])) $ordemAndamentos = 'data_desc';

$stmtAnd = $pdo->prepare(
    "SELECT a.id, a.data_andamento, a.hora_andamento, a.descricao, a.created_at,
            c.id AS case_id, c.title AS case_title, c.case_number
     FROM case_andamentos a
     INNER JOIN cases c ON c.id = a.case_id
     WHERE a.tipo_origem = 'email_pje'
     ORDER BY " . $ordensValidas[$ordemAndamentos] . "
     LIMIT 50"
);
$stmtAnd->execute();
$andamentosImportados = $stmtAnd->fetchAll(PDO::FETCH_ASSOC);
$stmtAnd->closeCursor();

$pageTitle = 'Email Monitor — Andamentos PJe';
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.em-wrap   { max-width: 1200px; margin: 0 auto; }
.em-hdr    { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .8rem; margin-bottom: 1rem; }
.em-hdr h1 { margin: 0; font-size: 1.4rem; color: var(--petrol-900); }
.em-stats  { display: grid; grid-template-columns: repeat(4, 1fr); gap: .8rem; margin-bottom: 1.2rem; }
.em-stat   { background: #fff; border: 1px solid var(--border); border-radius: 10px; padding: .75rem 1rem; }
.em-stat-l { font-size: .68rem; text-transform: uppercase; letter-spacing: .4px; color: var(--text-muted); font-weight: 700; }
.em-stat-v { font-size: 1.4rem; font-weight: 800; color: var(--petrol-900); margin-top: .15rem; }
.em-stat.green   .em-stat-v { color: #15803d; }
.em-stat.amber   .em-stat-v { color: #b45309; }
.em-stat.red     .em-stat-v { color: #b91c1c; }
.em-card   { background: #fff; border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
.em-card-h { padding: .8rem 1rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .5rem; background: #fafafa; }
.em-card-h h3 { margin: 0; font-size: .92rem; color: var(--petrol-900); }
.em-tbl    { width: 100%; border-collapse: collapse; font-size: .82rem; }
.em-tbl th { padding: 8px 12px; text-align: left; font-weight: 700; font-size: .7rem; text-transform: uppercase; letter-spacing: .3px; color: var(--text-muted); border-bottom: 1px solid var(--border); background: #f9fafb; }
.em-tbl td { padding: 8px 12px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
.em-tbl tr:hover td { background: #fafbfc; }
.em-pill   { display: inline-block; padding: 1px 8px; border-radius: 99px; font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; }
.em-pill.cron   { background: #e0f2fe; color: #0369a1; }
.em-pill.manual { background: #fef3c7; color: #b45309; }
.em-pill.descartado { background: #f1f5f9; color: #64748b; }
.em-num.zero { color: #94a3b8; }
.em-num.ok   { color: #15803d; font-weight: 700; }
.em-num.dup  { color: #b45309; font-weight: 700; }
.em-num.err  { color: #b91c1c; font-weight: 700; }
.em-det      { font-family: ui-monospace, monospace; font-size: .72rem; color: var(--text-muted); white-space: pre-wrap; max-height: 120px; overflow: auto; padding: 6px 8px; background: #f8fafc; border-radius: 6px; border: 1px solid #e2e8f0; }
.em-det-toggle { background: none; border: 1px dashed var(--border); border-radius: 5px; padding: 2px 8px; font-size: .68rem; cursor: pointer; color: var(--petrol-900); }
.em-resultado { margin: .8rem 0; padding: .7rem 1rem; border-radius: 8px; display: none; font-family: ui-monospace, monospace; font-size: .8rem; white-space: pre-wrap; }
.em-resultado.show { display: block; }
.em-resultado.ok   { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
.em-resultado.err  { background: #fef2f2; border: 1px solid #fca5a5; color: #b91c1c; }
.em-btn-run  { background: #b87333; color: #fff; border: none; border-radius: 8px; padding: 8px 16px; font-weight: 700; cursor: pointer; font-size: .85rem; }
.em-btn-run:hover { background: #a05c20; }
.em-btn-run:disabled { opacity: .6; cursor: not-allowed; }

/* Tabs */
.em-tabs { display: flex; gap: .25rem; margin-bottom: 1rem; border-bottom: 2px solid var(--border); flex-wrap: wrap; }
.em-tab  { background: none; border: none; padding: .65rem 1.1rem; font-size: .85rem; font-weight: 600; color: var(--text-muted); cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all .15s; }
.em-tab:hover { color: var(--petrol-900); background: #f8fafc; }
.em-tab.active { color: #b87333; border-bottom-color: #b87333; }
.em-tab .em-tab-cnt { display: inline-block; background: #e2e8f0; color: #475569; font-size: .7rem; font-weight: 700; padding: 1px 7px; border-radius: 99px; margin-left: 4px; min-width: 20px; }
.em-tab.active .em-tab-cnt { background: #b87333; color: #fff; }
.em-tab.tab-pendentes .em-tab-cnt[data-cnt-real]:not([data-cnt-real="0"]) { background: #fef3c7; color: #b45309; }
.em-tab.tab-pendentes.active .em-tab-cnt[data-cnt-real]:not([data-cnt-real="0"]) { background: #b45309; color: #fff; }
.em-tab-content { display: none; }
.em-tab-content.active { display: block; }

/* Pendentes — botões de ação */
.em-pend-actions { display: flex; gap: 4px; flex-wrap: wrap; }
.em-btn-cad  { background: #15803d; color: #fff; border: none; border-radius: 5px; padding: 4px 10px; font-size: .72rem; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-block; }
.em-btn-cad:hover { background: #126b32; }
.em-btn-desc { background: #fff; color: #b91c1c; border: 1px solid #fca5a5; border-radius: 5px; padding: 4px 10px; font-size: .72rem; font-weight: 600; cursor: pointer; }
.em-btn-desc:hover { background: #fef2f2; }
.em-pend-row.descartado { opacity: .55; background: #f8fafc; }
.em-pend-row.descartado.hidden { display: none; }
.em-toggle-desc { font-size: .8rem; color: #b87333; cursor: pointer; text-decoration: underline; background: none; border: none; padding: 0; }
.em-cnj { font-family: ui-monospace, monospace; font-size: .78rem; }
.em-mov-desc { max-width: 320px; font-size: .78rem; color: var(--text-muted); }

/* Feed andamentos importados */
.em-and-link { color: #b87333; font-weight: 600; text-decoration: none; }
.em-and-link:hover { text-decoration: underline; }
.em-and-cnj  { font-family: ui-monospace, monospace; font-size: .76rem; color: var(--text-muted); }

/* Botão Recuperar histórico (aba Pendentes) */
.em-btn-recover { background: #0369a1; color: #fff; border: none; border-radius: 6px; padding: 5px 12px; font-size: .74rem; font-weight: 600; cursor: pointer; }
.em-btn-recover:hover { background: #0c4a6e; }
.em-btn-recover:disabled { opacity: .6; cursor: not-allowed; }

/* Caixas de info coloridas (cron sugerido, como funciona) — convertidas pra classes
   pra terem override consistente no modo noturno */
.em-info-box      { margin-top: 1rem; padding: .8rem 1rem; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0; font-size: .78rem; color: var(--text-muted); }
.em-info-box-warn { margin-top: 1rem; padding: .8rem 1rem; background: #fef9e7; border-radius: 8px; border: 1px solid #fde68a; font-size: .78rem; color: #92400e; }
.em-info-box pre  { margin: .4rem 0 0; font-size: .76rem; color: #334155; background: #fff; padding: .5rem; border-radius: 6px; overflow-x: auto; border: 1px solid #e5e7eb; }

@media (max-width: 768px) {
    .em-stats { grid-template-columns: repeat(2, 1fr); }
    .em-mov-desc { max-width: 200px; }
}

/* ────────────────────────────────────────────────────────────
   DARK MODE — overrides explícitos pras classes .em-*
   (a regra global de layout_start cobre só `.card` e `table td` —
   não pega .em-card / .em-stat / .em-tbl etc.)
   ──────────────────────────────────────────────────────────── */
body.dark-mode .em-stat        { background: var(--bg-card) !important; border-color: var(--border) !important; }
body.dark-mode .em-stat-l      { color: var(--text-muted) !important; }
body.dark-mode .em-stat-v      { color: var(--text) !important; }
body.dark-mode .em-stat.green  .em-stat-v { color: #4ade80 !important; }
body.dark-mode .em-stat.amber  .em-stat-v { color: #fbbf24 !important; }
body.dark-mode .em-stat.red    .em-stat-v { color: #f87171 !important; }

body.dark-mode .em-card        { background: var(--bg-card) !important; border-color: var(--border) !important; }
body.dark-mode .em-card-h      { background: var(--bg-secondary) !important; border-color: var(--border) !important; }
body.dark-mode .em-card-h h3   { color: var(--text) !important; }

body.dark-mode .em-tbl th      { background: var(--bg-secondary) !important; color: #cbd5e1 !important; border-color: var(--border) !important; }
body.dark-mode .em-tbl td      { background: var(--bg-card) !important; color: var(--text) !important; border-color: var(--border) !important; }
body.dark-mode .em-tbl tr:hover td { background: rgba(215,171,144,.10) !important; }
body.dark-mode .em-tbl strong  { color: var(--text) !important; }

body.dark-mode .em-pill.cron       { background: rgba(56,189,248,.15) !important; color: #7dd3fc !important; }
body.dark-mode .em-pill.manual     { background: rgba(251,191,36,.15) !important; color: #fcd34d !important; }
body.dark-mode .em-pill.descartado { background: rgba(148,163,184,.18) !important; color: #cbd5e1 !important; }

body.dark-mode .em-num.zero { color: #64748b !important; }
body.dark-mode .em-num.ok   { color: #4ade80 !important; }
body.dark-mode .em-num.dup  { color: #fbbf24 !important; }
body.dark-mode .em-num.err  { color: #f87171 !important; }

body.dark-mode .em-det        { background: var(--bg-secondary) !important; color: var(--text) !important; border-color: var(--border) !important; }
body.dark-mode .em-det-toggle { color: var(--text) !important; border-color: var(--border) !important; }

body.dark-mode .em-resultado.ok  { background: rgba(34,197,94,.10) !important; color: #4ade80 !important; border-color: rgba(34,197,94,.30) !important; }
body.dark-mode .em-resultado.err { background: rgba(239,68,68,.10) !important; color: #f87171 !important; border-color: rgba(239,68,68,.30) !important; }

body.dark-mode .em-tab          { color: var(--text-muted) !important; }
body.dark-mode .em-tab:hover    { color: var(--text) !important; background: rgba(255,255,255,.04) !important; }
body.dark-mode .em-tab.active   { color: #d7ab90 !important; border-bottom-color: #d7ab90 !important; }
body.dark-mode .em-tab .em-tab-cnt { background: rgba(255,255,255,.10) !important; color: #cbd5e1 !important; }
body.dark-mode .em-tab.active .em-tab-cnt { background: #d7ab90 !important; color: #1a1a2e !important; }
body.dark-mode .em-tab.tab-pendentes .em-tab-cnt[data-cnt-real]:not([data-cnt-real="0"]) { background: rgba(251,191,36,.20) !important; color: #fcd34d !important; }
body.dark-mode .em-tab.tab-pendentes.active .em-tab-cnt[data-cnt-real]:not([data-cnt-real="0"]) { background: #fcd34d !important; color: #1a1a2e !important; }

body.dark-mode .em-mov-desc { color: var(--text-muted) !important; }
body.dark-mode .em-cnj      { color: var(--text) !important; }
body.dark-mode .em-and-cnj  { color: var(--text-muted) !important; }
body.dark-mode .em-and-link { color: #d7ab90 !important; }

body.dark-mode .em-pend-row.descartado    { background: rgba(148,163,184,.04) !important; }
body.dark-mode .em-pend-row.descartado td { color: var(--text-muted) !important; }

body.dark-mode .em-btn-cad      { background: #15803d !important; color: #fff !important; }
body.dark-mode .em-btn-cad:hover{ background: #166534 !important; }
body.dark-mode .em-btn-desc     { background: rgba(239,68,68,.10) !important; color: #f87171 !important; border-color: rgba(239,68,68,.30) !important; }
body.dark-mode .em-btn-desc:hover { background: rgba(239,68,68,.20) !important; }

body.dark-mode .em-btn-run      { background: #b87333 !important; color: #fff !important; }
body.dark-mode .em-btn-run:hover{ background: #a05c20 !important; }

body.dark-mode .em-btn-recover     { background: #0369a1 !important; color: #fff !important; }
body.dark-mode .em-btn-recover:hover { background: #075985 !important; }

body.dark-mode .em-toggle-desc { color: #d7ab90 !important; }

body.dark-mode .em-info-box      { background: var(--bg-secondary) !important; border-color: var(--border) !important; color: var(--text-muted) !important; }
body.dark-mode .em-info-box pre  { background: var(--bg-card) !important; color: var(--text) !important; border-color: var(--border) !important; }
body.dark-mode .em-info-box-warn { background: rgba(251,191,36,.08) !important; border-color: rgba(251,191,36,.30) !important; color: #fcd34d !important; }
body.dark-mode .em-info-box-warn code { color: #fcd34d !important; }
body.dark-mode .em-info-box-warn strong { color: #fde68a !important; }

/* Contador "X pendente(s) · Y descartado(s)" no card-header da aba Pendentes */
body.dark-mode .em-pend-row td span[style*="background:#fef3c7"] { background: rgba(251,191,36,.20) !important; color: #fcd34d !important; }
</style>

<div class="em-wrap">

    <div class="em-hdr">
        <h1>📧 Email Monitor — Andamentos PJe</h1>
        <button type="button" class="em-btn-run" id="emBtnRun" onclick="emRodarAgora()">▶ Rodar agora</button>
    </div>

    <div style="font-size: .82rem; color: var(--text-muted); margin-bottom: 1rem;">
        Lê emails não lidos de <code><?= e('tjrj.pjeadm-LD@tjrj.jus.br') ?></code> na conta
        <code><?= e('andamentosfes@gmail.com') ?></code>, parseia movimentos e insere em
        <code>case_andamentos</code> com <code>tipo_origem='email_pje'</code> e deduplicação por hash MD5.
        Cron agendado pra <strong>3× ao dia: 08h, 13h e 19h</strong>.
    </div>

    <div class="em-stats">
        <div class="em-stat">
            <div class="em-stat-l">Hoje · Lidos</div>
            <div class="em-stat-v"><?= (int)$totaisHoje['lidos'] ?></div>
        </div>
        <div class="em-stat green">
            <div class="em-stat-l">Hoje · Inseridos</div>
            <div class="em-stat-v"><?= (int)$totaisHoje['inseridos'] ?></div>
        </div>
        <div class="em-stat amber">
            <div class="em-stat-l">Hoje · Duplicatas</div>
            <div class="em-stat-v"><?= (int)$totaisHoje['dup'] ?></div>
        </div>
        <div class="em-stat red">
            <div class="em-stat-l">Hoje · Erros</div>
            <div class="em-stat-v"><?= (int)$totaisHoje['erros'] ?></div>
        </div>
    </div>

    <div class="em-resultado" id="emResultado"></div>

    <!-- Abas -->
    <div class="em-tabs" role="tablist">
        <button type="button" class="em-tab active" data-tab="historico" onclick="emTabSwitch('historico')">
            Histórico de Execuções
            <span class="em-tab-cnt"><?= count($logs) ?></span>
        </button>
        <button type="button" class="em-tab tab-pendentes" data-tab="pendentes" onclick="emTabSwitch('pendentes')">
            Pendentes de Cadastro
            <span class="em-tab-cnt" data-cnt-real="<?= (int)$totalPendentes ?>"><?= (int)$totalPendentes ?></span>
        </button>
        <button type="button" class="em-tab" data-tab="andamentos" onclick="emTabSwitch('andamentos')">
            Andamentos Importados
            <span class="em-tab-cnt"><?= count($andamentosImportados) ?></span>
        </button>
    </div>

    <!-- ABA 1: HISTÓRICO -->
    <div class="em-tab-content active" id="emTab-historico">
        <div class="em-card">
            <div class="em-card-h">
                <h3>Histórico (últimas 30 execuções)</h3>
                <span style="font-size: .72rem; color: var(--text-muted);"><?= count($logs) ?> registro(s)</span>
            </div>
            <div style="overflow-x: auto;">
                <table class="em-tbl">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Quando</th>
                            <th>Modo</th>
                            <th>Lidos</th>
                            <th>Inseridos</th>
                            <th>Ignorados</th>
                            <th>Dup.</th>
                            <th>Erros</th>
                            <th>Detalhes</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="9" style="text-align:center; color: var(--text-muted); padding: 2rem;">
                            Nenhuma execução registrada ainda. Clique em <strong>Rodar agora</strong> pra testar.
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $row): ?>
                            <?php
                                $modoClasse = ($row['modo'] === 'manual') ? 'manual' : 'cron';
                                $temDet     = !empty(trim((string)$row['detalhes']));
                            ?>
                            <tr>
                                <td><?= (int)$row['id'] ?></td>
                                <td style="white-space: nowrap;"><?= e(date('d/m/Y H:i:s', strtotime($row['executado_em']))) ?></td>
                                <td><span class="em-pill <?= e($modoClasse) ?>"><?= e($row['modo']) ?></span></td>
                                <td><span class="em-num <?= ((int)$row['emails_lidos'] === 0) ? 'zero' : '' ?>"><?= (int)$row['emails_lidos'] ?></span></td>
                                <td><span class="em-num <?= ((int)$row['andamentos_inseridos'] > 0) ? 'ok' : 'zero' ?>"><?= (int)$row['andamentos_inseridos'] ?></span></td>
                                <td><span class="em-num <?= ((int)$row['emails_ignorados'] === 0) ? 'zero' : '' ?>"><?= (int)$row['emails_ignorados'] ?></span></td>
                                <td><span class="em-num <?= ((int)$row['duplicatas_ignoradas'] > 0) ? 'dup' : 'zero' ?>"><?= (int)$row['duplicatas_ignoradas'] ?></span></td>
                                <td><span class="em-num <?= ((int)$row['erros'] > 0) ? 'err' : 'zero' ?>"><?= (int)$row['erros'] ?></span></td>
                                <td>
                                    <?php if ($temDet): ?>
                                        <button type="button" class="em-det-toggle" onclick="emToggleDet(<?= (int)$row['id'] ?>)">ver/ocultar</button>
                                        <div id="emDet<?= (int)$row['id'] ?>" class="em-det" style="display:none; margin-top:6px;"><?= e($row['detalhes']) ?></div>
                                    <?php else: ?>
                                        <span style="color:#cbd5e1;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="em-info-box">
            <strong>Cron sugerido (cPanel TurboCloud):</strong>
            <pre>0 8,13,19 * * * curl -s "https://ferreiraesa.com.br/conecta/email_monitor_cron.php?key=fsa-hub-deploy-2026" > /dev/null</pre>
        </div>
    </div>

    <!-- ABA 2: PENDENTES -->
    <div class="em-tab-content" id="emTab-pendentes">
        <div class="em-card">
            <div class="em-card-h">
                <h3>Pendentes de Cadastro</h3>
                <div style="display:flex; align-items:center; gap:.8rem; flex-wrap:wrap;">
                    <span style="font-size: .72rem; color: var(--text-muted);">
                        <?= (int)$totalPendentes ?> pendente(s)<?php if ($totalDescartados > 0): ?> · <?= (int)$totalDescartados ?> descartado(s)<?php endif; ?>
                    </span>
                    <?php if ($totalDescartados > 0): ?>
                        <button type="button" class="em-toggle-desc" id="emToggleDesc" onclick="emToggleDescartados()">mostrar descartados</button>
                    <?php endif; ?>
                    <button type="button" class="em-btn-recover" id="emBtnRecover" onclick="emRecuperarHistorico()" title="Reprocessa emails antigos do PJe (já lidos) pra encontrar CNJs que entraram antes desta tabela existir. Roda em batches de 30 — clique várias vezes até esvaziar.">
                        🔄 Recuperar histórico
                    </button>
                </div>
            </div>
            <div style="overflow-x: auto;">
                <table class="em-tbl">
                    <thead>
                        <tr>
                            <th>CNJ</th>
                            <th>Polo Ativo × Polo Passivo</th>
                            <th>Órgão</th>
                            <th>Último Movimento</th>
                            <th title="Total de emails recebidos">Emails</th>
                            <th>Primeira vez</th>
                            <th>Última vez</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($pendentes)): ?>
                        <tr><td colspan="8" style="text-align:center; color: var(--text-muted); padding: 2rem;">
                            Nenhum processo pendente. Quando o cron receber email com CNJ que não existe em <code>cases</code>, ele aparece aqui.
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($pendentes as $p): ?>
                            <?php
                                $isDesc      = ($p['status'] === 'descartado');
                                $rowClasses  = 'em-pend-row';
                                if ($isDesc) $rowClasses .= ' descartado hidden';

                                // Monta título sugerido pra pré-preencher o cadastro
                                $tituloSug = trim(($p['polo_ativo'] !== null && $p['polo_ativo'] !== '' ? $p['polo_ativo'] : 'Autor')
                                            . ' x '
                                            . ($p['polo_passivo'] !== null && $p['polo_passivo'] !== '' ? $p['polo_passivo'] : 'Réu'));

                                // URL do form de novo caso. NÃO passa title (Amanda preenche manualmente
                                // pra evitar criar pasta com nome incompatível com a convenção do escritório).
                                // Passa case_number + orgao (caso_novo.php parseia o órgão pra preencher
                                // vara + comarca + UF automaticamente) + from=email_monitor (faz o botão
                                // Voltar do caso_novo apontar pra esta página de novo).
                                $urlCadastrar = url('modules/operacional/caso_novo.php')
                                              . '?case_number=' . rawurlencode($p['case_number'])
                                              . '&orgao='       . rawurlencode((string)($p['orgao'] !== null ? $p['orgao'] : ''))
                                              . '&from=email_monitor';

                                // Polos compactos pra primeira coluna
                                $poloA = ($p['polo_ativo']   !== null && $p['polo_ativo']   !== '') ? $p['polo_ativo']   : '—';
                                $poloP = ($p['polo_passivo'] !== null && $p['polo_passivo'] !== '') ? $p['polo_passivo'] : '—';

                                // Movimento mais recente
                                $movData = !empty($p['ultimo_movimento_data']) ? date('d/m/Y', strtotime($p['ultimo_movimento_data'])) : '—';
                                $movDesc = !empty($p['ultimo_movimento_desc']) ? $p['ultimo_movimento_desc'] : '';
                            ?>
                            <tr class="<?= e($rowClasses) ?>" id="emPendRow<?= (int)$p['id'] ?>">
                                <td>
                                    <span class="em-cnj"><?= e($p['case_number']) ?></span>
                                    <?php if ($isDesc): ?>
                                        <br><span class="em-pill descartado" style="margin-top:3px;">descartado</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:.78rem;">
                                    <div><strong>Ativo:</strong> <?= e($poloA) ?></div>
                                    <div><strong>Passivo:</strong> <?= e($poloP) ?></div>
                                </td>
                                <td style="font-size:.78rem;">
                                    <?= !empty($p['orgao']) ? e($p['orgao']) : '<span style="color:#cbd5e1;">—</span>' ?>
                                </td>
                                <td>
                                    <div style="font-size:.78rem;"><strong><?= e($movData) ?></strong></div>
                                    <?php if ($movDesc !== ''): ?>
                                        <div class="em-mov-desc" title="<?= e($movDesc) ?>">
                                            <?= e(mb_strlen($movDesc) > 90 ? mb_substr($movDesc, 0, 90) . '…' : $movDesc) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <span style="background:#fef3c7;color:#b45309;font-weight:700;padding:2px 8px;border-radius:99px;font-size:.74rem;">
                                        <?= (int)$p['total_emails_recebidos'] ?>
                                    </span>
                                </td>
                                <td style="white-space:nowrap;font-size:.74rem;color:var(--text-muted);"><?= e(date('d/m/Y H:i', strtotime($p['primeira_vez']))) ?></td>
                                <td style="white-space:nowrap;font-size:.74rem;color:var(--text-muted);"><?= e(date('d/m/Y H:i', strtotime($p['ultima_vez']))) ?></td>
                                <td>
                                    <div class="em-pend-actions">
                                        <?php if (!$isDesc): ?>
                                            <a class="em-btn-cad"
                                               href="<?= e($urlCadastrar) ?>"
                                               onclick="emAntesCadastrar();"
                                               title="Abre o cadastro de caso novo (CNJ, vara, comarca, UF e partes vêm pré-preenchidos do email).">
                                                ✚ Cadastrar
                                            </a>
                                            <button type="button" class="em-btn-desc" onclick="emDescartarPendente(<?= (int)$p['id'] ?>)" title="Marca como descartado (não vai mais aparecer)">
                                                ✕ Descartar
                                            </button>
                                        <?php else: ?>
                                            <span style="font-size:.7rem;color:#94a3b8;">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="em-info-box-warn">
            <strong>Como funciona:</strong> quando um email do PJe chega com CNJ que não existe em <code>cases</code>, ele aparece aqui em vez de ser ignorado.
            Clicar em <strong>Cadastrar</strong> abre o form de novo caso em outra aba e copia o CNJ + título sugerido pra área de transferência (cole com <kbd>Ctrl+V</kbd>).
            <strong>Descartar</strong> marca como ignorado (some da lista; pode reaparecer com "mostrar descartados").
            Depois do caso ser criado, novos emails desse CNJ vão direto pra <code>case_andamentos</code> (não voltam pra esta lista).
        </div>
    </div>

    <!-- ABA 3: ANDAMENTOS IMPORTADOS -->
    <div class="em-tab-content" id="emTab-andamentos">
        <div class="em-card">
            <div class="em-card-h">
                <h3>Andamentos Importados (últimos 50)</h3>
                <span style="font-size: .72rem; color: var(--text-muted);">
                    <?= count($andamentosImportados) ?> registro(s) · <code>tipo_origem='email_pje'</code>
                </span>
            </div>
            <div style="overflow-x: auto;">
                <table class="em-tbl">
                    <thead>
                        <?php
                        // Ajuda pra montar link de ordenação alternando ASC/DESC.
                        // Mantém demais query strings (caso futuro tenha filtros).
                        $_qsBase = $_GET; unset($_qsBase['ordem']);
                        $_mkOrd = function($colKey, $label) use ($ordemAndamentos, $_qsBase) {
                            $asc = $colKey . '_asc'; $desc = $colKey . '_desc';
                            $proxima = ($ordemAndamentos === $desc) ? $asc : $desc;
                            $seta = '';
                            if ($ordemAndamentos === $desc) $seta = ' ↓';
                            elseif ($ordemAndamentos === $asc) $seta = ' ↑';
                            $qs = http_build_query(array_merge($_qsBase, array('ordem' => $proxima))) . '#emTab-andamentos';
                            return '<a href="?' . htmlspecialchars($qs) . '" style="color:inherit;text-decoration:none;cursor:pointer">'
                                 . htmlspecialchars($label) . '<span style="color:#B87333;font-weight:700">' . $seta . '</span></a>';
                        };
                        ?>
                        <tr>
                            <th>Caso</th>
                            <th>CNJ</th>
                            <th><?= $_mkOrd('data', 'Data / Hora') ?></th>
                            <th>Descrição</th>
                            <th><?= $_mkOrd('importado', 'Importado em') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($andamentosImportados)): ?>
                        <tr><td colspan="5" style="text-align:center; color: var(--text-muted); padding: 2rem;">
                            Nenhum andamento importado por email ainda.
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($andamentosImportados as $and): ?>
                            <?php
                                $hHora = !empty($and['hora_andamento']) ? substr($and['hora_andamento'], 0, 5) : '';
                                $urlCaso = url('modules/operacional/caso_ver.php') . '?id=' . (int)$and['case_id'];
                            ?>
                            <tr>
                                <td>
                                    <a class="em-and-link" href="<?= e($urlCaso) ?>" target="_blank" rel="noopener">
                                        <?= e($and['case_title'] ?: ('Caso #' . (int)$and['case_id'])) ?>
                                    </a>
                                </td>
                                <td><span class="em-and-cnj"><?= e($and['case_number'] ?: '—') ?></span></td>
                                <td style="white-space:nowrap; font-size:.78rem;">
                                    <strong><?= e(date('d/m/Y', strtotime($and['data_andamento']))) ?></strong>
                                    <?php if ($hHora): ?> <span style="color:#94a3b8;"><?= e($hHora) ?></span><?php endif; ?>
                                </td>
                                <td class="em-mov-desc" title="<?= e($and['descricao']) ?>">
                                    <?= e(mb_strlen($and['descricao']) > 140 ? mb_substr($and['descricao'], 0, 140) . '…' : $and['descricao']) ?>
                                </td>
                                <td style="white-space:nowrap; font-size:.72rem; color:var(--text-muted);">
                                    <?= e(date('d/m/Y H:i', strtotime($and['created_at']))) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script>
(function(){
    var EM_KEY     = 'fsa-hub-deploy-2026';
    var EM_URL     = '<?= url('email_monitor_cron.php') ?>?key=' + encodeURIComponent(EM_KEY);
    var EM_REC_URL = '<?= url('email_monitor_recover.php') ?>?key=' + encodeURIComponent(EM_KEY);
    var EM_CSRF    = '<?= e($csrf) ?>';
    var EM_PAGE    = '<?= url('modules/email_monitor.php') ?>';

    // ──── Tabs ────
    window.emTabSwitch = function(tab) {
        var tabs = document.querySelectorAll('.em-tab');
        var conts = document.querySelectorAll('.em-tab-content');
        for (var i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('active', tabs[i].getAttribute('data-tab') === tab);
        }
        for (var j = 0; j < conts.length; j++) {
            conts[j].classList.toggle('active', conts[j].id === 'emTab-' + tab);
        }
        try { localStorage.setItem('em_active_tab', tab); } catch (e) {}
    };

    // Restaura aba ativa do localStorage
    try {
        var saved = localStorage.getItem('em_active_tab');
        if (saved && document.getElementById('emTab-' + saved)) {
            window.emTabSwitch(saved);
        }
    } catch (e) {}

    // ──── Rodar agora ────
    window.emRodarAgora = function() {
        var btn = document.getElementById('emBtnRun');
        var box = document.getElementById('emResultado');
        if (!btn) return;

        if (!confirm('Disparar leitura manual da caixa de entrada agora?')) return;

        btn.disabled = true;
        var labelOriginal = btn.textContent;
        btn.textContent = 'Executando...';

        box.className = 'em-resultado show';
        box.textContent = 'Conectando ao servidor de email...';

        var xhr = new XMLHttpRequest();
        xhr.open('GET', EM_URL, true);
        xhr.setRequestHeader('X-Csrf-Token', EM_CSRF);
        xhr.setRequestHeader('X-Api-Key', EM_KEY);
        xhr.timeout = 120000; // 2 min
        xhr.onload = function() {
            btn.disabled = false;
            btn.textContent = labelOriginal;
            if (xhr.status >= 200 && xhr.status < 300) {
                box.className = 'em-resultado show ok';
                box.textContent = xhr.responseText || 'Execução concluída.';
                setTimeout(function(){ window.location.reload(); }, 2000);
            } else {
                box.className = 'em-resultado show err';
                box.textContent = 'HTTP ' + xhr.status + '\n' + xhr.responseText;
            }
        };
        xhr.onerror = function() {
            btn.disabled = false;
            btn.textContent = labelOriginal;
            box.className = 'em-resultado show err';
            box.textContent = 'Erro de rede. Verifique a conexão e tente de novo.';
        };
        xhr.ontimeout = function() {
            btn.disabled = false;
            btn.textContent = labelOriginal;
            box.className = 'em-resultado show err';
            box.textContent = 'Timeout (>2 min). O processo pode estar rodando em background — recarregue a página em alguns minutos.';
        };
        xhr.send();
    };

    // ──── Toggle detalhes (histórico) ────
    window.emToggleDet = function(id) {
        var el = document.getElementById('emDet' + id);
        if (!el) return;
        el.style.display = (el.style.display === 'none' || el.style.display === '') ? 'block' : 'none';
    };

    // ──── Mostrar/ocultar descartados ────
    window.emToggleDescartados = function() {
        var rows = document.querySelectorAll('.em-pend-row.descartado');
        var btn  = document.getElementById('emToggleDesc');
        if (!rows.length) return;
        var hidden = rows[0].classList.contains('hidden');
        for (var i = 0; i < rows.length; i++) {
            rows[i].classList.toggle('hidden', !hidden);
        }
        if (btn) btn.textContent = hidden ? 'ocultar descartados' : 'mostrar descartados';
    };

    // ──── Antes de navegar pro caso_novo, garante que a aba Pendentes fique
    //      ativa quando o usuário clicar em "Voltar pra Pendentes" no caso_novo ────
    // (caso_novo abre na MESMA aba, então quando voltar, esta página vai recarregar
    //  e o JS do emTabSwitch lê localStorage pra restaurar a aba ativa)
    window.emAntesCadastrar = function() {
        try { localStorage.setItem('em_active_tab', 'pendentes'); } catch (e) {}
        return true;
    };

    // ──── Recuperar histórico (script one-shot email_monitor_recover.php) ────
    window.emRecuperarHistorico = function() {
        var btn = document.getElementById('emBtnRecover');
        var box = document.getElementById('emResultado');
        if (!btn || !box) return;

        if (!confirm('Reprocessar emails antigos do PJe (já lidos no Gmail) pra encontrar CNJs perdidos?\n\nProcessa 30 emails por vez. Pode ser executado várias vezes — cada execução continua de onde a anterior parou.')) return;

        btn.disabled = true;
        var label0 = btn.textContent;
        btn.textContent = 'Recuperando...';

        box.className = 'em-resultado show';
        box.textContent = 'Conectando ao Gmail e lendo histórico...';

        var xhr = new XMLHttpRequest();
        xhr.open('GET', EM_REC_URL, true);
        xhr.setRequestHeader('X-Csrf-Token', EM_CSRF);
        xhr.setRequestHeader('X-Api-Key',    EM_KEY);
        xhr.timeout = 120000; // 2 min
        xhr.onload = function() {
            btn.disabled = false;
            btn.textContent = label0;
            if (xhr.status >= 200 && xhr.status < 300) {
                box.className = 'em-resultado show ok';
                box.textContent = xhr.responseText || 'Recuperação concluída.';
                // Recarrega após 2.5s pra atualizar a aba Pendentes com os novos registros
                setTimeout(function(){ window.location.reload(); }, 2500);
            } else {
                box.className = 'em-resultado show err';
                box.textContent = 'HTTP ' + xhr.status + '\n' + xhr.responseText;
            }
        };
        xhr.onerror = function() {
            btn.disabled = false;
            btn.textContent = label0;
            box.className = 'em-resultado show err';
            box.textContent = 'Erro de rede. Verifique a conexão e tente de novo.';
        };
        xhr.ontimeout = function() {
            btn.disabled = false;
            btn.textContent = label0;
            box.className = 'em-resultado show err';
            box.textContent = 'Timeout (>2 min). O processo pode estar rodando em background — recarregue a página em alguns minutos.';
        };
        xhr.send();
    };

    // ──── Descartar pendente ────
    window.emDescartarPendente = function(id) {
        if (!confirm('Marcar este processo como descartado? Ele some da lista (pode ser revisto com "mostrar descartados").')) return;

        var fd = new FormData();
        fd.append('action',     'descartar_pendente');
        fd.append('csrf_token', EM_CSRF);
        fd.append('id',         id);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', EM_PAGE, true);
        xhr.setRequestHeader('X-Csrf-Token', EM_CSRF);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function() {
            if (xhr.status === 401 && typeof window.fsaMostrarSessaoExpirada === 'function') {
                window.fsaMostrarSessaoExpirada();
                return;
            }
            var resp = null;
            try { resp = JSON.parse(xhr.responseText); } catch (e) {}
            if (xhr.status >= 200 && xhr.status < 300 && resp && resp.ok) {
                // Recarrega pra atualizar contador da aba e remover a linha
                window.location.reload();
            } else {
                alert('Erro ao descartar: ' + ((resp && resp.erro) || ('HTTP ' + xhr.status)));
            }
        };
        xhr.onerror = function() {
            alert('Erro de rede ao descartar.');
        };
        xhr.send(fd);
    };
})();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
