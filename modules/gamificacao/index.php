<?php
/**
 * Ferreira & Sá Hub — Gamificação / Ranking
 * Módulo visual completo com tema dark + copper/gold
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
$pageTitle = 'Ranking';
$pdo = db();
$userId = current_user_id();
$userName = current_user()['name'] ?? 'Usuário';
$isAdmin = has_role('admin');
$isGestao = has_min_role('gestao');

// Determine visible areas
$userRole = $_SESSION['user']['role'] ?? '';
$areas = array('comercial');
if ($isGestao || $isAdmin) {
    $areas = array('comercial', 'operacional');
} elseif (in_array($userRole, array('operacional'))) {
    $areas = array('operacional');
}

$areaAtual = isset($_GET['area']) && in_array($_GET['area'], $areas) ? $_GET['area'] : $areas[0];

// Current month data
$mesAtual = (int)date('n');
$anoAtual = (int)date('Y');
$meses = array('','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro');

// ── PHP Queries ─────────────────────────────────────────────
$campo_mes = 'pontos_mes_' . $areaAtual;
$campo_total = 'pontos_total_' . $areaAtual;

// Ranking mensal
try {
    $stmtRanking = $pdo->prepare(
        "SELECT gt.*, u.name, gn.badge_emoji
         FROM gamificacao_totais gt
         JOIN users u ON u.id = gt.user_id
         LEFT JOIN gamificacao_niveis gn ON gn.nivel_num = gt.nivel_num
         WHERE u.is_active = 1
           AND gt.mes_referencia = ?
           AND gt.ano_referencia = ?
         ORDER BY gt.{$campo_mes} DESC"
    );
    $stmtRanking->execute(array($mesAtual, $anoAtual));
    $ranking = $stmtRanking->fetchAll();
} catch (Exception $ex) {
    $ranking = array();
}

// Meta
try {
    $stmtMeta = $pdo->prepare("SELECT * FROM gamificacao_config WHERE mes=? AND ano=? AND area=?");
    $stmtMeta->execute(array($mesAtual, $anoAtual, $areaAtual));
    $metaData = $stmtMeta->fetch();
} catch (Exception $ex) {
    $metaData = null;
}

// My position
$myPos = gamificacao_posicao($userId, $areaAtual);

// My totals
try {
    $stmtMy = $pdo->prepare(
        "SELECT gt.*, gn.badge_emoji
         FROM gamificacao_totais gt
         LEFT JOIN gamificacao_niveis gn ON gn.nivel_num = gt.nivel_num
         WHERE gt.user_id = ? AND gt.mes_referencia = ? AND gt.ano_referencia = ?"
    );
    $stmtMy->execute(array($userId, $mesAtual, $anoAtual));
    $myData = $stmtMy->fetch();
} catch (Exception $ex) {
    $myData = null;
}

// Historico
try {
    $stmtHist = $pdo->prepare("SELECT * FROM gamificacao_pontos WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
    $stmtHist->execute(array($userId));
    $histData = $stmtHist->fetchAll();
} catch (Exception $ex) {
    $histData = array();
}

// Niveis
try {
    $niveis = $pdo->query("SELECT * FROM gamificacao_niveis ORDER BY nivel_num")->fetchAll();
} catch (Exception $ex) {
    $niveis = array();
}

// Premios do mês
try {
    $stmtPremios = $pdo->prepare(
        "SELECT gp.*, u.name as user_name
         FROM gamificacao_premios gp
         LEFT JOIN users u ON u.id = gp.user_id
         WHERE gp.mes=? AND gp.ano=? AND gp.area=?
         ORDER BY gp.posicao"
    );
    $stmtPremios->execute(array($mesAtual, $anoAtual, $areaAtual));
    $premiosData = $stmtPremios->fetchAll();
} catch (Exception $ex) {
    $premiosData = array();
}

// Prepare JSON data for JS
$rankingJson = json_encode($ranking, JSON_UNESCAPED_UNICODE);
$metaJson = json_encode($metaData ?: new stdClass(), JSON_UNESCAPED_UNICODE);
$myDataJson = json_encode($myData ?: new stdClass(), JSON_UNESCAPED_UNICODE);
$histJson = json_encode($histData, JSON_UNESCAPED_UNICODE);
$niveisJson = json_encode($niveis, JSON_UNESCAPED_UNICODE);
$premiosJson = json_encode($premiosData, JSON_UNESCAPED_UNICODE);
$areasJson = json_encode($areas, JSON_UNESCAPED_UNICODE);

$apiUrl = module_url('gamificacao', 'api.php');
$csrfToken = generate_csrf_token();

$metaPrincipal = $metaData ? (int)($metaData['meta_principal'] ?? 0) : 0;
$totalContratos = 0;
foreach ($ranking as $r) {
    $totalContratos += (int)($r['contratos_mes'] ?? 0);
}
$metaPct = $metaPrincipal > 0 ? min(100, round(($totalContratos / $metaPrincipal) * 100)) : 0;

$myPontosMes = $myData ? (int)($myData[$campo_mes] ?? 0) : 0;
$myContratos = $myData ? (int)($myData['contratos_mes'] ?? 0) : 0;
$myNivel = $myData ? e($myData['nivel'] ?? 'Iniciante') : 'Iniciante';
$myBadge = $myData ? ($myData['badge_emoji'] ?? '') : '';
$myNivelNum = $myData ? (int)($myData['nivel_num'] ?? 0) : 0;

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
/* Override portal styles for gamification page */
.page-content { max-width:none !important; padding:0 !important; background:var(--gam-ink) !important; }
.main-content { background:var(--gam-ink) !important; }
.topbar { background:var(--gam-deep) !important; border-bottom:1px solid rgba(200,135,58,0.15) !important; }
.topbar-title { color:var(--gam-text) !important; }

@import url('https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=Outfit:wght@300;400;500;600;700&display=swap');

:root {
    --gam-ink: #040E12;
    --gam-deep: #071820;
    --gam-mid: #0D2535;
    --gam-surface: #112D3E;
    --gam-glass: rgba(255,255,255,0.04);
    --gam-cobre: #C8873A;
    --gam-gold: #E8C94A;
    --gam-silver: #A8B8C8;
    --gam-bronze: #C87840;
    --gam-text: #F0EDE8;
    --gam-text-dim: rgba(240,237,232,0.5);
    --gam-green: #3DAA6A;
}

/* ═══════════════ RESET & BASE ═══════════════ */
.gam-wrap {
    position: relative;
    min-height: 100vh;
    background: var(--gam-ink);
    color: var(--gam-text);
    font-family: 'Outfit', sans-serif;
    font-weight: 400;
    overflow-x: hidden;
}

.gam-wrap * { box-sizing: border-box; }

/* ═══════════════ BACKGROUND ═══════════════ */
.gam-bg {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    z-index: 0;
    pointer-events: none;
    background:
        radial-gradient(ellipse 80% 60% at 50% 0%, rgba(200,135,58,0.08) 0%, transparent 60%),
        radial-gradient(ellipse 60% 50% at 80% 100%, rgba(200,135,58,0.04) 0%, transparent 50%),
        radial-gradient(ellipse 40% 40% at 20% 80%, rgba(13,37,53,0.6) 0%, transparent 50%),
        var(--gam-ink);
}

.gam-grid-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    z-index: 0;
    pointer-events: none;
    background-image:
        linear-gradient(rgba(200,135,58,0.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(200,135,58,0.03) 1px, transparent 1px);
    background-size: 60px 60px;
}

/* Particles canvas */
#gamParticles {
    position: fixed;
    top: 0; left: 0;
    width: 100%;
    height: 100%;
    z-index: 0;
    pointer-events: none;
}

/* ═══════════════ CONTENT WRAPPER ═══════════════ */
.gam-content {
    position: relative;
    z-index: 1;
    max-width: 1200px;
    margin: 0 auto;
    padding: 24px 20px 60px;
}

/* ═══════════════ TABS ═══════════════ */
.gam-tabs {
    display: flex;
    gap: 6px;
    margin-bottom: 32px;
    flex-wrap: wrap;
}

.gam-tab-btn {
    padding: 10px 24px;
    border: 1px solid rgba(200,135,58,0.2);
    border-radius: 50px;
    background: var(--gam-glass);
    color: var(--gam-text-dim);
    font-family: 'Outfit', sans-serif;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    white-space: nowrap;
}

.gam-tab-btn:hover {
    border-color: var(--gam-cobre);
    color: var(--gam-text);
    background: rgba(200,135,58,0.08);
}

.gam-tab-btn.active {
    background: linear-gradient(135deg, var(--gam-cobre), #A06A28);
    border-color: var(--gam-cobre);
    color: #fff;
    font-weight: 600;
    box-shadow: 0 4px 20px rgba(200,135,58,0.3);
}

/* ═══════════════ HERO SECTION ═══════════════ */
.gam-hero {
    text-align: center;
    margin-bottom: 32px;
    position: relative;
}

.gam-hero-title {
    font-family: 'Outfit', sans-serif;
    font-size: 14px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 4px;
    color: var(--gam-cobre);
    margin: 0 0 8px;
}

.gam-hero-month {
    font-family: 'Cormorant Garamond', serif;
    font-size: 52px;
    font-weight: 700;
    background: linear-gradient(135deg, var(--gam-gold), var(--gam-cobre), var(--gam-gold));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin: 0;
    line-height: 1.1;
}

.gam-hero-sub {
    font-size: 13px;
    color: var(--gam-text-dim);
    margin-top: 6px;
}

/* ═══════════════ META CARD ═══════════════ */
.gam-meta-card {
    background: linear-gradient(135deg, var(--gam-surface), var(--gam-mid));
    border: 1px solid rgba(200,135,58,0.15);
    border-radius: 16px;
    padding: 20px 24px;
    margin-bottom: 28px;
    display: flex;
    align-items: center;
    gap: 20px;
    backdrop-filter: blur(10px);
    flex-wrap: wrap;
}

.gam-meta-label {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 2px;
    color: var(--gam-text-dim);
    margin-bottom: 4px;
}

.gam-meta-value {
    font-family: 'Cormorant Garamond', serif;
    font-size: 28px;
    font-weight: 700;
    color: var(--gam-gold);
}

.gam-meta-bar-wrap {
    flex: 1;
    min-width: 200px;
}

.gam-meta-bar {
    height: 10px;
    background: rgba(255,255,255,0.06);
    border-radius: 5px;
    overflow: hidden;
    position: relative;
}

.gam-meta-bar-fill {
    height: 100%;
    border-radius: 5px;
    background: linear-gradient(90deg, var(--gam-cobre), var(--gam-gold));
    transition: width 1.2s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
}

.gam-meta-bar-fill::after {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
    animation: gam-shimmer 2s infinite;
}

@keyframes gam-shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

.gam-meta-pct {
    font-size: 12px;
    color: var(--gam-text-dim);
    margin-top: 4px;
    text-align: right;
}

/* ═══════════════ AREA TOGGLE ═══════════════ */
.gam-area-toggle {
    display: flex;
    gap: 8px;
    justify-content: center;
    margin-bottom: 36px;
}

.gam-area-btn {
    padding: 10px 28px;
    border-radius: 12px;
    border: 1px solid rgba(200,135,58,0.15);
    background: var(--gam-glass);
    color: var(--gam-text-dim);
    font-family: 'Outfit', sans-serif;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
}

.gam-area-btn:hover {
    border-color: var(--gam-cobre);
    color: var(--gam-text);
}

.gam-area-btn.active {
    background: var(--gam-surface);
    border-color: var(--gam-cobre);
    color: var(--gam-text);
    box-shadow: 0 0 20px rgba(200,135,58,0.15);
}

/* ═══════════════ PODIO ═══════════════ */
.gam-podio {
    display: flex;
    align-items: flex-end;
    justify-content: center;
    gap: 16px;
    margin-bottom: 40px;
    padding: 20px 0;
}

.gam-podio-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    animation: gam-fadeUp 0.6s ease both;
}

.gam-podio-item:nth-child(1) { animation-delay: 0.2s; }
.gam-podio-item:nth-child(2) { animation-delay: 0s; }
.gam-podio-item:nth-child(3) { animation-delay: 0.4s; }

@keyframes gam-fadeUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}

.gam-podio-avatar {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    font-weight: 700;
    color: var(--gam-ink);
    position: relative;
    margin-bottom: 10px;
}

.gam-podio-item[data-pos="1"] .gam-podio-avatar {
    width: 80px;
    height: 80px;
    font-size: 30px;
    border: 3px solid var(--gam-gold);
    background: linear-gradient(135deg, rgba(232,201,74,0.2), rgba(200,135,58,0.2));
    color: var(--gam-gold);
    box-shadow: 0 0 30px rgba(232,201,74,0.3);
}

.gam-podio-item[data-pos="2"] .gam-podio-avatar {
    border: 3px solid var(--gam-silver);
    background: linear-gradient(135deg, rgba(168,184,200,0.2), rgba(168,184,200,0.1));
    color: var(--gam-silver);
    box-shadow: 0 0 20px rgba(168,184,200,0.2);
}

.gam-podio-item[data-pos="3"] .gam-podio-avatar {
    border: 3px solid var(--gam-bronze);
    background: linear-gradient(135deg, rgba(200,120,64,0.2), rgba(200,120,64,0.1));
    color: var(--gam-bronze);
    box-shadow: 0 0 20px rgba(200,120,64,0.2);
}

.gam-podio-crown {
    position: absolute;
    top: -18px;
    font-size: 22px;
    filter: drop-shadow(0 2px 4px rgba(0,0,0,0.5));
}

.gam-podio-name {
    font-size: 14px;
    font-weight: 600;
    color: var(--gam-text);
    margin-bottom: 2px;
    text-align: center;
    max-width: 120px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.gam-podio-pts {
    font-family: 'Cormorant Garamond', serif;
    font-size: 18px;
    font-weight: 700;
    color: var(--gam-cobre);
    margin-bottom: 10px;
}

.gam-podio-base {
    width: 100px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px 12px 4px 4px;
    position: relative;
    overflow: hidden;
}

.gam-podio-base::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    opacity: 0.5;
}

.gam-podio-item[data-pos="1"] .gam-podio-base {
    height: 100px;
    background: linear-gradient(180deg, rgba(232,201,74,0.25), rgba(232,201,74,0.08));
    border: 1px solid rgba(232,201,74,0.3);
}

.gam-podio-item[data-pos="2"] .gam-podio-base {
    height: 72px;
    background: linear-gradient(180deg, rgba(168,184,200,0.2), rgba(168,184,200,0.06));
    border: 1px solid rgba(168,184,200,0.2);
}

.gam-podio-item[data-pos="3"] .gam-podio-base {
    height: 52px;
    background: linear-gradient(180deg, rgba(200,120,64,0.2), rgba(200,120,64,0.06));
    border: 1px solid rgba(200,120,64,0.2);
}

.gam-podio-pos {
    font-family: 'Cormorant Garamond', serif;
    font-size: 36px;
    font-weight: 700;
    opacity: 0.4;
}

.gam-podio-item[data-pos="1"] .gam-podio-pos { color: var(--gam-gold); }
.gam-podio-item[data-pos="2"] .gam-podio-pos { color: var(--gam-silver); }
.gam-podio-item[data-pos="3"] .gam-podio-pos { color: var(--gam-bronze); }

/* ═══════════════ MEU CARD ═══════════════ */
.gam-my-card {
    background: linear-gradient(135deg, var(--gam-surface), var(--gam-mid));
    border: 1px solid rgba(200,135,58,0.25);
    border-radius: 20px;
    padding: 24px 28px;
    margin-bottom: 36px;
    display: flex;
    align-items: center;
    gap: 24px;
    position: relative;
    overflow: hidden;
    flex-wrap: wrap;
}

.gam-my-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: linear-gradient(135deg, rgba(200,135,58,0.06), transparent);
    pointer-events: none;
}

.gam-my-pos {
    font-family: 'Cormorant Garamond', serif;
    font-size: 64px;
    font-weight: 700;
    color: var(--gam-cobre);
    line-height: 1;
    min-width: 60px;
    text-align: center;
    position: relative;
    z-index: 1;
}

.gam-my-pos-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 2px;
    color: var(--gam-text-dim);
    text-align: center;
}

.gam-my-avatar {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    border: 2px solid var(--gam-cobre);
    background: linear-gradient(135deg, rgba(200,135,58,0.2), var(--gam-mid));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    font-weight: 700;
    color: var(--gam-cobre);
    position: relative;
    z-index: 1;
}

.gam-my-info {
    flex: 1;
    min-width: 150px;
    position: relative;
    z-index: 1;
}

.gam-my-name {
    font-size: 18px;
    font-weight: 600;
    color: var(--gam-text);
    margin-bottom: 2px;
}

.gam-my-level {
    font-size: 12px;
    color: var(--gam-cobre);
    display: flex;
    align-items: center;
    gap: 4px;
}

.gam-my-stats {
    display: flex;
    gap: 28px;
    position: relative;
    z-index: 1;
    flex-wrap: wrap;
}

.gam-my-stat {
    text-align: center;
}

.gam-my-stat-val {
    font-family: 'Cormorant Garamond', serif;
    font-size: 28px;
    font-weight: 700;
    color: var(--gam-text);
    line-height: 1;
}

.gam-my-stat-lbl {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: var(--gam-text-dim);
    margin-top: 2px;
}

/* ═══════════════ RANKING LIST ═══════════════ */
.gam-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.gam-list-item {
    display: grid;
    grid-template-columns: 50px 1fr 100px 140px;
    align-items: center;
    gap: 16px;
    padding: 14px 20px;
    background: var(--gam-glass);
    border: 1px solid rgba(255,255,255,0.04);
    border-radius: 14px;
    transition: all 0.3s ease;
}

.gam-list-item:hover {
    background: rgba(255,255,255,0.06);
    border-color: rgba(200,135,58,0.15);
    transform: translateX(4px);
}

.gam-list-item.is-me {
    background: rgba(200,135,58,0.08);
    border-color: rgba(200,135,58,0.25);
}

.gam-list-pos {
    font-family: 'Cormorant Garamond', serif;
    font-size: 24px;
    font-weight: 700;
    color: var(--gam-text-dim);
    text-align: center;
}

.gam-list-item:nth-child(1) .gam-list-pos { color: var(--gam-gold); }
.gam-list-item:nth-child(2) .gam-list-pos { color: var(--gam-silver); }
.gam-list-item:nth-child(3) .gam-list-pos { color: var(--gam-bronze); }

.gam-list-user {
    display: flex;
    align-items: center;
    gap: 12px;
    overflow: hidden;
}

.gam-list-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--gam-mid);
    border: 1px solid rgba(255,255,255,0.08);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 600;
    color: var(--gam-text-dim);
    flex-shrink: 0;
}

.gam-list-name {
    font-size: 14px;
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.gam-list-level {
    font-size: 11px;
    color: var(--gam-text-dim);
}

.gam-list-pts {
    font-family: 'Cormorant Garamond', serif;
    font-size: 22px;
    font-weight: 700;
    color: var(--gam-cobre);
    text-align: right;
}

.gam-list-bar-wrap {
    position: relative;
}

.gam-list-bar {
    height: 6px;
    background: rgba(255,255,255,0.04);
    border-radius: 3px;
    overflow: hidden;
}

.gam-list-bar-fill {
    height: 100%;
    border-radius: 3px;
    background: linear-gradient(90deg, var(--gam-cobre), var(--gam-gold));
    transition: width 1s ease;
}

.gam-list-bar-label {
    font-size: 10px;
    color: var(--gam-text-dim);
    text-align: right;
    margin-top: 2px;
}

/* ═══════════════ TAB PANELS ═══════════════ */
.gam-panel { display: none; }
.gam-panel.active { display: block; }

/* ═══════════════ SECTION TITLES ═══════════════ */
.gam-section-title {
    font-family: 'Outfit', sans-serif;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 3px;
    color: var(--gam-text-dim);
    margin: 0 0 16px;
    padding-bottom: 8px;
    border-bottom: 1px solid rgba(200,135,58,0.1);
}

/* ═══════════════ CARREIRA — NIVEL CARDS ═══════════════ */
.gam-nivel-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 12px;
    margin-bottom: 36px;
}

.gam-nivel-card {
    background: var(--gam-glass);
    border: 1px solid rgba(255,255,255,0.04);
    border-radius: 14px;
    padding: 18px 14px;
    text-align: center;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.gam-nivel-card:hover {
    border-color: rgba(200,135,58,0.15);
    background: rgba(255,255,255,0.06);
}

.gam-nivel-card.is-current {
    border-color: var(--gam-cobre);
    background: rgba(200,135,58,0.1);
    box-shadow: 0 0 30px rgba(200,135,58,0.15);
}

.gam-nivel-card.is-current::after {
    content: 'ATUAL';
    position: absolute;
    top: 8px; right: 8px;
    font-size: 8px;
    font-weight: 700;
    letter-spacing: 1px;
    padding: 2px 6px;
    border-radius: 4px;
    background: var(--gam-cobre);
    color: #fff;
}

.gam-nivel-emoji {
    font-size: 32px;
    margin-bottom: 8px;
    display: block;
}

.gam-nivel-name {
    font-size: 13px;
    font-weight: 600;
    color: var(--gam-text);
    margin-bottom: 4px;
}

.gam-nivel-pts {
    font-size: 11px;
    color: var(--gam-text-dim);
}

/* ═══════════════ HISTORICO — TIMELINE ═══════════════ */
.gam-timeline {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.gam-timeline-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 18px;
    background: var(--gam-glass);
    border: 1px solid rgba(255,255,255,0.03);
    border-radius: 12px;
    transition: all 0.3s ease;
}

.gam-timeline-item:hover {
    background: rgba(255,255,255,0.06);
    border-color: rgba(200,135,58,0.1);
}

.gam-timeline-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: var(--gam-mid);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}

.gam-timeline-body {
    flex: 1;
    overflow: hidden;
}

.gam-timeline-desc {
    font-size: 13px;
    color: var(--gam-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.gam-timeline-ref {
    font-size: 11px;
    color: var(--gam-text-dim);
}

.gam-timeline-date {
    font-size: 11px;
    color: var(--gam-text-dim);
    white-space: nowrap;
}

.gam-timeline-pts {
    font-family: 'Cormorant Garamond', serif;
    font-size: 20px;
    font-weight: 700;
    color: var(--gam-green);
    white-space: nowrap;
}

/* ═══════════════ PREMIACAO ═══════════════ */
.gam-premios-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 16px;
    margin-bottom: 36px;
}

.gam-premio-card {
    background: linear-gradient(135deg, var(--gam-surface), var(--gam-mid));
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 18px;
    padding: 24px;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.gam-premio-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
}

.gam-premio-card[data-place="1"]::before { background: linear-gradient(90deg, var(--gam-gold), var(--gam-cobre)); }
.gam-premio-card[data-place="2"]::before { background: linear-gradient(90deg, var(--gam-silver), #8898A8); }
.gam-premio-card[data-place="3"]::before { background: linear-gradient(90deg, var(--gam-bronze), #A85830); }

.gam-premio-place {
    font-family: 'Cormorant Garamond', serif;
    font-size: 42px;
    font-weight: 700;
    margin-bottom: 4px;
}

.gam-premio-card[data-place="1"] .gam-premio-place { color: var(--gam-gold); }
.gam-premio-card[data-place="2"] .gam-premio-place { color: var(--gam-silver); }
.gam-premio-card[data-place="3"] .gam-premio-place { color: var(--gam-bronze); }

.gam-premio-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 2px;
    color: var(--gam-text-dim);
    margin-bottom: 12px;
}

.gam-premio-desc {
    font-size: 16px;
    font-weight: 600;
    color: var(--gam-text);
    margin-bottom: 8px;
    min-height: 24px;
}

.gam-premio-user {
    font-size: 13px;
    color: var(--gam-cobre);
}

.gam-premio-status {
    margin-top: 12px;
    font-size: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.gam-premio-status.entregue { color: var(--gam-green); }
.gam-premio-status.pendente { color: var(--gam-text-dim); }

/* ═══════════════ ADMIN CONFIG FORM ═══════════════ */
.gam-admin-form {
    background: linear-gradient(135deg, var(--gam-surface), var(--gam-mid));
    border: 1px solid rgba(200,135,58,0.15);
    border-radius: 18px;
    padding: 28px;
    margin-top: 24px;
}

.gam-admin-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--gam-cobre);
    margin: 0 0 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.gam-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 20px;
}

.gam-form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.gam-form-group.full-width {
    grid-column: 1 / -1;
}

.gam-form-label {
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--gam-text-dim);
}

.gam-form-input {
    padding: 10px 14px;
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 10px;
    background: var(--gam-deep);
    color: var(--gam-text);
    font-family: 'Outfit', sans-serif;
    font-size: 14px;
    transition: border-color 0.3s;
}

.gam-form-input:focus {
    outline: none;
    border-color: var(--gam-cobre);
}

.gam-btn-save {
    padding: 12px 32px;
    border: none;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--gam-cobre), #A06A28);
    color: #fff;
    font-family: 'Outfit', sans-serif;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.gam-btn-save:hover {
    box-shadow: 0 4px 20px rgba(200,135,58,0.4);
    transform: translateY(-1px);
}

.gam-btn-save:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

/* ═══════════════ ADMIN CHECKBOX ═══════════════ */
.gam-check-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--gam-text-dim);
    cursor: pointer;
    padding: 8px 0;
}

.gam-check-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: var(--gam-cobre);
}

/* ═══════════════ POPUP PONTOS ═══════════════ */
.gam-popup {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    background: linear-gradient(135deg, var(--gam-surface), var(--gam-mid));
    border: 1px solid var(--gam-cobre);
    border-radius: 16px;
    padding: 16px 24px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 8px 40px rgba(0,0,0,0.5), 0 0 30px rgba(200,135,58,0.2);
    animation: gam-popIn 0.5s ease both;
    min-width: 280px;
}

@keyframes gam-popIn {
    0% { opacity: 0; transform: translateX(100px) scale(0.9); }
    100% { opacity: 1; transform: translateX(0) scale(1); }
}

@keyframes gam-popOut {
    0% { opacity: 1; transform: translateX(0) scale(1); }
    100% { opacity: 0; transform: translateX(100px) scale(0.9); }
}

.gam-popup-icon {
    font-size: 28px;
}

.gam-popup-text {
    flex: 1;
}

.gam-popup-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--gam-text);
}

.gam-popup-pts {
    font-family: 'Cormorant Garamond', serif;
    font-size: 22px;
    font-weight: 700;
    color: var(--gam-green);
}

/* ═══════════════ EMPTY STATE ═══════════════ */
.gam-empty {
    text-align: center;
    padding: 60px 20px;
    color: var(--gam-text-dim);
}

.gam-empty-icon {
    font-size: 48px;
    margin-bottom: 12px;
    opacity: 0.3;
}

.gam-empty-text {
    font-size: 15px;
}

/* ═══════════════ RESPONSIVE ═══════════════ */
@media (max-width: 768px) {
    .gam-content { padding: 16px 12px 40px; }
    .gam-hero-month { font-size: 36px; }
    .gam-list-item { grid-template-columns: 40px 1fr 70px; gap: 10px; padding: 10px 14px; }
    .gam-list-bar-wrap { display: none; }
    .gam-my-card { padding: 16px; gap: 14px; }
    .gam-my-pos { font-size: 44px; min-width: 45px; }
    .gam-my-stats { gap: 16px; }
    .gam-podio { gap: 10px; }
    .gam-podio-base { width: 80px; }
    .gam-form-grid { grid-template-columns: 1fr; }
    .gam-nivel-grid { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); }
    .gam-premios-grid { grid-template-columns: 1fr; }
}
</style>

<div class="gam-wrap">
    <div class="gam-bg"></div>
    <div class="gam-grid-overlay"></div>
    <canvas id="gamParticles"></canvas>

    <div class="gam-content">

        <!-- TABS -->
        <div class="gam-tabs">
            <button class="gam-tab-btn active" data-tab="mensal">Mensal</button>
            <button class="gam-tab-btn" data-tab="carreira">Carreira</button>
            <button class="gam-tab-btn" data-tab="historico">Hist&oacute;rico</button>
            <?php if ($isAdmin): ?>
            <button class="gam-tab-btn" data-tab="premiacao">Premia&ccedil;&atilde;o</button>
            <?php endif; ?>
        </div>

        <!-- ════════════════ TAB MENSAL ════════════════ -->
        <div class="gam-panel active" id="panelMensal">

            <!-- Hero -->
            <div class="gam-hero">
                <p class="gam-hero-title">Ranking do Escrit&oacute;rio</p>
                <h2 class="gam-hero-month"><?= e($meses[$mesAtual]) ?></h2>
                <p class="gam-hero-sub"><?= e($anoAtual) ?> &mdash; <?= e(ucfirst($areaAtual)) ?></p>
            </div>

            <!-- Meta Card -->
            <div class="gam-meta-card">
                <div>
                    <div class="gam-meta-label">Meta Contratos</div>
                    <div class="gam-meta-value" id="metaValue"><?= $totalContratos ?>/<?= $metaPrincipal ?></div>
                </div>
                <div class="gam-meta-bar-wrap">
                    <div class="gam-meta-bar">
                        <div class="gam-meta-bar-fill" style="width:<?= $metaPct ?>%"></div>
                    </div>
                    <div class="gam-meta-pct"><?= $metaPct ?>% da meta</div>
                </div>
            </div>

            <!-- Area Toggle -->
            <?php if (count($areas) > 1): ?>
            <div class="gam-area-toggle" id="areaToggle">
                <?php foreach ($areas as $a): ?>
                <button class="gam-area-btn<?= $a === $areaAtual ? ' active' : '' ?>" data-area="<?= e($a) ?>">
                    <?= $a === 'comercial' ? '&#129309; Comercial' : '&#9878;&#65039; Operacional' ?>
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Podio -->
            <div class="gam-podio" id="podioContainer">
                <?php
                $podioOrder = array();
                if (isset($ranking[1])) $podioOrder[] = array('pos' => 2, 'data' => $ranking[1]);
                if (isset($ranking[0])) $podioOrder[] = array('pos' => 1, 'data' => $ranking[0]);
                if (isset($ranking[2])) $podioOrder[] = array('pos' => 3, 'data' => $ranking[2]);

                foreach ($podioOrder as $pi):
                    $p = $pi['data'];
                    $pos = $pi['pos'];
                    $initial = mb_strtoupper(mb_substr($p['name'], 0, 1));
                    $pts = (int)($p[$campo_mes] ?? 0);
                ?>
                <div class="gam-podio-item" data-pos="<?= $pos ?>">
                    <div class="gam-podio-avatar">
                        <?php if ($pos === 1): ?><span class="gam-podio-crown">&#128081;</span><?php endif; ?>
                        <?= e($initial) ?>
                    </div>
                    <div class="gam-podio-name"><?= e(explode(' ', $p['name'])[0]) ?></div>
                    <div class="gam-podio-pts"><?= number_format($pts, 0, ',', '.') ?> pts</div>
                    <div class="gam-podio-base">
                        <span class="gam-podio-pos"><?= $pos ?>&ordm;</span>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if (empty($podioOrder)): ?>
                <div class="gam-empty">
                    <div class="gam-empty-icon">&#127942;</div>
                    <div class="gam-empty-text">Nenhum dado de ranking ainda este m&ecirc;s.</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Meu Card -->
            <p class="gam-section-title">Meu Desempenho</p>
            <div class="gam-my-card" id="myCard">
                <div>
                    <div class="gam-my-pos" id="myPosNum"><?= $myPos > 0 ? $myPos : '-' ?></div>
                    <div class="gam-my-pos-label">posi&ccedil;&atilde;o</div>
                </div>
                <div class="gam-my-avatar"><?= e(mb_strtoupper(mb_substr($userName, 0, 1))) ?></div>
                <div class="gam-my-info">
                    <div class="gam-my-name"><?= e(explode(' ', $userName)[0]) ?></div>
                    <div class="gam-my-level"><?= $myBadge ? e($myBadge) . ' ' : '' ?><?= e($myNivel) ?></div>
                </div>
                <div class="gam-my-stats">
                    <div class="gam-my-stat">
                        <div class="gam-my-stat-val" id="myPontos"><?= number_format($myPontosMes, 0, ',', '.') ?></div>
                        <div class="gam-my-stat-lbl">Pontos</div>
                    </div>
                    <div class="gam-my-stat">
                        <div class="gam-my-stat-val" id="myContratos"><?= $myContratos ?></div>
                        <div class="gam-my-stat-lbl">Contratos</div>
                    </div>
                    <div class="gam-my-stat">
                        <div class="gam-my-stat-val" id="myMetaPct"><?= $metaPrincipal > 0 ? round(($myContratos / $metaPrincipal) * 100) : 0 ?>%</div>
                        <div class="gam-my-stat-lbl">Meta</div>
                    </div>
                </div>
            </div>

            <!-- Full List -->
            <p class="gam-section-title">Ranking Completo</p>
            <div class="gam-list" id="rankingList">
                <?php
                $posCount = 0;
                foreach ($ranking as $r):
                    $posCount++;
                    $rPts = (int)($r[$campo_mes] ?? 0);
                    $rContratos = (int)($r['contratos_mes'] ?? 0);
                    $rNivel = $r['nivel'] ?? 'Iniciante';
                    $rBadge = $r['badge_emoji'] ?? '';
                    $rInitial = mb_strtoupper(mb_substr($r['name'], 0, 1));
                    $isMe = ((int)$r['user_id'] === $userId);
                    $rMetaPct = $metaPrincipal > 0 ? min(100, round(($rContratos / $metaPrincipal) * 100)) : 0;
                    $maxPts = isset($ranking[0]) ? max(1, (int)($ranking[0][$campo_mes] ?? 1)) : 1;
                    $barW = $maxPts > 0 ? round(($rPts / $maxPts) * 100) : 0;
                ?>
                <div class="gam-list-item<?= $isMe ? ' is-me' : '' ?>">
                    <div class="gam-list-pos"><?= $posCount ?>&ordm;</div>
                    <div class="gam-list-user">
                        <div class="gam-list-avatar"><?= e($rInitial) ?></div>
                        <div>
                            <div class="gam-list-name"><?= e($r['name']) ?></div>
                            <div class="gam-list-level"><?= $rBadge ? e($rBadge) . ' ' : '' ?><?= e($rNivel) ?></div>
                        </div>
                    </div>
                    <div class="gam-list-pts"><?= number_format($rPts, 0, ',', '.') ?></div>
                    <div class="gam-list-bar-wrap">
                        <div class="gam-list-bar">
                            <div class="gam-list-bar-fill" style="width:<?= $barW ?>%"></div>
                        </div>
                        <div class="gam-list-bar-label"><?= $rMetaPct ?>% meta</div>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if (empty($ranking)): ?>
                <div class="gam-empty">
                    <div class="gam-empty-icon">&#128202;</div>
                    <div class="gam-empty-text">Nenhum participante no ranking ainda.</div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ════════════════ TAB CARREIRA ════════════════ -->
        <div class="gam-panel" id="panelCarreira">

            <div class="gam-hero">
                <p class="gam-hero-title">N&iacute;veis de Carreira</p>
                <h2 class="gam-hero-month">Evolu&ccedil;&atilde;o</h2>
            </div>

            <!-- Nivel Cards -->
            <p class="gam-section-title">N&iacute;veis</p>
            <div class="gam-nivel-grid" id="nivelGrid">
                <?php foreach ($niveis as $nv):
                    $isCurrent = ((int)($nv['nivel_num'] ?? 0) === $myNivelNum);
                ?>
                <div class="gam-nivel-card<?= $isCurrent ? ' is-current' : '' ?>">
                    <span class="gam-nivel-emoji"><?= e($nv['badge_emoji'] ?? '') ?></span>
                    <div class="gam-nivel-name"><?= e($nv['nivel'] ?? $nv['nome'] ?? 'N' . $nv['nivel_num']) ?></div>
                    <div class="gam-nivel-pts"><?= number_format((int)($nv['pontos_min'] ?? 0), 0, ',', '.') ?> pts</div>
                </div>
                <?php endforeach; ?>

                <?php if (empty($niveis)): ?>
                <div class="gam-empty">
                    <div class="gam-empty-icon">&#127775;</div>
                    <div class="gam-empty-text">N&iacute;veis ainda n&atilde;o configurados.</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Ranking Carreira (total pts) -->
            <p class="gam-section-title">Ranking por Pontos Totais</p>
            <div class="gam-list" id="rankingCarreira">
                <?php
                // Sort by total pts
                usort($ranking, function($a, $b) use ($campo_total) {
                    return (int)($b[$campo_total] ?? 0) - (int)($a[$campo_total] ?? 0);
                });
                $posCount = 0;
                foreach ($ranking as $r):
                    $posCount++;
                    $rPtsTotal = (int)($r[$campo_total] ?? 0);
                    $rNivel = $r['nivel'] ?? 'Iniciante';
                    $rBadge = $r['badge_emoji'] ?? '';
                    $rInitial = mb_strtoupper(mb_substr($r['name'], 0, 1));
                    $isMe = ((int)$r['user_id'] === $userId);
                    $maxTotal = isset($ranking[0]) ? max(1, (int)($ranking[0][$campo_total] ?? 1)) : 1;
                    $barW = $maxTotal > 0 ? round(($rPtsTotal / $maxTotal) * 100) : 0;
                ?>
                <div class="gam-list-item<?= $isMe ? ' is-me' : '' ?>">
                    <div class="gam-list-pos"><?= $posCount ?>&ordm;</div>
                    <div class="gam-list-user">
                        <div class="gam-list-avatar"><?= e($rInitial) ?></div>
                        <div>
                            <div class="gam-list-name"><?= e($r['name']) ?></div>
                            <div class="gam-list-level"><?= $rBadge ? e($rBadge) . ' ' : '' ?><?= e($rNivel) ?></div>
                        </div>
                    </div>
                    <div class="gam-list-pts"><?= number_format($rPtsTotal, 0, ',', '.') ?></div>
                    <div class="gam-list-bar-wrap">
                        <div class="gam-list-bar">
                            <div class="gam-list-bar-fill" style="width:<?= $barW ?>%"></div>
                        </div>
                        <div class="gam-list-bar-label">total</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ════════════════ TAB HISTORICO ════════════════ -->
        <div class="gam-panel" id="panelHistorico">

            <div class="gam-hero">
                <p class="gam-hero-title">Meu Hist&oacute;rico</p>
                <h2 class="gam-hero-month">Pontos</h2>
            </div>

            <div class="gam-timeline" id="timelineContainer">
                <?php if (empty($histData)): ?>
                <div class="gam-empty">
                    <div class="gam-empty-icon">&#128337;</div>
                    <div class="gam-empty-text">Nenhum evento de pontua&ccedil;&atilde;o registrado ainda.</div>
                </div>
                <?php else: ?>
                <?php foreach ($histData as $ev):
                    $evPts = (int)($ev['pontos'] ?? 0);
                    $evDesc = $ev['descricao'] ?? $ev['tipo'] ?? 'Pontos';
                    $evRef = $ev['referencia'] ?? $ev['case_id'] ?? '';
                    $evDate = $ev['created_at'] ?? '';
                    $evIcon = '&#11088;';
                    $tipo = strtolower($ev['tipo'] ?? '');
                    if (strpos($tipo, 'contrato') !== false) $evIcon = '&#128196;';
                    elseif (strpos($tipo, 'audiencia') !== false || strpos($tipo, 'audiência') !== false) $evIcon = '&#9878;&#65039;';
                    elseif (strpos($tipo, 'peticao') !== false || strpos($tipo, 'petição') !== false) $evIcon = '&#128221;';
                    elseif (strpos($tipo, 'manual') !== false) $evIcon = '&#127873;';
                    elseif (strpos($tipo, 'bonus') !== false || strpos($tipo, 'bônus') !== false) $evIcon = '&#128293;';
                ?>
                <div class="gam-timeline-item">
                    <div class="gam-timeline-icon"><?= $evIcon ?></div>
                    <div class="gam-timeline-body">
                        <div class="gam-timeline-desc"><?= e($evDesc) ?></div>
                        <?php if ($evRef): ?>
                        <div class="gam-timeline-ref"><?= e($evRef) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="gam-timeline-date"><?= $evDate ? date('d/m H:i', strtotime($evDate)) : '' ?></div>
                    <div class="gam-timeline-pts">+<?= number_format($evPts, 0, ',', '.') ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ════════════════ TAB PREMIACAO ════════════════ -->
        <?php if ($isAdmin): ?>
        <div class="gam-panel" id="panelPremiacao">

            <div class="gam-hero">
                <p class="gam-hero-title">Premia&ccedil;&atilde;o</p>
                <h2 class="gam-hero-month"><?= e($meses[$mesAtual]) ?></h2>
            </div>

            <!-- Prize Cards -->
            <p class="gam-section-title">Pr&ecirc;mios do M&ecirc;s</p>
            <div class="gam-premios-grid" id="premiosGrid">
                <?php
                $premioLabels = array(1 => '1&ordm; Lugar', 2 => '2&ordm; Lugar', 3 => '3&ordm; Lugar');
                $configPremios = array(
                    1 => $metaData ? ($metaData['premio_1'] ?? '') : '',
                    2 => $metaData ? ($metaData['premio_2'] ?? '') : '',
                    3 => $metaData ? ($metaData['premio_3'] ?? '') : '',
                );

                for ($place = 1; $place <= 3; $place++):
                    $premioUser = null;
                    foreach ($premiosData as $pd) {
                        if ((int)($pd['posicao'] ?? 0) === $place) {
                            $premioUser = $pd;
                            break;
                        }
                    }
                ?>
                <div class="gam-premio-card" data-place="<?= $place ?>">
                    <div class="gam-premio-place"><?= $place ?>&ordm;</div>
                    <div class="gam-premio-label"><?= $premioLabels[$place] ?></div>
                    <div class="gam-premio-desc"><?= e($configPremios[$place]) ?: '<span style="color:var(--gam-text-dim)">N&atilde;o definido</span>' ?></div>
                    <?php if ($premioUser): ?>
                    <div class="gam-premio-user"><?= e($premioUser['user_name'] ?? 'Aguardando') ?></div>
                    <div class="gam-premio-status <?= !empty($premioUser['entregue']) ? 'entregue' : 'pendente' ?>">
                        <?php if (!empty($premioUser['entregue'])): ?>
                            &#9989; Entregue
                        <?php else: ?>
                            &#9203; Pendente
                            <label class="gam-check-label" style="margin:0; padding:0;">
                                <input type="checkbox" onchange="marcarEntregue(<?= (int)$premioUser['id'] ?>, this)"> Marcar entregue
                            </label>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="gam-premio-user" style="color:var(--gam-text-dim)">Aguardando resultado</div>
                    <?php endif; ?>
                </div>
                <?php endfor; ?>
            </div>

            <!-- Admin Config Form -->
            <div class="gam-admin-form">
                <h3 class="gam-admin-title">&#9881;&#65039; Configurar M&ecirc;s</h3>
                <form id="formConfig" onsubmit="salvarConfig(event)">
                    <div class="gam-form-grid">
                        <div class="gam-form-group">
                            <label class="gam-form-label">M&ecirc;s</label>
                            <select class="gam-form-input" name="mes" id="cfgMes">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $m === $mesAtual ? 'selected' : '' ?>><?= e($meses[$m]) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="gam-form-group">
                            <label class="gam-form-label">Ano</label>
                            <input class="gam-form-input" type="number" name="ano" id="cfgAno" value="<?= $anoAtual ?>" min="2024" max="2030">
                        </div>
                        <div class="gam-form-group">
                            <label class="gam-form-label">&Aacute;rea</label>
                            <select class="gam-form-input" name="area" id="cfgArea">
                                <option value="comercial" <?= $areaAtual === 'comercial' ? 'selected' : '' ?>>Comercial</option>
                                <option value="operacional" <?= $areaAtual === 'operacional' ? 'selected' : '' ?>>Operacional</option>
                            </select>
                        </div>
                        <div class="gam-form-group">
                            <label class="gam-form-label">Meta de Contratos</label>
                            <input class="gam-form-input" type="number" name="meta_principal" id="cfgMetaPrincipal" value="<?= $metaPrincipal ?>" min="0">
                        </div>
                        <div class="gam-form-group">
                            <label class="gam-form-label">Pr&ecirc;mio 1&ordm; Lugar</label>
                            <input class="gam-form-input" type="text" name="premio_1" id="cfgPremio1" value="<?= e($configPremios[1]) ?>" placeholder="Ex: R$500 + troféu">
                        </div>
                        <div class="gam-form-group">
                            <label class="gam-form-label">Pr&ecirc;mio 2&ordm; Lugar</label>
                            <input class="gam-form-input" type="text" name="premio_2" id="cfgPremio2" value="<?= e($configPremios[2]) ?>" placeholder="Ex: R$300">
                        </div>
                        <div class="gam-form-group">
                            <label class="gam-form-label">Pr&ecirc;mio 3&ordm; Lugar</label>
                            <input class="gam-form-input" type="text" name="premio_3" id="cfgPremio3" value="<?= e($configPremios[3]) ?>" placeholder="Ex: R$200">
                        </div>
                        <div class="gam-form-group">
                            <label class="gam-form-label">Meta de Pontos</label>
                            <input class="gam-form-input" type="number" name="meta_pontos" id="cfgMetaPontos" value="<?= $metaData ? (int)($metaData['meta_pontos'] ?? 0) : 0 ?>" min="0">
                        </div>
                    </div>
                    <button type="submit" class="gam-btn-save" id="btnSaveConfig">Salvar Configura&ccedil;&atilde;o</button>
                    <span id="cfgMsg" style="margin-left:12px; font-size:13px; color:var(--gam-green);"></span>
                </form>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- .gam-content -->
</div><!-- .gam-wrap -->

<script>
(function() {
    'use strict';

    var apiUrl = '<?= $apiUrl ?>';
    var csrfToken = '<?= $csrfToken ?>';
    var currentArea = '<?= e($areaAtual) ?>';
    var currentUserId = <?= (int)$userId ?>;
    var isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
    var mesAtual = <?= $mesAtual ?>;
    var anoAtual = <?= $anoAtual ?>;
    var lastEventCheck = Date.now();

    // ═══════════════ PARTICLES ═══════════════
    function initParticles() {
        var canvas = document.getElementById('gamParticles');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        var particles = [];
        var count = 40;

        function resize() {
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
        }
        resize();
        window.addEventListener('resize', resize);

        for (var i = 0; i < count; i++) {
            particles.push({
                x: Math.random() * canvas.width,
                y: Math.random() * canvas.height,
                r: Math.random() * 2 + 0.5,
                speed: Math.random() * 0.4 + 0.1,
                opacity: Math.random() * 0.4 + 0.1,
                drift: (Math.random() - 0.5) * 0.3
            });
        }

        function draw() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            for (var i = 0; i < particles.length; i++) {
                var p = particles[i];
                ctx.beginPath();
                ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(200, 135, 58, ' + p.opacity + ')';
                ctx.fill();

                p.y -= p.speed;
                p.x += p.drift;

                if (p.y < -10) {
                    p.y = canvas.height + 10;
                    p.x = Math.random() * canvas.width;
                }
                if (p.x < -10) p.x = canvas.width + 10;
                if (p.x > canvas.width + 10) p.x = -10;
            }
            requestAnimationFrame(draw);
        }
        draw();
    }

    // ═══════════════ TAB SWITCHING ═══════════════
    function initTabs() {
        var btns = document.querySelectorAll('.gam-tab-btn');
        for (var i = 0; i < btns.length; i++) {
            btns[i].addEventListener('click', function() {
                var tab = this.getAttribute('data-tab');

                // Update buttons
                var allBtns = document.querySelectorAll('.gam-tab-btn');
                for (var j = 0; j < allBtns.length; j++) {
                    allBtns[j].classList.remove('active');
                }
                this.classList.add('active');

                // Update panels
                var panels = document.querySelectorAll('.gam-panel');
                for (var k = 0; k < panels.length; k++) {
                    panels[k].classList.remove('active');
                }

                var targetId = 'panel' + tab.charAt(0).toUpperCase() + tab.slice(1);
                var target = document.getElementById(targetId);
                if (target) target.classList.add('active');
            });
        }
    }

    // ═══════════════ AREA TOGGLE ═══════════════
    function initAreaToggle() {
        var container = document.getElementById('areaToggle');
        if (!container) return;

        var btns = container.querySelectorAll('.gam-area-btn');
        for (var i = 0; i < btns.length; i++) {
            btns[i].addEventListener('click', function() {
                var area = this.getAttribute('data-area');
                if (area === currentArea) return;

                currentArea = area;
                var allBtns = container.querySelectorAll('.gam-area-btn');
                for (var j = 0; j < allBtns.length; j++) {
                    allBtns[j].classList.remove('active');
                }
                this.classList.add('active');

                loadRanking(area);
                loadMeuCard(area);
            });
        }
    }

    // ═══════════════ AJAX: RANKING MENSAL ═══════════════
    function loadRanking(area) {
        fetch(apiUrl + '?action=ranking_mensal&area=' + encodeURIComponent(area))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                renderPodio(data.ranking || []);
                renderRankingList(data.ranking || [], data.meta || null, 'mensal');
                renderMeta(data.ranking || [], data.meta || null);
            })
            .catch(function(err) { console.error('Erro ranking:', err); });
    }

    // ═══════════════ AJAX: MEU CARD ═══════════════
    function loadMeuCard(area) {
        fetch(apiUrl + '?action=meu_card&area=' + encodeURIComponent(area))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var posEl = document.getElementById('myPosNum');
                var ptsEl = document.getElementById('myPontos');
                var ctrEl = document.getElementById('myContratos');
                var metaEl = document.getElementById('myMetaPct');

                if (posEl) posEl.textContent = data.posicao > 0 ? data.posicao : '-';
                if (ptsEl) ptsEl.textContent = formatNum(data.pontos_mes || 0);
                if (ctrEl) ctrEl.textContent = data.contratos_mes || 0;
                if (metaEl) metaEl.textContent = (data.meta_pct || 0) + '%';
            })
            .catch(function(err) { console.error('Erro meu card:', err); });
    }

    // ═══════════════ RENDER: PODIO ═══════════════
    function renderPodio(ranking) {
        var container = document.getElementById('podioContainer');
        if (!container) return;

        if (ranking.length === 0) {
            container.innerHTML = '<div class="gam-empty"><div class="gam-empty-icon">&#127942;</div><div class="gam-empty-text">Nenhum dado de ranking ainda.</div></div>';
            return;
        }

        var order = [];
        if (ranking[1]) order.push({pos: 2, data: ranking[1]});
        if (ranking[0]) order.push({pos: 1, data: ranking[0]});
        if (ranking[2]) order.push({pos: 3, data: ranking[2]});

        var html = '';
        for (var i = 0; i < order.length; i++) {
            var item = order[i];
            var r = item.data;
            var pos = item.pos;
            var initial = (r.name || 'U').charAt(0).toUpperCase();
            var pts = parseInt(r.pontos || 0, 10);
            var firstName = (r.name || '').split(' ')[0];

            html += '<div class="gam-podio-item" data-pos="' + pos + '">';
            html += '<div class="gam-podio-avatar">';
            if (pos === 1) html += '<span class="gam-podio-crown">&#128081;</span>';
            html += escHtml(initial) + '</div>';
            html += '<div class="gam-podio-name">' + escHtml(firstName) + '</div>';
            html += '<div class="gam-podio-pts">' + formatNum(pts) + ' pts</div>';
            html += '<div class="gam-podio-base"><span class="gam-podio-pos">' + pos + '&ordm;</span></div>';
            html += '</div>';
        }
        container.innerHTML = html;
    }

    // ═══════════════ RENDER: RANKING LIST ═══════════════
    function renderRankingList(ranking, meta, mode) {
        var containerId = mode === 'mensal' ? 'rankingList' : 'rankingCarreira';
        var container = document.getElementById(containerId);
        if (!container) return;

        if (ranking.length === 0) {
            container.innerHTML = '<div class="gam-empty"><div class="gam-empty-icon">&#128202;</div><div class="gam-empty-text">Nenhum participante no ranking.</div></div>';
            return;
        }

        var metaPrincipal = meta ? parseInt(meta.meta_principal || 0, 10) : 0;
        var maxPts = Math.max(1, parseInt(ranking[0].pontos || 1, 10));
        var html = '';

        for (var i = 0; i < ranking.length; i++) {
            var r = ranking[i];
            var pts = parseInt(r.pontos || 0, 10);
            var contratos = parseInt(r.contratos_mes || 0, 10);
            var nivel = r.nivel || 'Iniciante';
            var badge = r.badge_emoji || '';
            var initial = (r.name || 'U').charAt(0).toUpperCase();
            var isMe = (parseInt(r.user_id, 10) === currentUserId);
            var barW = Math.round((pts / maxPts) * 100);
            var metaPct = metaPrincipal > 0 ? Math.min(100, Math.round((contratos / metaPrincipal) * 100)) : 0;
            var barLabel = mode === 'mensal' ? (metaPct + '% meta') : 'total';

            html += '<div class="gam-list-item' + (isMe ? ' is-me' : '') + '">';
            html += '<div class="gam-list-pos">' + (i + 1) + '&ordm;</div>';
            html += '<div class="gam-list-user">';
            html += '<div class="gam-list-avatar">' + escHtml(initial) + '</div>';
            html += '<div><div class="gam-list-name">' + escHtml(r.name || '') + '</div>';
            html += '<div class="gam-list-level">' + escHtml(badge ? badge + ' ' : '') + escHtml(nivel) + '</div></div></div>';
            html += '<div class="gam-list-pts">' + formatNum(pts) + '</div>';
            html += '<div class="gam-list-bar-wrap">';
            html += '<div class="gam-list-bar"><div class="gam-list-bar-fill" style="width:' + barW + '%"></div></div>';
            html += '<div class="gam-list-bar-label">' + barLabel + '</div></div>';
            html += '</div>';
        }
        container.innerHTML = html;
    }

    // ═══════════════ RENDER: META ═══════════════
    function renderMeta(ranking, meta) {
        var metaPrincipal = meta ? parseInt(meta.meta_principal || 0, 10) : 0;
        var totalContratos = 0;
        for (var i = 0; i < ranking.length; i++) {
            totalContratos += parseInt(ranking[i].contratos_mes || 0, 10);
        }
        var pct = metaPrincipal > 0 ? Math.min(100, Math.round((totalContratos / metaPrincipal) * 100)) : 0;

        var valEl = document.getElementById('metaValue');
        if (valEl) valEl.textContent = totalContratos + '/' + metaPrincipal;

        var barFill = document.querySelector('.gam-meta-bar-fill');
        if (barFill) barFill.style.width = pct + '%';

        var pctEl = document.querySelector('.gam-meta-pct');
        if (pctEl) pctEl.textContent = pct + '% da meta';
    }

    // ═══════════════ POLLING: CHECK EVENTOS ═══════════════
    function pollEventos() {
        fetch(apiUrl + '?action=check_eventos')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var eventos = data.eventos || [];
                for (var i = 0; i < eventos.length; i++) {
                    mostrarPopupPontos(eventos[i]);
                }
            })
            .catch(function(err) { console.error('Poll error:', err); });
    }

    var popupQueue = [];
    var popupActive = false;

    function mostrarPopupPontos(ev) {
        popupQueue.push(ev);
        if (!popupActive) processPopupQueue();
    }

    function processPopupQueue() {
        if (popupQueue.length === 0) {
            popupActive = false;
            return;
        }
        popupActive = true;
        var ev = popupQueue.shift();

        var popup = document.createElement('div');
        popup.className = 'gam-popup';
        popup.innerHTML =
            '<div class="gam-popup-icon">&#11088;</div>' +
            '<div class="gam-popup-text">' +
                '<div class="gam-popup-title">' + escHtml(ev.descricao || ev.tipo || 'Pontos') + '</div>' +
                '<div class="gam-popup-pts">+' + formatNum(parseInt(ev.pontos || 0, 10)) + ' pts</div>' +
            '</div>';

        document.body.appendChild(popup);

        setTimeout(function() {
            popup.style.animation = 'gam-popOut 0.4s ease both';
            setTimeout(function() {
                if (popup.parentNode) popup.parentNode.removeChild(popup);
                processPopupQueue();
            }, 400);
        }, 3500);
    }

    // ═══════════════ ADMIN: SALVAR CONFIG ═══════════════
    window.salvarConfig = function(e) {
        e.preventDefault();
        var btn = document.getElementById('btnSaveConfig');
        var msg = document.getElementById('cfgMsg');
        btn.disabled = true;
        msg.textContent = 'Salvando...';
        msg.style.color = 'var(--gam-text-dim)';

        var formData = new FormData();
        formData.append('action', 'config_salvar');
        formData.append('csrf_token', csrfToken);
        formData.append('mes', document.getElementById('cfgMes').value);
        formData.append('ano', document.getElementById('cfgAno').value);
        formData.append('area', document.getElementById('cfgArea').value);
        formData.append('meta_principal', document.getElementById('cfgMetaPrincipal').value);
        formData.append('meta_pontos', document.getElementById('cfgMetaPontos').value);
        formData.append('premio_1', document.getElementById('cfgPremio1').value);
        formData.append('premio_2', document.getElementById('cfgPremio2').value);
        formData.append('premio_3', document.getElementById('cfgPremio3').value);

        fetch(apiUrl, {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            if (data.ok) {
                msg.textContent = 'Salvo com sucesso!';
                msg.style.color = 'var(--gam-green)';
                loadRanking(currentArea);
            } else {
                msg.textContent = data.error || 'Erro ao salvar';
                msg.style.color = '#e74c3c';
            }
            setTimeout(function() { msg.textContent = ''; }, 4000);
        })
        .catch(function(err) {
            btn.disabled = false;
            msg.textContent = 'Erro de conexão';
            msg.style.color = '#e74c3c';
        });
    };

    // ═══════════════ ADMIN: MARCAR ENTREGUE ═══════════════
    window.marcarEntregue = function(premioId, checkbox) {
        if (!confirm('Confirmar entrega deste prêmio?')) {
            checkbox.checked = false;
            return;
        }

        var formData = new FormData();
        formData.append('action', 'premio_entregue');
        formData.append('csrf_token', csrfToken);
        formData.append('premio_id', premioId);

        fetch(apiUrl, {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                var label = checkbox.closest('.gam-premio-status');
                if (label) {
                    label.className = 'gam-premio-status entregue';
                    label.innerHTML = '&#9989; Entregue';
                }
            } else {
                alert(data.error || 'Erro ao marcar');
                checkbox.checked = false;
            }
        })
        .catch(function() {
            alert('Erro de conexão');
            checkbox.checked = false;
        });
    };

    // ═══════════════ HELPERS ═══════════════
    function formatNum(n) {
        return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // ═══════════════ ANIMATE BARS ON SCROLL ═══════════════
    function animateBarsOnView() {
        var bars = document.querySelectorAll('.gam-list-bar-fill, .gam-meta-bar-fill');
        var observer = null;

        if ('IntersectionObserver' in window) {
            observer = new IntersectionObserver(function(entries) {
                for (var i = 0; i < entries.length; i++) {
                    if (entries[i].isIntersecting) {
                        var bar = entries[i].target;
                        var w = bar.getAttribute('data-width') || bar.style.width;
                        bar.style.width = '0%';
                        setTimeout((function(b, width) {
                            return function() { b.style.width = width; };
                        })(bar, w), 100);
                        observer.unobserve(bar);
                    }
                }
            }, { threshold: 0.1 });

            for (var i = 0; i < bars.length; i++) {
                bars[i].setAttribute('data-width', bars[i].style.width);
                observer.observe(bars[i]);
            }
        }
    }

    // ═══════════════ INIT ═══════════════
    document.addEventListener('DOMContentLoaded', function() {
        initParticles();
        initTabs();
        initAreaToggle();
        animateBarsOnView();

        // Polling every 10 seconds
        setInterval(pollEventos, 10000);
    });

})();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
