<?php
/**
 * Tela interna de um módulo de treinamento.
 * 3 abas: Conteúdo · Missão · Quiz. URL: ?slug=visao-geral
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$user = current_user();
$userId = (int)$user['id'];
$csrf = generate_csrf_token();

$slug = $_GET['slug'] ?? '';
if (!preg_match('/^[a-z0-9-]+$/', $slug)) { flash_set('error','Slug inválido.'); redirect(module_url('treinamento')); }

$stmt = $pdo->prepare("SELECT * FROM treinamento_modulos WHERE slug = ? AND ativo = 1");
$stmt->execute(array($slug));
$modulo = $stmt->fetch();
if (!$modulo) { flash_set('error','Módulo não encontrado.'); redirect(module_url('treinamento')); }

// Whitelist: módulos financeiros só pra Amanda/Rodrigo/Luiz (mesma regra do módulo real)
$slugsFinanceiros = array('financeiro', 'cobranca-honorarios');
if (in_array($slug, $slugsFinanceiros, true) && !can_access_financeiro()) {
    flash_set('error','Este treinamento é restrito.');
    redirect(module_url('treinamento'));
}

// Cria/carrega progresso
$pdo->prepare("INSERT IGNORE INTO treinamento_progresso (user_id, modulo_slug) VALUES (?, ?)")
    ->execute(array($userId, $slug));
$progStmt = $pdo->prepare("SELECT * FROM treinamento_progresso WHERE user_id = ? AND modulo_slug = ?");
$progStmt->execute(array($userId, $slug));
$prog = $progStmt->fetch() ?: array('conteudo_visto'=>0,'missao_feita'=>0,'quiz_concluido'=>0,'concluido'=>0,'quiz_acertos'=>0,'quiz_tentativas'=>0,'pontos_ganhos'=>0);

// Quiz
$quizStmt = $pdo->prepare("SELECT * FROM treinamento_quiz WHERE modulo_slug = ? ORDER BY ordem, id");
$quizStmt->execute(array($slug));
$perguntas = $quizStmt->fetchAll();

// Conteúdo didático
$conteudos = require __DIR__ . '/conteudo.php';
$cont = $conteudos[$slug] ?? array('por_que' => 'Conteúdo em preparação.', 'passos' => array(), 'atencao' => null, 'dica' => null, 'missao' => 'Explore o módulo no sistema.');

$aba = $_GET['aba'] ?? 'conteudo';
if (!in_array($aba, array('conteudo','missao','quiz'), true)) $aba = 'conteudo';

$pageTitle = 'Treinamento · ' . $modulo['titulo'];
require_once APP_ROOT . '/templates/layout_start.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Outfit:wght@400;600;700&display=swap" rel="stylesheet">

<style>
.tm-wrap { max-width:920px; margin:0 auto; }
.tm-back { display:inline-flex; align-items:center; gap:5px; padding:6px 12px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; text-decoration:none; color:#052228; font-size:.78rem; font-weight:600; margin-bottom:1rem; }
.tm-back:hover { border-color:#B87333; color:#B87333; }
.tm-hdr { background:linear-gradient(135deg,#052228,#0a3842); color:#fff; padding:1.8rem 2rem; border-radius:16px; margin-bottom:1.2rem; display:flex; gap:1rem; align-items:center; }
.tm-hdr .ico { font-size:3rem; line-height:1; }
.tm-hdr h1 { font-family:'Cormorant Garamond',serif; font-size:2rem; margin:0; color:#fff; font-weight:600; }
.tm-hdr .sub { font-size:.85rem; opacity:.85; margin-top:4px; font-family:'Outfit',sans-serif; }
.tm-abas { display:flex; gap:2px; background:#f3f4f6; padding:3px; border-radius:12px; margin-bottom:1.2rem; }
.tm-aba { flex:1; text-align:center; padding:10px; font-size:.82rem; font-weight:700; text-decoration:none; color:#6b7280; border-radius:9px; transition:all .15s; }
.tm-aba:hover { background:rgba(184,115,51,.1); color:#B87333; }
.tm-aba.active { background:#fff; color:#052228; box-shadow:0 2px 8px rgba(0,0,0,.08); }
.tm-aba.done { color:#059669; }
.tm-box { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:1.8rem 2rem; }
.tm-box h2 { font-family:'Cormorant Garamond',serif; color:#052228; font-size:1.5rem; margin:0 0 .8rem; font-weight:600; }
.tm-box h3 { font-family:'Cormorant Garamond',serif; color:#B87333; font-size:1.2rem; margin:1.5rem 0 .6rem; font-weight:600; }
.tm-box p, .tm-box li { font-size:.92rem; line-height:1.65; color:#1A1A1A; }
.tm-box ol { padding-left:1.4rem; }
.tm-box ol li { margin-bottom:.6rem; }
.tm-callout { padding:1rem 1.2rem; border-radius:10px; margin:1.2rem 0; display:flex; gap:.8rem; }
.tm-callout .icon { font-size:1.4rem; flex-shrink:0; }
.tm-callout.warn { background:#fef3c7; border-left:4px solid #d97706; color:#78350f; }
.tm-callout.tip { background:#f5ede3; border-left:4px solid #B87333; color:#78350f; }
.tm-missao-btn { background:#059669; color:#fff; border:none; padding:12px 28px; border-radius:10px; font-size:.95rem; font-weight:700; cursor:pointer; margin-top:1rem; }
.tm-missao-btn:hover { background:#047857; }
.tm-missao-btn:disabled { background:#9ca3af; cursor:not-allowed; }
.tm-quiz-card { background:#f9fafb; border:2px solid #e5e7eb; border-radius:12px; padding:1.5rem; margin-bottom:1rem; }
.tm-quiz-pergunta { font-size:1rem; font-weight:600; color:#052228; margin-bottom:1rem; }
.tm-quiz-opts { display:flex; flex-direction:column; gap:8px; }
.tm-quiz-opt { padding:12px 16px; border:2px solid #e5e7eb; border-radius:10px; cursor:pointer; background:#fff; font-size:.88rem; transition:all .12s; text-align:left; }
.tm-quiz-opt:hover { border-color:#B87333; background:#fff7ed; }
.tm-quiz-opt.selected { border-color:#052228; background:#f5ede3; }
.tm-quiz-opt.correct { border-color:#059669; background:#d1fae5; }
.tm-quiz-opt.wrong { border-color:#dc2626; background:#fee2e2; }
.tm-quiz-feedback { margin-top:12px; padding:12px; border-radius:10px; font-size:.85rem; }
.tm-quiz-feedback.ok { background:#d1fae5; color:#065f46; border-left:4px solid #059669; }
.tm-quiz-feedback.nok { background:#fee2e2; color:#991b1b; border-left:4px solid #dc2626; }
.tm-quiz-result { text-align:center; padding:2rem; background:#f5ede3; border-radius:14px; }
.tm-quiz-result .score { font-family:'Cormorant Garamond',serif; font-size:3rem; font-weight:600; color:#052228; }
.tm-next-btn { background:#B87333; color:#fff; border:none; padding:12px 28px; border-radius:10px; font-size:.95rem; font-weight:700; cursor:pointer; margin-top:1rem; }
.tm-next-btn:hover { background:#a06428; }

/* ── Mockups visuais ("telas_html") ─────────────────────────── */
.tm-screens { margin: 1.5rem 0; display: flex; flex-direction: column; gap: 1.4rem; }
.tm-screen { background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; box-shadow:0 1px 2px rgba(5,34,40,.04), 0 12px 32px -12px rgba(5,34,40,.15); }
.tm-screen-chrome { background:#f4f2ed; border-bottom:1px solid #e5e7eb; padding:.5rem .85rem; display:flex; align-items:center; gap:.5rem; }
.tm-screen-dots { display:inline-flex; gap:5px; }
.tm-screen-dots span { width:10px; height:10px; border-radius:50%; }
.tm-screen-dots span:nth-child(1){background:#e59a8f;} .tm-screen-dots span:nth-child(2){background:#e5c26a;} .tm-screen-dots span:nth-child(3){background:#7fbb8f;}
.tm-screen-url { background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:3px 9px; font-family:'JetBrains Mono',ui-monospace,monospace; font-size:.68rem; color:#8b7a68; margin-left:.5rem; }
.tm-screen-body { padding:1.1rem; overflow-x:auto; }
.tm-screen-caption { padding:.65rem 1.1rem .9rem; font-size:.82rem; color:#64748b; font-style:italic; border-top:1px dashed #e5e7eb; background:#faf7f2; margin:0; }

/* Sidebar mock */
.tm-mock-sidebar { background:linear-gradient(180deg,#052228 0%,#0a3842 100%); color:#fff; padding:.9rem .6rem; border-radius:8px; font-size:.82rem; min-width:260px; }
.tm-mock-sidebar .sec { color:rgba(255,255,255,.55); font-size:.62rem; letter-spacing:.12em; text-transform:uppercase; padding:.55rem .5rem .3rem; font-weight:700; }
.tm-mock-sidebar .item { display:flex; align-items:center; gap:.55rem; padding:.42rem .55rem; border-radius:6px; color:rgba(255,255,255,.88); }
.tm-mock-sidebar .item.hot { background:rgba(184,115,51,.22); box-shadow:inset 0 0 0 1px rgba(184,115,51,.5); }
.tm-mock-sidebar .item .icon { font-size:.95rem; width:1.2rem; text-align:center; }
.tm-mock-sidebar .item .badge { margin-left:auto; background:#0891b2; color:#fff; font-size:.65rem; padding:1px 7px; border-radius:10px; font-weight:700; }
.tm-mock-sidebar .arrow { color:#B87333; font-size:.75rem; padding:.3rem .55rem .1rem; }

/* Hub header mock */
.tm-mock-hdr { background:linear-gradient(135deg,#052228,#0d3640); color:#fff; border-radius:10px; padding:.85rem 1rem; display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; margin-bottom:1rem; }
.tm-mock-hdr h4 { font-family:'Cormorant Garamond',serif; font-size:1.05rem; margin:0; color:#fff; }
.tm-mock-hdr .s { font-size:.72rem; color:rgba(255,255,255,.75); margin-top:.1rem; }
.tm-mock-hdr .stats { margin-left:auto; display:flex; gap:.5rem; flex-wrap:wrap; }
.tm-mock-stat { background:rgba(255,255,255,.1); padding:4px 9px; border-radius:7px; font-size:.66rem; text-align:center; line-height:1.1; }
.tm-mock-stat b { font-size:.88rem; display:block; font-weight:700; }
.tm-mock-kill { padding:4px 9px; border-radius:7px; font-size:.62rem; font-weight:700; background:#059669; color:#fff; }

/* Form mock */
.tm-mock-form { border:1px solid #e5e7eb; border-radius:10px; padding:1rem 1.1rem; background:#fff; }
.tm-mock-form-title { font-size:.88rem; font-weight:700; margin:0 0 .7rem; color:#052228; }
.tm-mock-grid { display:grid; grid-template-columns:1fr 1fr; gap:.6rem .7rem; }
.tm-mock-field { display:flex; flex-direction:column; gap:.2rem; }
.tm-mock-field.full { grid-column:1/-1; }
.tm-mock-field label { font-size:.68rem; text-transform:uppercase; letter-spacing:.06em; font-weight:700; color:#64615a; }
.tm-mock-input { background:#fff; border:1.5px solid #e5e7eb; border-radius:7px; padding:7px 10px; font-size:.82rem; color:#052228; min-height:34px; display:flex; align-items:center; }
.tm-mock-input.focus { border-color:#052228; box-shadow:0 0 0 3px rgba(5,34,40,.08); }
.tm-mock-input.tall { min-height:78px; align-items:flex-start; padding:8px 10px; white-space:pre-line; }
.tm-mock-hint { font-size:.68rem; color:#8b7a68; margin-top:.3rem; }
.tm-mock-var { background:#f5ede3; color:#78350f; padding:1px 6px; border-radius:4px; font-family:'JetBrains Mono',ui-monospace,monospace; font-size:.72rem; margin-right:4px; }
.tm-mock-btn-good { display:inline-flex; align-items:center; gap:.35rem; background:#059669; color:#fff; padding:8px 16px; border-radius:7px; font-weight:700; font-size:.82rem; margin-top:.75rem; }

/* Lista de agendamentos mock */
.tm-mock-tabs { display:flex; gap:.3rem; margin:1rem 0 .7rem; flex-wrap:wrap; }
.tm-mock-tab { padding:5px 11px; background:#fff; border:1.5px solid #e5e7eb; border-radius:18px; font-size:.74rem; font-weight:600; color:#052228; }
.tm-mock-tab.active { background:#052228; color:#fff; border-color:#052228; }
.tm-mock-tab .n { background:#fef3c7; color:#78350f; padding:1px 7px; border-radius:9px; font-size:.66rem; margin-left:4px; }
.tm-mock-tab.active .n { background:rgba(255,255,255,.2); color:#fff; }
.tm-mock-item { background:#fff; border:1px solid #e5e7eb; border-left:4px solid; border-radius:8px; padding:.75rem .9rem; margin-bottom:.55rem; font-size:.8rem; }
.tm-mock-item.pend { border-left-color:#d97706; }
.tm-mock-item.env { border-left-color:#059669; }
.tm-mock-item .top { display:flex; align-items:center; gap:.45rem; flex-wrap:wrap; }
.tm-mock-item .quem { font-weight:700; color:#052228; }
.tm-mock-item .canal { padding:1px 6px; border-radius:5px; font-size:.62rem; font-weight:700; background:#f0fdf4; color:#166534; }
.tm-mock-item .quando { font-size:.68rem; color:#8b7a68; }
.tm-mock-item .badge { padding:1px 7px; border-radius:5px; font-weight:700; font-size:.62rem; text-transform:uppercase; margin-left:auto; }
.tm-mock-item .badge.pend { background:#fef3c7; color:#78350f; }
.tm-mock-item .badge.env { background:#d1fae5; color:#065f46; }
.tm-mock-item .msg { background:#f9f7f2; border-radius:6px; padding:.45rem .6rem; font-size:.76rem; color:#334155; margin-top:.35rem; line-height:1.45; }
.tm-mock-item .cancel { background:#fee2e2; color:#991b1b; padding:2px 9px; border-radius:5px; font-size:.66rem; font-weight:600; margin-left:auto; }

.tm-mock-cli-sug { margin-top:4px; background:#fff; border:1px solid #e5e7eb; border-radius:7px; max-width:340px; box-shadow:0 6px 16px rgba(0,0,0,.08); font-size:.78rem; }
.tm-mock-cli-sug .r { padding:7px 11px; border-bottom:1px solid #f3f0ea; }
.tm-mock-cli-sug .r:last-child { border-bottom:0; }
.tm-mock-cli-sug .r.hov { background:#f5faff; }
.tm-mock-cli-sug .r small { display:block; color:#8b7a68; font-size:.72rem; margin-top:1px; }

/* Painel do dia: cards de sessao */
.tm-mock-painel-sec { margin-bottom:.9rem; }
.tm-mock-painel-sec h5 { margin:0 0 .5rem; font-size:.72rem; text-transform:uppercase; letter-spacing:.1em; color:#8b7a68; font-weight:700; display:flex; align-items:center; gap:.4rem; }
.tm-mock-linha { background:#fff; border:1px solid #e5e7eb; border-left:4px solid #B87333; border-radius:8px; padding:.6rem .85rem; margin-bottom:.4rem; display:flex; align-items:center; gap:.6rem; font-size:.82rem; }
.tm-mock-linha.urg { border-left-color:#dc2626; background:#fef2f2; }
.tm-mock-linha .hora { font-family:'JetBrains Mono',ui-monospace,monospace; color:#052228; font-weight:700; font-size:.75rem; min-width:44px; }
.tm-mock-linha .titulo { flex:1; color:#052228; font-weight:600; }
.tm-mock-linha .cli { color:#8b7a68; font-size:.72rem; }
.tm-mock-linha .badge-urg { background:#dc2626; color:#fff; font-size:.62rem; padding:1px 7px; border-radius:5px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }

/* Drawer mock */
.tm-mock-drawer { max-width:520px; margin-left:auto; background:#fff; border:1px solid #e5e7eb; border-radius:12px 0 0 12px; box-shadow:-4px 0 20px rgba(5,34,40,.1); overflow:hidden; }
.tm-mock-drawer-top { background:linear-gradient(135deg,#052228,#0d3640); color:#fff; padding:1rem 1.1rem; }
.tm-mock-drawer-top h4 { margin:0; font-family:'Cormorant Garamond',serif; font-size:1.1rem; color:#fff; }
.tm-mock-drawer-top .sub { font-size:.72rem; color:rgba(255,255,255,.75); margin-top:.15rem; }
.tm-mock-drawer-abas { display:flex; gap:0; background:#f4f2ed; border-bottom:1px solid #e5e7eb; overflow-x:auto; }
.tm-mock-drawer-abas .a { padding:8px 12px; font-size:.7rem; font-weight:600; color:#8b7a68; white-space:nowrap; border-bottom:3px solid transparent; }
.tm-mock-drawer-abas .a.on { color:#052228; background:#fff; border-bottom-color:#B87333; }
.tm-mock-drawer-cont { padding:1rem 1.1rem; font-size:.82rem; }
.tm-mock-doc-item { display:flex; align-items:center; gap:.55rem; padding:.55rem .7rem; background:#faf7f2; border-radius:6px; margin-bottom:.4rem; font-size:.8rem; }
.tm-mock-doc-item.done { opacity:.6; text-decoration:line-through; }
.tm-mock-doc-item .chk { width:16px; height:16px; border:1.5px solid #B87333; border-radius:4px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:.7rem; color:#fff; }
.tm-mock-doc-item.done .chk { background:#059669; border-color:#059669; }
.tm-mock-doc-item.done .chk::before { content:'✓'; }

/* WhatsApp mock: layout 2 col */
.tm-mock-wa { display:grid; grid-template-columns:220px 1fr; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; background:#fff; min-height:340px; }
@media (max-width:640px) { .tm-mock-wa { grid-template-columns:1fr; } }
.tm-mock-wa-lista { background:#f4f2ed; border-right:1px solid #e5e7eb; overflow-y:auto; }
.tm-mock-wa-conv { padding:.6rem .8rem; border-bottom:1px solid #e5e7eb; cursor:pointer; }
.tm-mock-wa-conv.on { background:#fff; border-left:3px solid #B87333; padding-left:calc(.8rem - 3px); }
.tm-mock-wa-conv .nome { font-weight:700; color:#052228; font-size:.82rem; display:flex; align-items:center; gap:.4rem; }
.tm-mock-wa-conv .prev { font-size:.72rem; color:#8b7a68; margin-top:.15rem; }
.tm-mock-wa-conv .lock { font-size:.7rem; color:#dc2626; }
.tm-mock-wa-conv .badge-nl { margin-left:auto; background:#dc2626; color:#fff; font-size:.62rem; padding:1px 6px; border-radius:9px; font-weight:700; }
.tm-mock-wa-chat { display:flex; flex-direction:column; }
.tm-mock-wa-chat-hdr { background:#052228; color:#fff; padding:.7rem 1rem; display:flex; align-items:center; gap:.5rem; font-size:.82rem; }
.tm-mock-wa-chat-hdr .nome { font-weight:700; }
.tm-mock-wa-chat-hdr .timer { margin-left:auto; background:#fef3c7; color:#78350f; padding:2px 8px; border-radius:6px; font-size:.68rem; font-weight:700; font-family:'JetBrains Mono',ui-monospace,monospace; }
.tm-mock-wa-body { flex:1; padding:.9rem 1rem; background:#faf7f2; display:flex; flex-direction:column; gap:.55rem; }
.tm-mock-bolha { max-width:75%; padding:.5rem .75rem; border-radius:12px; font-size:.78rem; line-height:1.4; }
.tm-mock-bolha.recv { background:#fff; border:1px solid #e5e7eb; align-self:flex-start; border-bottom-left-radius:3px; }
.tm-mock-bolha.env { background:#dcfce7; align-self:flex-end; border-bottom-right-radius:3px; color:#052228; }
.tm-mock-bolha .h { display:block; font-size:.62rem; color:#8b7a68; margin-top:.2rem; text-align:right; }

/* Kanban mock — colunas + cards */
.tm-mock-kanban { display:flex; gap:.6rem; overflow-x:auto; padding-bottom:.5rem; }
.tm-mock-kb-col { flex:0 0 190px; background:#f4f2ed; border-radius:8px; padding:.6rem; min-width:0; }
.tm-mock-kb-col.destaque { background:#f5ede3; box-shadow:0 0 0 2px #B87333; }
.tm-mock-kb-col h6 { margin:0 0 .5rem; font-size:.68rem; text-transform:uppercase; letter-spacing:.06em; color:#052228; font-weight:700; display:flex; align-items:center; gap:.35rem; }
.tm-mock-kb-col h6 .cnt { background:#052228; color:#fff; padding:1px 7px; border-radius:9px; font-size:.62rem; }
.tm-mock-kb-card { background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:.5rem .6rem; margin-bottom:.4rem; font-size:.72rem; }
.tm-mock-kb-card.destaque { border-color:#B87333; box-shadow:0 2px 6px rgba(184,115,51,.15); }
.tm-mock-kb-card .nome { font-weight:700; color:#052228; }
.tm-mock-kb-card .sub { color:#8b7a68; font-size:.66rem; margin-top:.15rem; }
.tm-mock-kb-card .tag { display:inline-block; background:#f5ede3; color:#78350f; padding:1px 6px; border-radius:4px; font-size:.6rem; font-weight:700; margin-top:.25rem; }

/* Calculadora prazos mock */
.tm-mock-calc { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:1rem; max-width:420px; margin:0 auto; }
.tm-mock-calc-row { display:grid; grid-template-columns:1fr 1fr; gap:.5rem .7rem; margin-bottom:.55rem; }
.tm-mock-calc label { font-size:.66rem; text-transform:uppercase; letter-spacing:.06em; font-weight:700; color:#64615a; display:block; margin-bottom:.15rem; }
.tm-mock-calc .campo { background:#fff; border:1.5px solid #e5e7eb; border-radius:6px; padding:6px 9px; font-size:.8rem; }
.tm-mock-calc .campo.hi { border-color:#B87333; background:#faf7f2; font-weight:700; }
.tm-mock-calc-result { background:#052228; color:#e5e7eb; padding:.8rem 1rem; border-radius:8px; margin-top:.6rem; font-size:.85rem; }
.tm-mock-calc-result b { color:#f5ede3; font-family:'Cormorant Garamond',serif; font-size:1.15rem; font-weight:600; }
.tm-mock-calc-result .aviso { color:#fbbf24; font-size:.72rem; margin-top:.35rem; display:block; }

/* Documentos — grid de tipos */
.tm-mock-docs-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(140px, 1fr)); gap:.5rem; }
.tm-mock-doc-card { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:.7rem; text-align:center; font-size:.72rem; }
.tm-mock-doc-card.hi { border-color:#B87333; background:#faf7f2; }
.tm-mock-doc-card .ico { font-size:1.5rem; margin-bottom:.3rem; }
.tm-mock-doc-card .n { font-weight:700; color:#052228; }

/* Fabrica petições — chat com IA */
.tm-mock-ia { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:.85rem 1rem; max-width:520px; margin:0 auto; }
.tm-mock-ia-bolha { padding:.55rem .8rem; border-radius:10px; margin-bottom:.5rem; font-size:.78rem; line-height:1.5; }
.tm-mock-ia-bolha.user { background:#f0f9ff; border-left:3px solid #0284c7; }
.tm-mock-ia-bolha.bot { background:#faf7f2; border-left:3px solid #B87333; }
.tm-mock-ia-bolha .who { font-size:.62rem; text-transform:uppercase; letter-spacing:.08em; font-weight:700; margin-bottom:.2rem; opacity:.7; }

/* Tarefas — lista com prioridade */
.tm-mock-tarefas { background:#fff; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden; }
.tm-mock-tarefa { display:flex; align-items:center; gap:.6rem; padding:.6rem .85rem; border-bottom:1px solid #f3f0ea; font-size:.8rem; }
.tm-mock-tarefa:last-child { border-bottom:0; }
.tm-mock-tarefa.hi { background:#fef2f2; }
.tm-mock-tarefa .pri { padding:1px 7px; border-radius:5px; font-size:.6rem; font-weight:700; text-transform:uppercase; }
.tm-mock-tarefa .pri.alta { background:#fee2e2; color:#991b1b; }
.tm-mock-tarefa .pri.media { background:#fef3c7; color:#78350f; }
.tm-mock-tarefa .pri.baixa { background:#e0f2fe; color:#075985; }
.tm-mock-tarefa .txt { flex:1; color:#052228; }
.tm-mock-tarefa .quando { font-size:.68rem; color:#8b7a68; }

/* Agenda — mini calendário */
.tm-mock-cal { display:grid; grid-template-columns:repeat(7, 1fr); gap:1px; background:#e5e7eb; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden; }
.tm-mock-cal-hd { background:#052228; color:#fff; padding:.35rem; text-align:center; font-size:.62rem; font-weight:700; text-transform:uppercase; }
.tm-mock-cal-day { background:#fff; padding:.35rem .4rem; min-height:52px; font-size:.7rem; }
.tm-mock-cal-day.hoje { background:#f5ede3; box-shadow:inset 0 0 0 2px #B87333; }
.tm-mock-cal-day.tem { background:#fef3c7; }
.tm-mock-cal-day .num { font-weight:700; color:#052228; }
.tm-mock-cal-day .ev { display:block; font-size:.58rem; color:#78350f; margin-top:.15rem; overflow:hidden; text-overflow:ellipsis; }

/* Financeiro — cards de valores */
.tm-mock-fin-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:.6rem; }
.tm-mock-fin-card { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:.75rem .85rem; }
.tm-mock-fin-card.pos { border-left:4px solid #059669; }
.tm-mock-fin-card.neg { border-left:4px solid #dc2626; }
.tm-mock-fin-card.warn { border-left:4px solid #d97706; }
.tm-mock-fin-card .k { font-size:.62rem; text-transform:uppercase; letter-spacing:.08em; color:#8b7a68; font-weight:700; }
.tm-mock-fin-card .v { font-family:'Cormorant Garamond',serif; font-size:1.4rem; font-weight:600; color:#052228; }
.tm-mock-fin-card .sub { font-size:.68rem; color:#8b7a68; }

/* Mockup — Cards do Pipeline/Operacional com badge de cliques */
.tm-mock-pipe-card { background:#fff; border:1px solid #e5e7eb; border-left:4px solid #B87333; border-radius:8px; padding:.65rem .8rem; max-width:250px; margin:0 auto; box-shadow:0 2px 6px rgba(0,0,0,.06); }
.tm-mock-pipe-card .n { font-weight:700; color:#052228; font-size:.85rem; }
.tm-mock-pipe-card .m { font-size:.7rem; color:#6b7280; margin-top:.2rem; display:flex; gap:.55rem; }
.tm-mock-pipe-card .cnj { font-size:.62rem; color:#15803d; font-family:'Courier New',monospace; margin-top:.2rem; }
.tm-mock-pipe-card .click-badge { font-size:.6rem; color:#0369a1; font-weight:700; margin-top:.3rem; background:#e0f2fe; display:inline-block; padding:1px 6px; border-radius:5px; }

/* Comparativo antes/depois (chat WhatsApp) */
.tm-mock-antes-depois { display:grid; grid-template-columns:1fr 1fr; gap:.7rem; }
@media (max-width:640px) { .tm-mock-antes-depois { grid-template-columns:1fr; } }
.tm-mock-ad-card { background:#fff; border:2px solid; border-radius:10px; padding:.8rem .9rem; }
.tm-mock-ad-card.antes { border-color:#94a3b8; }
.tm-mock-ad-card.depois { border-color:#059669; background:#ecfdf5; }
.tm-mock-ad-card h5 { margin:0 0 .5rem; font-size:.72rem; text-transform:uppercase; letter-spacing:.08em; font-weight:800; }
.tm-mock-ad-card.antes h5 { color:#475569; }
.tm-mock-ad-card.depois h5 { color:#047857; }
.tm-mock-ad-card .bolha { background:#dcfce7; padding:.5rem .75rem; border-radius:8px; font-size:.78rem; color:#052228; line-height:1.5; word-break:break-all; }
.tm-mock-ad-card.antes .bolha { background:#f1f5f9; }
.tm-mock-ad-card .obs { font-size:.68rem; color:#8b7a68; margin-top:.4rem; font-style:italic; }

/* Fluxo do clique — 3 caixas horizontais */
.tm-mock-fluxo-cliente { display:grid; grid-template-columns:1fr auto 1fr auto 1fr; gap:.5rem; align-items:center; }
@media (max-width:640px) { .tm-mock-fluxo-cliente { grid-template-columns:1fr; } .tm-mock-fluxo-cliente .seta { display:none; } }
.tm-mock-fluxo-cliente .caixa { background:#fff; border:2px solid #B87333; border-radius:10px; padding:.7rem .8rem; text-align:center; font-size:.78rem; }
.tm-mock-fluxo-cliente .caixa .ico { font-size:1.6rem; margin-bottom:.25rem; display:block; }
.tm-mock-fluxo-cliente .caixa .titulo { font-weight:700; color:#052228; }
.tm-mock-fluxo-cliente .caixa .sub { font-size:.66rem; color:#8b7a68; margin-top:.15rem; }
.tm-mock-fluxo-cliente .seta { font-size:1.5rem; color:#B87333; text-align:center; }

/* Procuração — comparativo lado a lado */
.tm-mock-procuracao { display:grid; grid-template-columns:1fr 1fr; gap:.7rem; }
@media (max-width:640px) { .tm-mock-procuracao { grid-template-columns:1fr; } }
.tm-mock-proc-card { background:#fff; border:2px solid; border-radius:10px; padding:.85rem 1rem; }
.tm-mock-proc-card.crianca { border-color:#059669; background:#ecfdf5; }
.tm-mock-proc-card.contratante { border-color:#0284c7; background:#eff6ff; }
.tm-mock-proc-card h5 { margin:0 0 .5rem; font-size:.75rem; text-transform:uppercase; letter-spacing:.08em; font-weight:800; display:flex; align-items:center; gap:.35rem; }
.tm-mock-proc-card.crianca h5 { color:#047857; }
.tm-mock-proc-card.contratante h5 { color:#075985; }
.tm-mock-proc-card ul { margin:0; padding-left:1.1rem; font-size:.82rem; line-height:1.6; color:#052228; }

/* Botão copiar link */
.tm-copy-btn { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; color:#052228; font-size:.78rem; font-weight:600; cursor:pointer; transition:all .12s; font-family:inherit; }
.tm-copy-btn:hover { border-color:#B87333; color:#B87333; }
.tm-copy-btn.copiado { background:#d1fae5; border-color:#059669; color:#065f46; }
</style>

<?php
$_urlTreinamento = 'https://ferreiraesa.com.br/conecta/modules/treinamento/modulo.php?slug=' . urlencode($slug);
?>
<div class="tm-wrap">

<div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:1rem;">
    <a href="<?= module_url('treinamento') ?>" class="tm-back" style="margin:0;">← Voltar aos módulos</a>
    <button type="button" class="tm-copy-btn" onclick="tmCopiarLink(this, '<?= e($_urlTreinamento) ?>')" title="Copia o link deste treinamento pra você compartilhar por WhatsApp/email">
        🔗 <span>Copiar link</span>
    </button>
</div>

<div class="tm-hdr">
    <div class="ico"><?= e($modulo['icone']) ?></div>
    <div>
        <h1><?= e($modulo['titulo']) ?></h1>
        <div class="sub"><?= e($modulo['descricao']) ?></div>
    </div>
    <div style="margin-left:auto; text-align:right;">
        <div style="font-size:1.6rem; font-weight:800; color:#D7AB90;">+<?= (int)$modulo['pontos'] ?></div>
        <div style="font-size:.7rem; opacity:.85;">ao concluir</div>
    </div>
</div>

<div class="tm-abas">
    <?php
    $abas = array('conteudo' => array('📖', 'Conteúdo', $prog['conteudo_visto']),
                  'missao'   => array('🎯', 'Missão',   $prog['missao_feita']),
                  'quiz'     => array('❓', 'Quiz',     $prog['quiz_concluido']));
    foreach ($abas as $k => $v):
        $active = $aba === $k ? 'active' : '';
        $done = $v[2] ? 'done' : '';
    ?>
        <a href="?slug=<?= e($slug) ?>&aba=<?= $k ?>" class="tm-aba <?= $active ?> <?= $done ?>">
            <?= $v[0] ?> <?= $v[1] ?> <?= $v[2] ? '✓' : '' ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($aba === 'conteudo'): ?>
<div class="tm-box">
    <h3>POR QUE ISSO IMPORTA</h3>
    <p><?= nl2br(e($cont['por_que'])) ?></p>

    <?php if (!empty($cont['telas_html'])): ?>
    <h3>COMO É NA TELA</h3>
    <div class="tm-screens">
        <?= $cont['telas_html'] /* HTML controlado, sem escape */ ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($cont['passos'])): ?>
    <h3>PASSO A PASSO</h3>
    <ol>
        <?php foreach ($cont['passos'] as $p):
            $txt = e($p);
            $txt = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $txt);
            $txt = preg_replace('/`(.+?)`/', '<code>$1</code>', $txt);
        ?>
        <li><?= $txt ?></li>
        <?php endforeach; ?>
    </ol>
    <?php endif; ?>

    <?php if (!empty($cont['atencao'])): ?>
    <div class="tm-callout warn">
        <div class="icon">⚠️</div>
        <div><strong>ATENÇÃO — erros comuns</strong><br><?= nl2br(e($cont['atencao'])) ?></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($cont['dica'])): ?>
    <div class="tm-callout tip">
        <div class="icon">💡</div>
        <div><strong>DICA DE OURO</strong><br><?= nl2br(e($cont['dica'])) ?></div>
    </div>
    <?php endif; ?>

    <div style="margin-top:2rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
        <button id="btnConteudo" class="tm-missao-btn" <?= $prog['conteudo_visto'] ? 'disabled' : '' ?>>
            <?= $prog['conteudo_visto'] ? '✓ Conteúdo lido' : 'Marcar como lido →' ?>
        </button>
        <?php if (!$prog['missao_feita']): ?>
            <a href="?slug=<?= e($slug) ?>&aba=missao" class="tm-next-btn" style="text-decoration:none;">Ir pra missão 🎯</a>
        <?php elseif (!$prog['quiz_concluido']): ?>
            <a href="?slug=<?= e($slug) ?>&aba=quiz" class="tm-next-btn" style="text-decoration:none;">Ir pro quiz ❓</a>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($aba === 'missao'): ?>
<div class="tm-box">
    <h2>🎯 Missão prática</h2>
    <p style="font-size:1rem; line-height:1.7; color:#1A1A1A;"><?= nl2br(e($cont['missao'])) ?></p>

    <div class="tm-callout tip">
        <div class="icon">💪</div>
        <div>Abra o sistema em outra aba, execute a tarefa, volte aqui e clique no botão verde abaixo quando tiver feito.</div>
    </div>

    <button id="btnMissao" class="tm-missao-btn" <?= $prog['missao_feita'] ? 'disabled' : '' ?>>
        <?= $prog['missao_feita'] ? '✓ Missão concluída' : 'Missão concluída ✓' ?>
    </button>

    <?php if (!$prog['quiz_concluido'] && $prog['missao_feita']): ?>
        <a href="?slug=<?= e($slug) ?>&aba=quiz" class="tm-next-btn" style="text-decoration:none; margin-left:10px;">Ir pro quiz ❓</a>
    <?php endif; ?>
</div>

<?php else: /* quiz */ ?>
<div class="tm-box" id="quizContainer">
    <?php if (empty($perguntas)): ?>
        <p>Quiz deste módulo ainda em preparação.</p>
    <?php elseif ($prog['quiz_concluido']):
        $totalQ = count($perguntas);
        $pctAcerto = round($prog['quiz_acertos'] / max($totalQ, 1) * 100);
        $moduloConcluido = (int)$prog['concluido'] === 1;
        $falta = array();
        if (!$prog['conteudo_visto']) $falta[] = array('📖 Ler conteúdo', 'conteudo');
        if (!$prog['missao_feita'])   $falta[] = array('🎯 Fazer missão', 'missao');
    ?>
        <?php if ($moduloConcluido): ?>
            <div class="tm-quiz-result">
                <div class="score" style="color:#059669;">🎉</div>
                <h2>Módulo concluído!</h2>
                <p>Você acertou <?= (int)$prog['quiz_acertos'] ?> de <?= $totalQ ?> (<?= $pctAcerto ?>%).</p>
                <p style="margin-top:1rem;">+<strong style="color:#B87333;"><?= (int)$prog['pontos_ganhos'] ?> pts</strong> creditados 🏆</p>
                <a href="?slug=<?= e($slug) ?>&aba=quiz&refazer=1" class="tm-next-btn" style="text-decoration:none; margin-top:1rem; display:inline-block;">🔄 Refazer quiz</a>
            </div>
        <?php else: ?>
            <div class="tm-quiz-result">
                <div class="score" style="color:#059669;">✅</div>
                <h2 style="color:#059669;">Quiz aprovado!</h2>
                <p>Você acertou <?= (int)$prog['quiz_acertos'] ?> de <?= $totalQ ?> (<?= $pctAcerto ?>%) — excelente!</p>
                <p style="margin-top:1rem; color:#78350f; background:#fef3c7; padding:.8rem; border-radius:8px;">
                    <strong>Pra finalizar o módulo e receber os +<?= (int)$modulo['pontos'] ?> pts, falta:</strong><br>
                    <?= implode(' e ', array_map(function($f){ return $f[0]; }, $falta)) ?>
                </p>
                <div style="display:flex; gap:.5rem; justify-content:center; margin-top:1rem; flex-wrap:wrap;">
                    <?php foreach ($falta as $f): ?>
                        <a href="?slug=<?= e($slug) ?>&aba=<?= $f[1] ?>" class="tm-next-btn" style="text-decoration:none;"><?= e($f[0]) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <h2>❓ Quiz — <?= count($perguntas) ?> pergunta(s)</h2>
        <p style="color:#6b7280; font-size:.85rem;">Precisa acertar pelo menos <strong>70%</strong> pra concluir o módulo.</p>
        <form id="quizForm">
            <?php foreach ($perguntas as $i => $p): ?>
            <div class="tm-quiz-card" data-qid="<?= (int)$p['id'] ?>">
                <div class="tm-quiz-pergunta"><?= ($i+1) ?>. <?= e($p['pergunta']) ?></div>
                <div class="tm-quiz-opts">
                    <?php foreach (array('a','b','c','d') as $letra): ?>
                    <label class="tm-quiz-opt">
                        <input type="radio" name="q<?= (int)$p['id'] ?>" value="<?= $letra ?>" style="display:none;">
                        <strong><?= strtoupper($letra) ?>)</strong> <?= e($p['opcao_' . $letra]) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="tm-quiz-feedback" style="display:none;"></div>
            </div>
            <?php endforeach; ?>
            <button type="button" id="btnEnviarQuiz" class="tm-next-btn" style="display:block; width:100%; padding:15px;">Enviar respostas ✓</button>
        </form>
    <?php endif; ?>
</div>

<script>
(function(){
    // Seleção de opções
    document.querySelectorAll('.tm-quiz-opt').forEach(function(opt){
        opt.addEventListener('click', function(){
            var card = opt.closest('.tm-quiz-card');
            if (!card) return;
            if (card.dataset.answered === '1') return; // já respondeu
            card.querySelectorAll('.tm-quiz-opt').forEach(function(o){ o.classList.remove('selected'); });
            opt.classList.add('selected');
            opt.querySelector('input').checked = true;
        });
    });

    var btn = document.getElementById('btnEnviarQuiz');
    if (btn) {
        btn.addEventListener('click', function(){
            var respostas = {};
            var todasRespondidas = true;
            document.querySelectorAll('.tm-quiz-card').forEach(function(card){
                var sel = card.querySelector('input[type="radio"]:checked');
                if (!sel) { todasRespondidas = false; return; }
                respostas[card.dataset.qid] = sel.value;
            });
            if (!todasRespondidas) { alert('Responda todas as perguntas antes de enviar.'); return; }

            btn.disabled = true; btn.textContent = 'Enviando...';
            var fd = new FormData();
            fd.append('action', 'salvar_quiz');
            fd.append('csrf_token', '<?= e($csrf) ?>');
            fd.append('slug', '<?= e($slug) ?>');
            fd.append('respostas', JSON.stringify(respostas));

            fetch('<?= module_url('treinamento','api.php') ?>', { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (d.error) { alert(d.error); btn.disabled = false; btn.textContent = 'Enviar respostas ✓'; return; }
                    // Mostra feedback em cada pergunta
                    (d.detalhes || []).forEach(function(det){
                        var card = document.querySelector('.tm-quiz-card[data-qid="' + det.id + '"]');
                        if (!card) return;
                        card.dataset.answered = '1';
                        card.querySelectorAll('.tm-quiz-opt').forEach(function(o){
                            var inp = o.querySelector('input'); if (!inp) return;
                            o.classList.remove('selected');
                            if (inp.value === det.correta) o.classList.add('correct');
                            else if (inp.value === det.escolhida) o.classList.add('wrong');
                        });
                        var fb = card.querySelector('.tm-quiz-feedback');
                        fb.className = 'tm-quiz-feedback ' + (det.acertou ? 'ok' : 'nok');
                        fb.innerHTML = (det.acertou ? '✅ Correto! ' : '❌ Errado. ') + (det.explicacao || '');
                        fb.style.display = 'block';
                    });
                    btn.style.display = 'none';
                    var resultado = document.createElement('div');
                    resultado.className = 'tm-quiz-result';
                    resultado.style.marginTop = '1.5rem';

                    if (d.concluido) {
                        // Cenário A: 70%+ E módulo concluído (3 etapas feitas)
                        resultado.innerHTML = '<div class="score" style="color:#059669;">🎉</div>' +
                            '<h2>Parabéns! Módulo concluído!</h2>' +
                            '<p>Acertou ' + d.acertos + '/' + d.total + ' (' + d.percentual + '%)</p>' +
                            '<p style="margin-top:1rem;">+<strong style="color:#B87333;">' + d.pontos + ' pontos</strong> creditados 🏆</p>' +
                            '<a href="<?= module_url('treinamento') ?>" class="tm-next-btn" style="text-decoration:none; margin-top:1rem; display:inline-block;">Voltar aos módulos</a>';
                    } else if (d.quiz_passou) {
                        // Cenário B: passou no quiz MAS faltou conteúdo/missão
                        var falta = [];
                        if (d.pendencias && d.pendencias.indexOf('conteudo') >= 0) falta.push('📖 marcar o conteúdo como lido');
                        if (d.pendencias && d.pendencias.indexOf('missao') >= 0) falta.push('🎯 fazer a missão prática');
                        var txt = falta.length ? falta.join(' e ') : 'marcar os outros passos';
                        resultado.innerHTML = '<div class="score" style="color:#059669;">✅</div>' +
                            '<h2 style="color:#059669;">Quiz aprovado!</h2>' +
                            '<p>Acertou ' + d.acertos + '/' + d.total + ' (' + d.percentual + '%) — excelente!</p>' +
                            '<p style="margin-top:1rem; color:#78350f; background:#fef3c7; padding:.8rem; border-radius:8px;"><strong>Pra concluir o módulo e receber os pontos, falta ' + txt + '.</strong></p>' +
                            '<div style="display:flex; gap:.5rem; justify-content:center; margin-top:1rem; flex-wrap:wrap;">' +
                                (d.pendencias.indexOf('conteudo') >= 0 ? '<a href="?slug=<?= e($slug) ?>&aba=conteudo" class="tm-next-btn" style="text-decoration:none;">📖 Ler conteúdo</a>' : '') +
                                (d.pendencias.indexOf('missao') >= 0 ? '<a href="?slug=<?= e($slug) ?>&aba=missao" class="tm-next-btn" style="text-decoration:none;">🎯 Ir pra missão</a>' : '') +
                            '</div>';
                    } else {
                        // Cenário C: < 70% — não passou no quiz
                        resultado.innerHTML = '<div class="score" style="color:#dc2626;">💪</div>' +
                            '<h2>Quase lá!</h2>' +
                            '<p>Acertou ' + d.acertos + '/' + d.total + ' (' + d.percentual + '%)</p>' +
                            '<p style="color:#6b7280;">Precisa de pelo menos 70%. Revise o conteúdo e tente novamente.</p>' +
                            '<a href="?slug=<?= e($slug) ?>&aba=quiz&refazer=1" class="tm-next-btn" style="text-decoration:none; margin-top:1rem; display:inline-block;">🔄 Tentar novamente</a>';
                    }
                    document.getElementById('quizContainer').appendChild(resultado);
                    resultado.scrollIntoView({ behavior: 'smooth' });
                });
        });
    }
})();
</script>
<?php endif; ?>

</div>

<script>
// Marcar conteúdo/missão
var CSRF = '<?= e($csrf) ?>', API = '<?= module_url('treinamento','api.php') ?>', SLUG = '<?= e($slug) ?>';

document.getElementById('btnConteudo')?.addEventListener('click', function(){
    this.disabled = true; this.textContent = 'Salvando...';
    var fd = new FormData(); fd.append('action','marcar_conteudo'); fd.append('csrf_token',CSRF); fd.append('slug',SLUG);
    fetch(API,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if (d.ok) { location.reload(); }
        else { alert(d.error||'Erro'); this.disabled=false; this.textContent='Marcar como lido →'; }
    });
});
document.getElementById('btnMissao')?.addEventListener('click', function(){
    this.disabled = true; this.textContent = 'Salvando...';
    var fd = new FormData(); fd.append('action','marcar_missao'); fd.append('csrf_token',CSRF); fd.append('slug',SLUG);
    fetch(API,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if (d.ok) { location.reload(); }
        else { alert(d.error||'Erro'); this.disabled=false; this.textContent='Missão concluída ✓'; }
    });
});

// Copiar link do treinamento
window.tmCopiarLink = function(btn, url) {
    var textoOriginal = btn.querySelector('span').textContent;
    var restaurar = function() {
        setTimeout(function(){ btn.classList.remove('copiado'); btn.querySelector('span').textContent = textoOriginal; }, 1800);
    };
    var sucesso = function() { btn.classList.add('copiado'); btn.querySelector('span').textContent = '✓ Link copiado!'; restaurar(); };
    // Método moderno (HTTPS + secure context)
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url).then(sucesso).catch(fallback);
    } else { fallback(); }
    function fallback() {
        // Fallback pra navegadores antigos: textarea temporário + execCommand
        var ta = document.createElement('textarea');
        ta.value = url; ta.style.position='fixed'; ta.style.left='-9999px';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); sucesso(); }
        catch(e) { alert('Link: ' + url); }
        document.body.removeChild(ta);
    }
};
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
