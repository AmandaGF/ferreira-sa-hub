<?php
/**
 * Agenda — Visualizações mensal, semanal e lista do dia
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$pageTitle = 'Agenda';
$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

// Mapa tipo → cor / ícone / label
$tiposMapa = array(
    'audiencia'        => array('cor' => '#052228', 'icon' => "\u{2696}", 'label' => 'Audiência'),
    'reuniao_cliente'  => array('cor' => '#B87333', 'icon' => "\u{1F464}", 'label' => 'Reunião com cliente'),
    'prazo'            => array('cor' => '#CC0000', 'icon' => "\u{23F0}", 'label' => 'Prazo processual'),
    'onboarding'       => array('cor' => '#2D7A4F', 'icon' => "\u{1F3AF}", 'label' => 'Onboarding'),
    'reuniao_interna'  => array('cor' => '#1a3a7a', 'icon' => "\u{1F465}", 'label' => 'Reunião interna'),
    'mediacao_cejusc'  => array('cor' => '#6B4C9A', 'icon' => "\u{1F91D}", 'label' => 'Mediação / CEJUSC'),
    'ligacao'          => array('cor' => '#888880', 'icon' => "\u{1F4DE}", 'label' => 'Ligação / Retorno'),
);

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
/* ── VARIÁVEIS ── */
.ag { --audiencia:#052228;--reuniao:#B87333;--prazo:#CC0000;--onboarding:#2D7A4F;--interna:#1a3a7a;--mediacao:#6B4C9A;--ligacao:#888880;--cobre-suave:#F5EDE3; }

/* ── TOPO ── */
.ag-topo { display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px; }
.ag-topo-esq { display:flex;align-items:center;gap:16px;flex-wrap:wrap; }
.ag-nav-vis { display:flex;background:var(--bg,#f2f2ef);border-radius:8px;padding:3px;gap:2px; }
.ag-nav-vis button { background:none;border:none;padding:6px 14px;border-radius:6px;font-family:inherit;font-size:13px;font-weight:500;color:var(--text-muted);cursor:pointer;transition:all .2s; }
.ag-nav-vis button.ativo { background:#fff;color:var(--petrol-900);box-shadow:0 1px 4px rgba(0,0,0,.08); }
body.dark-mode .ag-nav-vis button.ativo { background:var(--bg-card);color:var(--text); }

.ag-nav-mes { display:flex;align-items:center;gap:10px; }
.ag-nav-mes-titulo { font-family:'Playfair Display',serif;font-size:20px;color:var(--petrol-900);min-width:180px; }
body.dark-mode .ag-nav-mes-titulo { color:var(--text); }
.ag-btn-mes { width:30px;height:30px;background:#fff;border:1px solid var(--border);border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:13px;transition:all .2s; }
.ag-btn-mes:hover { border-color:var(--rose);color:var(--rose); }
.ag-btn-hoje { background:none;border:1px solid var(--border);border-radius:8px;padding:5px 12px;font-size:12px;font-weight:500;cursor:pointer;color:var(--text-muted);transition:all .2s; }
.ag-btn-hoje:hover { border-color:var(--rose);color:var(--rose); }

.ag-filtro-resp { font-size:13px; }
.ag-filtro-resp select { padding:5px 8px;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:13px; }

/* ── FILTROS TIPO ── */
.ag-filtros { display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px; }
.ag-chip { display:flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:12px;font-weight:500;cursor:pointer;border:1.5px solid var(--border);background:#fff;color:var(--text-muted);transition:all .2s; }
.ag-chip.ativo { color:#fff;border-color:transparent; }
.ag-chip[data-tipo="audiencia"].ativo{background:var(--audiencia)}.ag-chip[data-tipo="reuniao_cliente"].ativo{background:var(--reuniao)}.ag-chip[data-tipo="prazo"].ativo{background:var(--prazo)}.ag-chip[data-tipo="onboarding"].ativo{background:var(--onboarding)}.ag-chip[data-tipo="reuniao_interna"].ativo{background:var(--interna)}.ag-chip[data-tipo="mediacao_cejusc"].ativo{background:var(--mediacao)}.ag-chip[data-tipo="ligacao"].ativo{background:var(--ligacao)}
.ag-chip-dot { width:8px;height:8px;border-radius:50%; }

/* ── CALENDÁRIO MENSAL ── */
.ag-cal-header { display:grid;grid-template-columns:repeat(7,1fr);gap:1px;margin-bottom:1px; }
.ag-cal-hd { text-align:center;font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-muted);padding:8px 0; }
.ag-cal-grid { display:grid;grid-template-columns:repeat(7,1fr);gap:1px;background:var(--border);border-radius:12px;overflow:hidden; }
.ag-cal-dia { background:#fff;min-height:100px;padding:6px;cursor:pointer;transition:background .15s;position:relative; }
body.dark-mode .ag-cal-dia { background:var(--bg-card); }
.ag-cal-dia:hover { background:#FAFAF8; }
body.dark-mode .ag-cal-dia:hover { background:var(--bg-secondary); }
.ag-cal-dia.outro-mes { opacity:.4; }
.ag-cal-dia-num { font-size:13px;font-weight:600;color:var(--petrol-900);margin-bottom:3px;width:26px;height:26px;display:flex;align-items:center;justify-content:center; }
body.dark-mode .ag-cal-dia-num { color:var(--text); }
.ag-cal-dia.hoje .ag-cal-dia-num { background:var(--rose);color:#fff;border-radius:50%; }
.ag-cal-ev { font-size:10px;font-weight:500;padding:2px 5px;border-radius:4px;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#fff;cursor:pointer; }
.ag-cal-mais { font-size:10px;color:var(--text-muted);padding:1px 5px;cursor:pointer; }
.ag-cal-mais:hover { color:var(--rose); }

/* ── SEMANAL ── */
.ag-sem { background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden; }
body.dark-mode .ag-sem { background:var(--bg-card);border-color:var(--border); }
.ag-sem-header { display:grid;grid-template-columns:54px repeat(7,1fr);border-bottom:1px solid var(--border); }
.ag-sem-hh { padding:10px; }
.ag-sem-hd { padding:10px;text-align:center;border-left:1px solid var(--border); }
.ag-sem-hd .dn { font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted); }
.ag-sem-hd .dnum { font-size:20px;font-weight:600;color:var(--petrol-900);margin-top:2px; }
body.dark-mode .ag-sem-hd .dnum { color:var(--text); }
.ag-sem-hd.hoje .dnum { background:var(--rose);color:#fff;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:2px auto 0; }
.ag-sem-body { display:grid;grid-template-columns:54px repeat(7,1fr);max-height:480px;overflow-y:auto; }
.ag-sem-hr { padding:0 8px;font-size:11px;color:var(--text-muted);text-align:right;height:56px;display:flex;align-items:flex-start;padding-top:4px; }
.ag-sem-cel { border-left:1px solid var(--border);border-top:1px solid var(--border);height:56px;position:relative; }
.ag-sem-ev { position:absolute;left:2px;right:2px;border-radius:5px;padding:3px 5px;font-size:10px;font-weight:500;color:#fff;overflow:hidden;cursor:pointer;z-index:1; }

/* ── LISTA DO DIA ── */
.ag-lista-titulo { font-family:'Playfair Display',serif;font-size:24px;color:var(--petrol-900);margin-bottom:20px; }
body.dark-mode .ag-lista-titulo { color:var(--text); }
.ag-lista-titulo span { color:var(--rose); }
.ag-lista-vazio { background:#fff;border:1px solid var(--border);border-radius:12px;padding:40px;text-align:center;color:var(--text-muted);font-size:14px; }
body.dark-mode .ag-lista-vazio { background:var(--bg-card); }
.ag-lista-item { display:flex;gap:16px;margin-bottom:10px;align-items:flex-start; }
.ag-lista-hora { font-size:13px;font-weight:600;color:var(--text-muted);min-width:50px;padding-top:16px;text-align:right; }
.ag-lista-linha { display:flex;flex-direction:column;align-items:center; }
.ag-lista-dot { width:12px;height:12px;border-radius:50%;margin-top:19px;flex-shrink:0; }
.ag-lista-fio { width:2px;flex:1;background:var(--border);min-height:30px; }
.ag-lista-card { flex:1;background:#fff;border:1px solid var(--border);border-radius:12px;padding:14px 18px;cursor:pointer;transition:all .2s;border-left:4px solid var(--border); }
body.dark-mode .ag-lista-card { background:var(--bg-card);border-color:var(--border); }
.ag-lista-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.06);transform:translateY(-1px); }
.ag-lc-topo { display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:6px;gap:8px; }
.ag-lc-titulo { font-size:14px;font-weight:600;color:var(--petrol-900); }
body.dark-mode .ag-lc-titulo { color:var(--text); }
.ag-lc-badge { font-size:10px;font-weight:600;padding:3px 10px;border-radius:20px;color:#fff;white-space:nowrap; }
.ag-lc-info { display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:var(--text-muted); }
.ag-lc-acoes { display:flex;gap:6px;margin-top:10px;padding-top:10px;border-top:1px solid var(--border);flex-wrap:wrap; }
.ag-btn-acao { flex:1;min-width:100px;background:none;border:1px solid var(--border);border-radius:8px;padding:6px 10px;font-size:11px;font-weight:500;cursor:pointer;transition:all .2s;color:var(--text-muted);display:flex;align-items:center;justify-content:center;gap:4px; }
.ag-btn-acao:hover { border-color:var(--rose);color:var(--rose); }
.ag-btn-acao.meet { background:var(--petrol-900);color:#fff;border-color:var(--petrol-900); }
.ag-btn-acao.verde { background:#2D7A4F;color:#fff;border-color:#2D7A4F; }

/* ── MODAL ── */
.ag-overlay { display:none;position:fixed;inset:0;background:rgba(5,34,40,.5);z-index:200;align-items:center;justify-content:center;padding:20px; }
.ag-overlay.aberto { display:flex; }
.ag-modal { background:#fff;border-radius:16px;width:100%;max-width:580px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(5,34,40,.2); }
body.dark-mode .ag-modal { background:var(--bg-card); }
.ag-modal-hdr { background:var(--petrol-900);padding:20px 24px;border-radius:16px 16px 0 0;display:flex;align-items:center;justify-content:space-between; }
.ag-modal-hdr h3 { font-family:'Playfair Display',serif;font-size:18px;color:#fff;margin:0; }
.ag-modal-fechar { background:none;border:none;color:rgba(255,255,255,.6);font-size:18px;cursor:pointer;width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:8px;transition:all .2s; }
.ag-modal-fechar:hover { background:rgba(255,255,255,.1);color:#fff; }
.ag-modal-body { padding:24px; }
.ag-fg { margin-bottom:16px; }
.ag-fl { display:block;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-muted);margin-bottom:6px; }
.ag-fi { width:100%;border:1.5px solid var(--border);border-radius:8px;padding:9px 12px;font-family:inherit;font-size:14px;color:var(--text);transition:border .2s;background:#fff; }
body.dark-mode .ag-fi { background:var(--bg-secondary);color:var(--text);border-color:var(--border); }
.ag-fi:focus { outline:none;border-color:var(--rose); }
.ag-fr { display:grid;grid-template-columns:1fr 1fr;gap:14px; }
.ag-tipo-grid { display:grid;grid-template-columns:repeat(4,1fr);gap:6px; }
.ag-tipo-btn { background:none;border:1.5px solid var(--border);border-radius:8px;padding:8px 4px;text-align:center;cursor:pointer;transition:all .2s;font-size:11px;font-weight:500;color:var(--text-muted); }
.ag-tipo-btn .te { font-size:16px;display:block;margin-bottom:2px; }
.ag-tipo-btn:hover { border-color:var(--rose); }
.ag-tipo-btn.sel { color:#fff;border-color:transparent; }
.ag-tipo-btn.sel[data-t="audiencia"]{background:var(--audiencia)}.ag-tipo-btn.sel[data-t="reuniao_cliente"]{background:var(--reuniao)}.ag-tipo-btn.sel[data-t="prazo"]{background:var(--prazo)}.ag-tipo-btn.sel[data-t="onboarding"]{background:var(--onboarding)}.ag-tipo-btn.sel[data-t="reuniao_interna"]{background:var(--interna)}.ag-tipo-btn.sel[data-t="mediacao_cejusc"]{background:var(--mediacao)}.ag-tipo-btn.sel[data-t="ligacao"]{background:var(--ligacao)}
.ag-meet-box { background:var(--cobre-suave);border:1px solid rgba(184,115,51,.2);border-radius:10px;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:6px; }
body.dark-mode .ag-meet-box { background:rgba(184,115,51,.15); }
.ag-meet-box p { font-size:12px;color:#5a3a00;margin:0; }
body.dark-mode .ag-meet-box p { color:var(--rose); }
.ag-btn-meet { background:var(--petrol-900);color:#fff;border:none;padding:7px 14px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;display:flex;align-items:center;gap:5px; }
.ag-msg-prev { background:var(--bg,#f2f2ef);border-radius:10px;padding:12px 14px;font-size:12px;color:#555;line-height:1.5;border-left:3px solid var(--rose);margin-top:6px; }
.ag-modal-footer { padding:16px 24px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px; }
.ag-btn-cancelar { background:none;border:1.5px solid var(--border);border-radius:8px;padding:9px 18px;font-size:13px;font-weight:500;cursor:pointer;color:var(--text-muted);transition:all .2s; }
.ag-btn-cancelar:hover { border-color:var(--text-muted);color:var(--text); }
.ag-btn-salvar { background:var(--petrol-900);color:#fff;border:none;border-radius:8px;padding:9px 22px;font-size:13px;font-weight:600;cursor:pointer;transition:opacity .2s; }
.ag-btn-salvar:hover { opacity:.9; }

/* autocomplete */
.ag-ac-wrap { position:relative; }
.ag-ac-list { display:none;position:absolute;top:100%;left:0;right:0;z-index:10;background:#fff;border:1.5px solid var(--border);border-radius:0 0 8px 8px;max-height:180px;overflow-y:auto;box-shadow:var(--shadow-md); }
body.dark-mode .ag-ac-list { background:var(--bg-card); }
.ag-ac-item { padding:7px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--border); }
.ag-ac-item:hover { background:rgba(215,171,144,.12); }

@media (max-width:768px) {
  .ag-tipo-grid { grid-template-columns:repeat(4,1fr); }
  .ag-fr { grid-template-columns:1fr; }
  .ag-topo { flex-direction:column;align-items:flex-start; }
}
</style>

<?php
$voltarCaso = (int)($_GET['voltar_caso'] ?? $_GET['case_id'] ?? 0);
$preClientId = (int)($_GET['client_id'] ?? 0);
$preCaseId = (int)($_GET['case_id'] ?? 0);
$preNovo = isset($_GET['novo']);

// Pré-carregar dados do cliente/caso para pré-preencher modal
$preClientName = '';
$preCaseTitle = '';
if ($preClientId) {
    $stmt = $pdo->prepare("SELECT name FROM clients WHERE id = ?");
    $stmt->execute(array($preClientId));
    $row = $stmt->fetch();
    if ($row) $preClientName = $row['name'];
}
if ($preCaseId) {
    $stmt = $pdo->prepare("SELECT title FROM cases WHERE id = ?");
    $stmt->execute(array($preCaseId));
    $row = $stmt->fetch();
    if ($row) $preCaseTitle = $row['title'];
}

if ($voltarCaso > 0): ?>
<div style="display:flex;gap:.5rem;margin-bottom:.75rem;">
    <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $voltarCaso) ?>" class="btn btn-outline btn-sm">← Analisar processo</a>
    <a href="<?= module_url('prazos') ?>?case_id=<?= $voltarCaso ?>" class="btn btn-outline btn-sm">⏰ Prazos</a>
</div>
<?php endif; ?>

<div class="ag">
    <!-- TOPO -->
    <div class="ag-topo">
        <div class="ag-topo-esq">
            <div class="ag-nav-vis">
                <button class="ativo" onclick="mudarVis('mensal',this)">Mês</button>
                <button onclick="mudarVis('semanal',this)">Semana</button>
                <button onclick="mudarVis('lista',this)">Hoje</button>
            </div>
            <div class="ag-nav-mes">
                <button class="ag-btn-mes" onclick="navMes(-1)">&#8592;</button>
                <button class="ag-btn-mes" onclick="navMes(1)">&#8594;</button>
                <div class="ag-nav-mes-titulo" id="agTituloMes"></div>
                <button class="ag-btn-hoje" onclick="irHoje()">Hoje</button>
            </div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;">
            <div class="ag-filtro-resp">
                <select id="agFiltroResp" onchange="recarregarEventos()" class="ag-fi" style="width:auto;padding:5px 10px;font-size:12px;">
                    <option value="">Todos</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (has_min_role('gestao')): ?>
            <a href="<?= module_url('agenda', 'importar.php') ?>" class="btn btn-outline btn-sm" style="font-size:13px;">Importar CSV</a>
            <?php endif; ?>
            <a href="https://www.tjrj.jus.br/web/guest/balcao-virtual" target="_blank" class="btn btn-outline btn-sm" style="font-size:13px;border-color:#052228;color:#052228;">Balcao Virtual</a>
            <button class="btn btn-primary btn-sm" onclick="abrirModal()" style="font-size:13px;">+ Novo compromisso</button>
        </div>
    </div>

    <!-- FILTROS TIPO -->
    <div class="ag-filtros">
        <?php foreach ($tiposMapa as $k => $t): ?>
        <div class="ag-chip ativo" data-tipo="<?= $k ?>" onclick="toggleFiltro(this)">
            <div class="ag-chip-dot" style="background:<?= $t['cor'] ?>"></div> <?= $t['label'] ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- VISÃO MENSAL -->
    <div id="vis-mensal">
        <div class="ag-cal-header">
            <div class="ag-cal-hd">Dom</div><div class="ag-cal-hd">Seg</div><div class="ag-cal-hd">Ter</div>
            <div class="ag-cal-hd">Qua</div><div class="ag-cal-hd">Qui</div><div class="ag-cal-hd">Sex</div><div class="ag-cal-hd">Sáb</div>
        </div>
        <div class="ag-cal-grid" id="agCalGrid"></div>
    </div>

    <!-- VISÃO SEMANAL -->
    <div id="vis-semanal" style="display:none;">
        <div class="ag-sem">
            <div class="ag-sem-header" id="agSemHeader"></div>
            <div class="ag-sem-body" id="agSemBody"></div>
        </div>
    </div>

    <!-- LISTA DO DIA -->
    <div id="vis-lista" style="display:none;">
        <div class="ag-lista-titulo" id="agListaTitulo"></div>
        <div id="agListaConteudo"></div>
    </div>
</div>

<!-- MODAL -->
<div class="ag-overlay" id="agOverlay">
<div class="ag-modal">
    <div class="ag-modal-hdr">
        <h3 id="agModalTitulo">Novo compromisso</h3>
        <button class="ag-modal-fechar" onclick="fecharModal()">&#10005;</button>
    </div>
    <div class="ag-modal-body">
        <input type="hidden" id="agEvId" value="0">

        <div class="ag-fg">
            <label class="ag-fl">Tipo de compromisso</label>
            <div class="ag-tipo-grid">
                <?php
                $emojis = array('audiencia'=>"\u{2696}\u{FE0F}",'reuniao_cliente'=>"\u{1F464}",'prazo'=>"\u{23F0}",'onboarding'=>"\u{1F3AF}",'reuniao_interna'=>"\u{1F465}",'mediacao_cejusc'=>"\u{1F91D}",'ligacao'=>"\u{1F4DE}");
                $labels = array('audiencia'=>'Audiência','reuniao_cliente'=>'Reunião cliente','prazo'=>'Prazo','onboarding'=>'Onboarding','reuniao_interna'=>'R. interna','mediacao_cejusc'=>'Mediação','ligacao'=>'Ligação');
                foreach ($labels as $k => $lb): ?>
                <button type="button" class="ag-tipo-btn" data-t="<?= $k ?>" onclick="selTipo('<?= $k ?>',this)">
                    <span class="te"><?= $emojis[$k] ?></span><?= $lb ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="ag-fg">
            <label class="ag-fl">Título</label>
            <input type="text" class="ag-fi" id="agTitulo" placeholder="Ex: Audiência — Wendel Magno x Alimentos">
        </div>

        <div class="ag-fr">
            <div class="ag-fg">
                <label class="ag-fl">Data/hora início</label>
                <input type="datetime-local" class="ag-fi" id="agDtInicio">
            </div>
            <div class="ag-fg">
                <label class="ag-fl">Data/hora término</label>
                <input type="datetime-local" class="ag-fi" id="agDtFim">
            </div>
        </div>

        <div class="ag-fg">
            <label class="ag-fl">Modalidade</label>
            <select class="ag-fi" id="agModalidade" onchange="toggleMeet()">
                <option value="presencial">Presencial</option>
                <option value="online">Online (Google Meet)</option>
                <option value="nao_aplicavel">Não se aplica</option>
            </select>
        </div>

        <div class="ag-fg" id="agMeetBox" style="display:none;">
            <div class="ag-meet-box">
                <div style="flex:1;">
                    <p>Link Google Meet</p>
                    <input type="text" class="ag-fi" id="agMeetLink" placeholder="Gerado automaticamente ou cole aqui" style="margin-top:5px;font-size:12px;">
                </div>
                <button type="button" class="ag-btn-meet" id="btnGerarMeet" onclick="gerarMeet()">Gerar Meet</button>
            </div>
        </div>

        <div class="ag-fg">
            <label class="ag-fl">Local / Endereço</label>
            <input type="text" class="ag-fi" id="agLocal" placeholder="Ex: 1ª Vara de Família de Barra Mansa">
        </div>

        <div class="ag-fr">
            <div class="ag-fg ag-ac-wrap">
                <label class="ag-fl">Cliente vinculado</label>
                <input type="text" class="ag-fi" id="agClienteBusca" placeholder="Buscar cliente..." autocomplete="off">
                <input type="hidden" id="agClienteId">
                <div class="ag-ac-list" id="agClienteList"></div>
            </div>
            <div class="ag-fg ag-ac-wrap">
                <label class="ag-fl">Processo vinculado</label>
                <input type="text" class="ag-fi" id="agCasoBusca" placeholder="Buscar processo..." autocomplete="off">
                <input type="hidden" id="agCasoId">
                <div class="ag-ac-list" id="agCasoList"></div>
            </div>
        </div>

        <div class="ag-fg">
            <label class="ag-fl">Responsável</label>
            <select class="ag-fi" id="agResponsavel">
                <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= (int)$u['id'] === current_user_id() ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="ag-fg">
            <label class="ag-fl">Mensagem para o cliente (WhatsApp)</label>
            <textarea class="ag-fi" id="agMsgCliente" rows="3" placeholder="Variáveis: [nome], [data], [hora], [link_meet]"></textarea>
            <div class="ag-msg-prev" id="agMsgPreview" style="display:none;"></div>
        </div>

        <div class="ag-fg">
            <label class="ag-fl">Observações internas</label>
            <textarea class="ag-fi" id="agDescricao" rows="2" placeholder="Notas internas..."></textarea>
        </div>
    </div>
    <div class="ag-modal-footer" style="display:flex;justify-content:space-between;">
        <button id="agBtnExcluir" class="ag-btn-cancelar" style="color:#dc2626;border-color:#dc2626;display:none;" onclick="excluirEventoModal()">Excluir</button>
        <div style="display:flex;gap:8px;margin-left:auto;">
            <button class="ag-btn-cancelar" onclick="fecharModal()">Cancelar</button>
            <button class="ag-btn-salvar" onclick="salvarEvento()">Salvar compromisso</button>
        </div>
    </div>
</div>
</div>

<script>
var API = '<?= module_url("agenda", "api.php") ?>';
var CSRF = '<?= generate_csrf_token() ?>';
var eventos = [];
var mesAtual, anoAtual, visAtual = 'mensal', diaLista;
var hoje = new Date();
var meses = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
var diasSem = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];

var CORES = {audiencia:'#052228',reuniao_cliente:'#B87333',prazo:'#CC0000',onboarding:'#2D7A4F',reuniao_interna:'#1a3a7a',mediacao_cejusc:'#6B4C9A',ligacao:'#888880'};
var LABELS = {audiencia:'Audiência',reuniao_cliente:'Reunião cliente',prazo:'Prazo',onboarding:'Onboarding',reuniao_interna:'R. interna',mediacao_cejusc:'Mediação',ligacao:'Ligação'};
var EMOJIS = {audiencia:"\u2696\uFE0F",reuniao_cliente:"\u{1F464}",prazo:"\u23F0",onboarding:"\u{1F3AF}",reuniao_interna:"\u{1F465}",mediacao_cejusc:"\u{1F91D}",ligacao:"\u{1F4DE}"};

// ── INIT ────────────────────────────────────────────────────
mesAtual = hoje.getMonth();
anoAtual = hoje.getFullYear();
diaLista = hoje;
recarregarEventos();

// Auto-abrir modal se veio com ?novo=1
<?php if ($preNovo):
    $preTipo = isset($_GET['tipo']) ? $_GET['tipo'] : '';
    $preModalidade = isset($_GET['modalidade']) ? $_GET['modalidade'] : '';
?>
setTimeout(function() {
    abrirModal();
    <?php if ($preClientId): ?>
    document.getElementById('agClienteBusca').value = <?= json_encode($preClientName) ?>;
    document.getElementById('agClienteId').value = '<?= $preClientId ?>';
    <?php endif; ?>
    <?php if ($preCaseId): ?>
    document.getElementById('agCasoBusca').value = <?= json_encode($preCaseTitle) ?>;
    document.getElementById('agCasoId').value = '<?= $preCaseId ?>';
    <?php endif; ?>
    <?php if ($preTipo): ?>
    var preBtn = document.querySelector('.ag-tipo-btn[data-t="<?= e($preTipo) ?>"]');
    if (preBtn) selTipo('<?= e($preTipo) ?>', preBtn);
    <?php endif; ?>
    <?php if ($preModalidade): ?>
    document.getElementById('agModalidade').value = '<?= e($preModalidade) ?>';
    toggleMeet();
    <?php endif; ?>
    atualizarPreview();
}, 300);
<?php endif; ?>

// ── FETCH EVENTOS ───────────────────────────────────────────
function recarregarEventos() {
    var inicio, fim;
    if (visAtual === 'mensal') {
        inicio = anoAtual + '-' + pad(mesAtual+1) + '-01';
        var lastDay = new Date(anoAtual, mesAtual+1, 0).getDate();
        fim = anoAtual + '-' + pad(mesAtual+1) + '-' + pad(lastDay);
        // Include prev/next month overflow
        var d = new Date(anoAtual, mesAtual, 1);
        d.setDate(d.getDate() - d.getDay());
        inicio = d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate());
        d = new Date(anoAtual, mesAtual+1, 0);
        d.setDate(d.getDate() + (6 - d.getDay()));
        fim = d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate());
    } else if (visAtual === 'semanal') {
        var s = new Date(hoje); s.setDate(hoje.getDate() - hoje.getDay());
        var e2 = new Date(s); e2.setDate(s.getDate() + 6);
        inicio = fmtDate(s); fim = fmtDate(e2);
    } else {
        inicio = fmtDate(diaLista); fim = fmtDate(diaLista);
    }

    var resp = document.getElementById('agFiltroResp').value;
    var url = API + '?action=listar&inicio=' + inicio + '&fim=' + fim;
    if (resp) url += '&responsavel=' + resp;

    var xhr = new XMLHttpRequest();
    xhr.open('GET', url);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onload = function() {
        try { eventos = JSON.parse(xhr.responseText); } catch(ex) { eventos = []; }
        renderVis();
    };
    xhr.send();
}

function renderVis() {
    if (visAtual === 'mensal') renderCalendario();
    else if (visAtual === 'semanal') renderSemanal();
    else renderLista();
}

// ── FILTROS ─────────────────────────────────────────────────
function filtrosAtivos() {
    var ativos = [];
    document.querySelectorAll('.ag-chip.ativo').forEach(function(c) { ativos.push(c.getAttribute('data-tipo')); });
    return ativos;
}
function toggleFiltro(el) { el.classList.toggle('ativo'); renderVis(); }
function eventosFiltrados() {
    var f = filtrosAtivos();
    return eventos.filter(function(ev) { return f.indexOf(ev.tipo) !== -1; });
}

// ── CALENDÁRIO MENSAL ───────────────────────────────────────
function renderCalendario() {
    document.getElementById('agTituloMes').textContent = meses[mesAtual] + ' ' + anoAtual;
    var grid = document.getElementById('agCalGrid');
    grid.innerHTML = '';
    var primDia = new Date(anoAtual, mesAtual, 1).getDay();
    var diasMes = new Date(anoAtual, mesAtual+1, 0).getDate();
    var diasMesAnt = new Date(anoAtual, mesAtual, 0).getDate();
    var cells = [];
    for (var i = primDia-1; i >= 0; i--) cells.push({d: diasMesAnt-i, m: mesAtual-1, outro:true});
    for (var i = 1; i <= diasMes; i++) cells.push({d: i, m: mesAtual, outro:false});
    while (cells.length % 7 !== 0) cells.push({d: cells.length-diasMes-primDia+1, m: mesAtual+1, outro:true});

    var evsFilt = eventosFiltrados();

    cells.forEach(function(c) {
        var div = document.createElement('div');
        var isHoje = !c.outro && c.d === hoje.getDate() && mesAtual === hoje.getMonth() && anoAtual === hoje.getFullYear();
        div.className = 'ag-cal-dia' + (c.outro ? ' outro-mes' : '') + (isHoje ? ' hoje' : '');

        var numDiv = document.createElement('div');
        numDiv.className = 'ag-cal-dia-num';
        numDiv.textContent = c.d;
        div.appendChild(numDiv);

        if (!c.outro) {
            var evsDia = evsFilt.filter(function(ev) {
                var dt = new Date(ev.data_inicio.replace(' ', 'T'));
                return dt.getDate() === c.d && dt.getMonth() === mesAtual && dt.getFullYear() === anoAtual;
            });
            var max = 2;
            evsDia.slice(0, max).forEach(function(ev) {
                var evDiv = document.createElement('div');
                evDiv.className = 'ag-cal-ev';
                evDiv.style.background = CORES[ev.tipo] || '#888';
                var hr = ev.dia_todo == 1 ? '' : ev.data_inicio.substring(11,16) + ' ';
                evDiv.textContent = hr + ev.titulo;
                evDiv.title = ev.titulo;
                evDiv.onclick = function(e) { e.stopPropagation(); abrirModalEditar(ev.id); };
                div.appendChild(evDiv);
            });
            if (evsDia.length > max) {
                var mais = document.createElement('div');
                mais.className = 'ag-cal-mais';
                mais.textContent = '+ ' + (evsDia.length - max) + ' mais';
                div.appendChild(mais);
            }
        }

        div.onclick = function() {
            if (!c.outro) {
                diaLista = new Date(anoAtual, mesAtual, c.d);
                mudarVis('lista');
            }
        };
        grid.appendChild(div);
    });
}

// ── SEMANAL ─────────────────────────────────────────────────
function renderSemanal() {
    document.getElementById('agTituloMes').textContent = meses[mesAtual] + ' ' + anoAtual;
    var header = document.getElementById('agSemHeader');
    var body = document.getElementById('agSemBody');
    header.innerHTML = '<div class="ag-sem-hh"></div>';
    body.innerHTML = '';

    var inicioSem = new Date(hoje); inicioSem.setDate(hoje.getDate() - hoje.getDay());
    var evsFilt = eventosFiltrados();

    for (var d = 0; d < 7; d++) {
        var dt = new Date(inicioSem); dt.setDate(inicioSem.getDate() + d);
        var isH = dt.toDateString() === hoje.toDateString();
        var h = document.createElement('div');
        h.className = 'ag-sem-hd' + (isH ? ' hoje' : '');
        h.innerHTML = '<div class="dn">' + diasSem[d] + '</div><div class="dnum">' + dt.getDate() + '</div>';
        header.appendChild(h);
    }

    for (var hora = 8; hora <= 19; hora++) {
        var hrDiv = document.createElement('div');
        hrDiv.className = 'ag-sem-hr';
        hrDiv.textContent = hora + 'h';
        body.appendChild(hrDiv);

        for (var d = 0; d < 7; d++) {
            var dt = new Date(inicioSem); dt.setDate(inicioSem.getDate() + d);
            var cel = document.createElement('div');
            cel.className = 'ag-sem-cel';

            var evs = evsFilt.filter(function(ev) {
                if (ev.dia_todo == 1) return false;
                var evDt = new Date(ev.data_inicio.replace(' ', 'T'));
                return evDt.getDate() === dt.getDate() && evDt.getMonth() === dt.getMonth() && evDt.getHours() === hora;
            });

            evs.forEach(function(ev) {
                var evDt = new Date(ev.data_inicio.replace(' ', 'T'));
                var evFim = ev.data_fim ? new Date(ev.data_fim.replace(' ', 'T')) : new Date(evDt.getTime() + 3600000);
                var durMin = (evFim - evDt) / 60000;
                var evDiv = document.createElement('div');
                evDiv.className = 'ag-sem-ev';
                evDiv.style.background = CORES[ev.tipo] || '#888';
                evDiv.style.top = '2px';
                evDiv.style.height = Math.min(Math.max(durMin / 60 * 56 - 4, 16), 112) + 'px';
                evDiv.textContent = ev.titulo;
                evDiv.title = ev.titulo + ' (' + ev.data_inicio.substring(11,16) + ')';
                evDiv.onclick = function() { abrirModalEditar(ev.id); };
                cel.appendChild(evDiv);
            });

            body.appendChild(cel);
        }
    }
}

// ── LISTA DO DIA ────────────────────────────────────────────
function renderLista() {
    var tEl = document.getElementById('agListaTitulo');
    var cEl = document.getElementById('agListaConteudo');

    var opts = { weekday:'long', day:'numeric', month:'long', year:'numeric' };
    var str = diaLista.toLocaleDateString('pt-BR', opts);
    var partes = str.split(', ');
    if (partes.length >= 2) {
        tEl.innerHTML = partes[0].charAt(0).toUpperCase() + partes[0].slice(1) + ', <span>' + partes[1] + '</span>';
    } else {
        tEl.innerHTML = '<span>' + str + '</span>';
    }

    // Update nav title
    document.getElementById('agTituloMes').textContent = meses[diaLista.getMonth()] + ' ' + diaLista.getFullYear();

    var evsFilt = eventosFiltrados().filter(function(ev) {
        var dt = new Date(ev.data_inicio.replace(' ', 'T'));
        return dt.getDate() === diaLista.getDate() && dt.getMonth() === diaLista.getMonth() && dt.getFullYear() === diaLista.getFullYear();
    }).sort(function(a, b) { return a.data_inicio.localeCompare(b.data_inicio); });

    if (!evsFilt.length) {
        cEl.innerHTML = '<div class="ag-lista-vazio">Nenhum compromisso para este dia.<br><small>Clique em "Novo compromisso" para adicionar.</small></div>';
        return;
    }

    cEl.innerHTML = evsFilt.map(function(ev, i) {
        var hr = ev.dia_todo == 1 ? 'Dia todo' : ev.data_inicio.substring(11,16);
        var cor = CORES[ev.tipo] || '#888';
        var label = LABELS[ev.tipo] || ev.tipo;
        var dtObj = new Date(ev.data_inicio.replace(' ', 'T'));
        var fimObj = ev.data_fim ? new Date(ev.data_fim.replace(' ', 'T')) : null;
        var durMin = fimObj ? Math.round((fimObj - dtObj) / 60000) : '';
        var durStr = durMin ? durMin + 'min' : '';
        var meetHtml = ev.meet_link ? '<button class="ag-btn-acao meet" onclick="window.open(\'' + ev.meet_link + '\',\'_blank\')">Entrar no Meet</button>' : '';
        var msgHtml = ev.client_id ? '<button class="ag-btn-acao" onclick="enviarMsgCliente(' + ev.id + ')">Mensagem cliente</button>' : '';

        return '<div class="ag-lista-item">' +
            '<div class="ag-lista-hora">' + hr + '</div>' +
            '<div class="ag-lista-linha"><div class="ag-lista-dot" style="background:' + cor + '"></div>' +
            (i < evsFilt.length-1 ? '<div class="ag-lista-fio"></div>' : '') + '</div>' +
            '<div class="ag-lista-card" style="border-left-color:' + cor + '">' +
            '<div class="ag-lc-topo"><div class="ag-lc-titulo">' + esc(ev.titulo) + '</div>' +
            '<div class="ag-lc-badge" style="background:' + cor + '">' + label + '</div></div>' +
            '<div class="ag-lc-info">' +
            (ev.dia_todo != 1 ? '<span>🕐 ' + hr + (durStr ? ' · ' + durStr : '') + '</span>' : '') +
            (ev.meet_link ? '<span>🎥 Google Meet</span>' : '') +
            (ev.responsavel_name ? '<span>👤 ' + esc(ev.responsavel_name) + '</span>' : '') +
            (ev.client_name ? '<span>📋 ' + esc(ev.client_name) + '</span>' : '') +
            '</div>' +
            '<div class="ag-lc-acoes">' + meetHtml + msgHtml +
            (ev.google_event_id ? '<button class="ag-btn-acao" style="color:#052228;border-color:#052228;" onclick="enviarConvite(' + ev.id + ')">Enviar Convite</button>' : '') +
            '<button class="ag-btn-acao verde" onclick="marcarRealizado(' + ev.id + ',this)">Realizado</button>' +
            '<button class="ag-btn-acao" onclick="abrirModalEditar(' + ev.id + ')">Editar</button>' +
            '<button class="ag-btn-acao" style="color:#dc2626;border-color:#dc2626;" onclick="excluirEvento(' + ev.id + ')">Excluir</button>' +
            '</div></div></div>';
    }).join('');
}

// ── NAVEGAÇÃO ───────────────────────────────────────────────
function mudarVis(vis, btn) {
    visAtual = vis;
    document.getElementById('vis-mensal').style.display = vis==='mensal' ? 'block' : 'none';
    document.getElementById('vis-semanal').style.display = vis==='semanal' ? 'block' : 'none';
    document.getElementById('vis-lista').style.display = vis==='lista' ? 'block' : 'none';
    document.querySelectorAll('.ag-nav-vis button').forEach(function(b) { b.classList.remove('ativo'); });
    if (btn) { btn.classList.add('ativo'); }
    else {
        var btns = document.querySelectorAll('.ag-nav-vis button');
        var idx = vis==='mensal'?0:vis==='semanal'?1:2;
        btns[idx].classList.add('ativo');
    }
    recarregarEventos();
}

function navMes(d) {
    if (visAtual === 'lista') {
        diaLista = new Date(diaLista); diaLista.setDate(diaLista.getDate() + d);
        recarregarEventos();
        return;
    }
    mesAtual += d;
    if (mesAtual > 11) { mesAtual=0; anoAtual++; }
    if (mesAtual < 0) { mesAtual=11; anoAtual--; }
    recarregarEventos();
}

function irHoje() {
    mesAtual = hoje.getMonth(); anoAtual = hoje.getFullYear();
    diaLista = new Date(hoje);
    recarregarEventos();
}

// ── MODAL ───────────────────────────────────────────────────
var tipoSelecionado = 'audiencia';
var msgsPadrao = {
    audiencia: 'Olá, [nome]! Sua audiência está agendada para [data] às [hora]. Qualquer dúvida, estamos à disposição!',
    reuniao_cliente: 'Olá, [nome]! Sua reunião com nossa equipe está confirmada para [data] às [hora]. Te esperamos!',
    onboarding: 'Olá, [nome]! Seu onboarding está agendado para [data] às [hora]. Prepare os documentos solicitados!',
    prazo: '', reuniao_interna: '', mediacao_cejusc: 'Olá, [nome]! A mediação está agendada para [data] às [hora]. Contamos com sua presença!', ligacao: ''
};

function abrirModal(dataStr) {
    document.getElementById('agModalTitulo').textContent = 'Novo compromisso';
    document.getElementById('agEvId').value = '0';
    document.getElementById('agTitulo').value = '';
    document.getElementById('agLocal').value = '';
    document.getElementById('agDescricao').value = '';
    document.getElementById('agClienteBusca').value = '';
    document.getElementById('agClienteId').value = '';
    document.getElementById('agCasoBusca').value = '';
    document.getElementById('agCasoId').value = '';
    document.getElementById('agModalidade').value = 'presencial';
    document.getElementById('agMeetLink').value = '';
    document.getElementById('btnGerarMeet').textContent = 'Gerar Meet';
    document.getElementById('btnGerarMeet').disabled = false;
    document.getElementById('agBtnExcluir').style.display = 'none';
    toggleMeet();

    var agora = new Date();
    agora.setMinutes(0); agora.setSeconds(0);
    if (dataStr) { agora = new Date(dataStr + 'T09:00'); }
    document.getElementById('agDtInicio').value = fmtDatetime(agora);
    var fim = new Date(agora); fim.setHours(fim.getHours()+1);
    document.getElementById('agDtFim').value = fmtDatetime(fim);

    selTipo('audiencia', document.querySelector('.ag-tipo-btn[data-t="audiencia"]'));
    document.getElementById('agMsgCliente').value = msgsPadrao.audiencia;
    atualizarPreview();

    document.getElementById('agOverlay').classList.add('aberto');
}

function abrirModalEditar(id) {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', API + '?action=get&id=' + id);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onload = function() {
        try {
            var ev = JSON.parse(xhr.responseText);
            if (ev.error) { alert(ev.error); return; }
            document.getElementById('agModalTitulo').textContent = 'Editar compromisso';
            document.getElementById('agEvId').value = ev.id;
            document.getElementById('agTitulo').value = ev.titulo;
            document.getElementById('agLocal').value = ev.local || '';
            document.getElementById('agDescricao').value = ev.descricao || '';
            document.getElementById('agModalidade').value = ev.modalidade || 'presencial';
            document.getElementById('agMeetLink').value = ev.meet_link || '';
            if (ev.meet_link) {
                document.getElementById('btnGerarMeet').textContent = 'Gerado';
                document.getElementById('btnGerarMeet').disabled = true;
            } else {
                document.getElementById('btnGerarMeet').textContent = 'Gerar Meet';
                document.getElementById('btnGerarMeet').disabled = false;
            }
            toggleMeet();
            document.getElementById('agDtInicio').value = (ev.data_inicio || '').replace(' ', 'T').substring(0,16);
            document.getElementById('agDtFim').value = (ev.data_fim || '').replace(' ', 'T').substring(0,16);
            document.getElementById('agClienteBusca').value = ev.client_name || '';
            document.getElementById('agClienteId').value = ev.client_id || '';
            document.getElementById('agCasoBusca').value = ev.case_title || '';
            document.getElementById('agCasoId').value = ev.case_id || '';
            document.getElementById('agResponsavel').value = ev.responsavel_id || '';
            document.getElementById('agMsgCliente').value = ev.msg_cliente || '';
            atualizarPreview();

            var btn = document.querySelector('.ag-tipo-btn[data-t="' + ev.tipo + '"]');
            if (btn) selTipo(ev.tipo, btn);

            document.getElementById('agBtnExcluir').style.display = 'inline-block';
            document.getElementById('agOverlay').classList.add('aberto');
        } catch(ex) { alert('Erro ao carregar evento'); }
    };
    xhr.send();
}

function fecharModal() { document.getElementById('agOverlay').classList.remove('aberto'); }

document.getElementById('agOverlay').addEventListener('click', function(e) {
    if (e.target === document.getElementById('agOverlay')) fecharModal();
});

function selTipo(tipo, btn) {
    tipoSelecionado = tipo;
    document.querySelectorAll('.ag-tipo-btn').forEach(function(b) { b.className = 'ag-tipo-btn'; });
    btn.className = 'ag-tipo-btn sel';
    btn.setAttribute('data-t', tipo);
    // Preencher msg padrão se vazio
    var msg = document.getElementById('agMsgCliente');
    if (!msg.value && msgsPadrao[tipo]) msg.value = msgsPadrao[tipo];
    atualizarPreview();
}

function toggleMeet() {
    document.getElementById('agMeetBox').style.display = document.getElementById('agModalidade').value === 'online' ? 'block' : 'none';
}

function gerarMeet() {
    var evId = document.getElementById('agEvId').value;
    var btn = document.getElementById('btnGerarMeet');

    // Se evento ainda não foi salvo, salvar primeiro
    if (!evId || evId === '0') {
        // Precisa salvar o evento antes de gerar Meet
        var titulo = document.getElementById('agTitulo').value.trim();
        var dtInicio = document.getElementById('agDtInicio').value;
        if (!titulo || !dtInicio) { alert('Preencha o título e a data antes de gerar o Meet.'); return; }

        btn.textContent = 'Salvando...';
        btn.disabled = true;

        // Salvar primeiro, depois gerar meet
        var fd = new FormData();
        fd.append('action', 'salvar');
        fd.append('csrf_token', CSRF);
        fd.append('id', '0');
        fd.append('titulo', titulo);
        fd.append('tipo', tipoSelecionado);
        fd.append('modalidade', 'online');
        fd.append('data_inicio', dtInicio.replace('T', ' '));
        fd.append('data_fim', (document.getElementById('agDtFim').value || dtInicio).replace('T', ' '));
        fd.append('local', document.getElementById('agLocal').value);
        fd.append('meet_link', '');
        fd.append('descricao', document.getElementById('agDescricao').value);
        fd.append('client_id', document.getElementById('agClienteId').value);
        fd.append('case_id', document.getElementById('agCasoId').value);
        fd.append('responsavel_id', document.getElementById('agResponsavel').value);
        fd.append('msg_cliente', document.getElementById('agMsgCliente').value);
        fd.append('lembrete_email', '1');
        fd.append('lembrete_whatsapp', '1');
        fd.append('lembrete_portal', '1');
        fd.append('lembrete_cliente', '1');

        var xhr = new XMLHttpRequest();
        xhr.open('POST', API);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function() {
            try {
                var r = JSON.parse(xhr.responseText);
                if (r.error) { alert(r.error); btn.textContent = 'Gerar Meet'; btn.disabled = false; return; }
                // Atualizar CSRF (foi consumido pelo salvar)
                CSRF = r.csrf || CSRF;
                document.getElementById('agEvId').value = r.id;
                // Agora gerar o meet com o ID criado
                chamarGerarMeet(r.id, btn);
            } catch(ex) { alert('Erro ao salvar evento'); btn.textContent = 'Gerar Meet'; btn.disabled = false; }
        };
        xhr.send(fd);
        return;
    }

    btn.textContent = 'Gerando...';
    btn.disabled = true;
    chamarGerarMeet(evId, btn);
}

function chamarGerarMeet(evId, btn) {
    var fd = new FormData();
    fd.append('action', 'gerar_meet');
    fd.append('csrf_token', CSRF);
    fd.append('id', evId);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', API);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onload = function() {
        try {
            var r = JSON.parse(xhr.responseText);
            if (r.csrf) CSRF = r.csrf;
            if (r.error) { alert('Erro: ' + r.error); btn.textContent = 'Gerar Meet'; btn.disabled = false; return; }
            document.getElementById('agMeetLink').value = r.meet_link;
            btn.textContent = 'Gerado!';
            btn.disabled = true;
            btn.style.background = '#059669';
            atualizarPreview();
        } catch(ex) { alert('Erro ao gerar Meet'); btn.textContent = 'Gerar Meet'; btn.disabled = false; }
    };
    xhr.onerror = function() { alert('Erro de rede'); btn.textContent = 'Gerar Meet'; btn.disabled = false; };
    xhr.send(fd);
}

function atualizarPreview() {
    var msg = document.getElementById('agMsgCliente').value;
    var prev = document.getElementById('agMsgPreview');
    if (!msg) { prev.style.display = 'none'; return; }
    var nome = document.getElementById('agClienteBusca').value || '[nome]';
    var dtVal = document.getElementById('agDtInicio').value;
    var data = dtVal ? new Date(dtVal).toLocaleDateString('pt-BR') : '[data]';
    var hora = dtVal ? new Date(dtVal).toLocaleTimeString('pt-BR', {hour:'2-digit',minute:'2-digit'}) : '[hora]';
    var meetVal = document.getElementById('agMeetLink').value || '(link meet)';
    var txt = msg.replace(/\[nome\]/g, nome).replace(/\[data\]/g, data).replace(/\[hora\]/g, hora).replace(/\[link_meet\]/g, meetVal);
    prev.innerHTML = '📱 <strong>Preview:</strong> ' + esc(txt);
    prev.style.display = 'block';
}
document.getElementById('agMsgCliente').addEventListener('input', atualizarPreview);
document.getElementById('agDtInicio').addEventListener('change', atualizarPreview);
document.getElementById('agClienteBusca').addEventListener('input', atualizarPreview);

// ── SALVAR ──────────────────────────────────────────────────
function salvarEvento() {
    var titulo = document.getElementById('agTitulo').value.trim();
    if (!titulo) { document.getElementById('agTitulo').style.borderColor='#ef4444'; document.getElementById('agTitulo').focus(); return; }
    var dtInicio = document.getElementById('agDtInicio').value;
    if (!dtInicio) { document.getElementById('agDtInicio').style.borderColor='#ef4444'; return; }

    var fd = new FormData();
    fd.append('action', 'salvar');
    fd.append('csrf_token', CSRF);
    fd.append('id', document.getElementById('agEvId').value);
    fd.append('titulo', titulo);
    fd.append('tipo', tipoSelecionado);
    fd.append('modalidade', document.getElementById('agModalidade').value);
    fd.append('data_inicio', dtInicio.replace('T', ' '));
    fd.append('data_fim', (document.getElementById('agDtFim').value || dtInicio).replace('T', ' '));
    fd.append('local', document.getElementById('agLocal').value);
    fd.append('meet_link', document.getElementById('agMeetLink').value);
    fd.append('descricao', document.getElementById('agDescricao').value);
    fd.append('client_id', document.getElementById('agClienteId').value);
    fd.append('case_id', document.getElementById('agCasoId').value);
    fd.append('responsavel_id', document.getElementById('agResponsavel').value);
    fd.append('msg_cliente', document.getElementById('agMsgCliente').value);
    fd.append('lembrete_email', '1');
    fd.append('lembrete_whatsapp', '1');
    fd.append('lembrete_portal', '1');
    fd.append('lembrete_cliente', '1');

    var xhr = new XMLHttpRequest();
    xhr.open('POST', API);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onload = function() {
        try {
            var r = JSON.parse(xhr.responseText);
            if (r.csrf) CSRF = r.csrf;
            if (r.error) { alert(r.error); return; }
            fecharModal();
            recarregarEventos();
        } catch(ex) { alert('Erro ao salvar'); }
    };
    xhr.send(fd);
}

// ── AÇÕES ───────────────────────────────────────────────────
function marcarRealizado(id, btn) {
    var fd = new FormData();
    fd.append('action', 'status');
    fd.append('csrf_token', CSRF);
    fd.append('id', id);
    fd.append('status', 'realizado');
    var xhr = new XMLHttpRequest();
    xhr.open('POST', API);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onload = function() {
        try { var r = JSON.parse(xhr.responseText); if (r.csrf) CSRF = r.csrf; } catch(e) {}
        btn.textContent = 'Concluido';
        btn.style.background = '#888';
        btn.style.borderColor = '#888';
        btn.disabled = true;
        setTimeout(recarregarEventos, 500);
    };
    xhr.send(fd);
}

function excluirEvento(id) {
    if (!confirm('Tem certeza que deseja excluir este compromisso?')) return;
    var fd = new FormData();
    fd.append('action', 'excluir');
    fd.append('csrf_token', CSRF);
    fd.append('id', id);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', API);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onload = function() {
        try { var r = JSON.parse(xhr.responseText); if (r.csrf) CSRF = r.csrf; if (r.error) { alert(r.error); return; } }
        catch(e) {}
        recarregarEventos();
    };
    xhr.send(fd);
}

function excluirEventoModal() {
    var id = document.getElementById('agEvId').value;
    if (!id || id === '0') return;
    if (!confirm('Tem certeza que deseja excluir este compromisso?')) return;
    var fd = new FormData();
    fd.append('action', 'excluir');
    fd.append('csrf_token', CSRF);
    fd.append('id', id);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', API);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onload = function() {
        try { var r = JSON.parse(xhr.responseText); if (r.csrf) CSRF = r.csrf; if (r.error) { alert(r.error); return; } }
        catch(e) {}
        fecharModal();
        recarregarEventos();
    };
    xhr.send(fd);
}

function enviarConvite(id) {
    var usersHtml = '';
    <?php foreach ($users as $u): ?>
    usersHtml += '<label style="display:flex;align-items:center;gap:6px;padding:4px 0;font-size:13px;cursor:pointer;">'
        + '<input type="checkbox" value="<?= e($u['email']) ?>" class="convite-cb"> '
        + '<?= e($u['name']) ?> <span style="color:#94a3b8;font-size:11px;">(<?= e($u['email']) ?>)</span></label>';
    <?php endforeach; ?>

    var div = document.createElement('div');
    div.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:2000;display:flex;align-items:center;justify-content:center;';
    div.innerHTML = '<div style="background:#fff;border-radius:12px;padding:1.5rem;max-width:420px;width:90%;max-height:80vh;overflow-y:auto;">'
        + '<h3 style="margin:0 0 .5rem;font-size:1rem;">Enviar convite do Google Calendar</h3>'
        + '<p style="font-size:.8rem;color:#6b7280;margin:0 0 1rem;">Os selecionados vao receber o compromisso na agenda pessoal.</p>'
        + '<div id="conviteUsers">' + usersHtml + '</div>'
        + '<div style="margin-top:.8rem;"><input type="text" id="conviteExtra" placeholder="Outros e-mails (separados por virgula)" style="width:100%;padding:6px 10px;border:1.5px solid #e5e7eb;border-radius:6px;font-size:12px;"></div>'
        + '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:1rem;">'
        + '<button onclick="this.closest(\'div[style]\').remove()" style="padding:6px 14px;border:1.5px solid #e5e7eb;border-radius:6px;background:none;cursor:pointer;font-size:13px;">Cancelar</button>'
        + '<button onclick="confirmarConvite(' + id + ',this)" style="padding:6px 14px;background:#052228;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;">Enviar</button>'
        + '</div></div>';
    div.addEventListener('click', function(e) { if (e.target === div) div.remove(); });
    document.body.appendChild(div);
}

function confirmarConvite(evId, btn) {
    var checks = document.querySelectorAll('.convite-cb:checked');
    var emails = [];
    checks.forEach(function(cb) { emails.push(cb.value); });
    var extra = document.getElementById('conviteExtra').value;
    if (extra) {
        extra.split(',').forEach(function(em) { em = em.trim(); if (em) emails.push(em); });
    }
    if (!emails.length) { alert('Selecione pelo menos um participante.'); return; }

    btn.textContent = 'Enviando...';
    btn.disabled = true;

    var fd = new FormData();
    fd.append('action', 'enviar_convite');
    fd.append('csrf_token', CSRF);
    fd.append('id', evId);
    fd.append('emails', emails.join(','));

    var xhr = new XMLHttpRequest();
    xhr.open('POST', API);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onload = function() {
        try {
            var r = JSON.parse(xhr.responseText);
            if (r.csrf) CSRF = r.csrf;
            if (r.error) { alert(r.error); btn.textContent = 'Enviar'; btn.disabled = false; return; }
            alert('Convites enviados para ' + r.enviados + ' pessoa(s)! Vao receber na agenda pessoal.');
            btn.closest('div[style*="position:fixed"]').remove();
        } catch(e) { alert('Erro ao enviar convites'); btn.textContent = 'Enviar'; btn.disabled = false; }
    };
    xhr.send(fd);
}

function enviarMsgCliente(id) {
    var ev = eventos.filter(function(e) { return e.id == id; })[0];
    if (!ev || !ev.client_phone) { alert('Cliente sem telefone cadastrado.'); return; }
    var phone = ev.client_phone.replace(/\D/g, '');
    if (phone.length < 11) phone = '55' + phone;
    else if (phone.length === 11) phone = '55' + phone;
    var msg = ev.msg_cliente || '';
    if (msg) {
        var dt = new Date(ev.data_inicio.replace(' ','T'));
        msg = msg.replace(/\[nome\]/g, ev.client_name || '')
                 .replace(/\[data\]/g, dt.toLocaleDateString('pt-BR'))
                 .replace(/\[hora\]/g, dt.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'}))
                 .replace(/\[link_meet\]/g, ev.meet_link || '');
    }
    window.open('https://wa.me/' + phone + '?text=' + encodeURIComponent(msg), '_blank');
}

// ── AUTOCOMPLETE ────────────────────────────────────────────
function setupAC(inputId, listId, hiddenId, acaoUrl, renderFn) {
    var input = document.getElementById(inputId);
    var list = document.getElementById(listId);
    var hidden = document.getElementById(hiddenId);
    var timer = null;
    input.addEventListener('input', function() {
        clearTimeout(timer);
        hidden.value = '';
        var q = this.value.trim();
        if (q.length < 2) { list.style.display = 'none'; return; }
        timer = setTimeout(function() {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', API + '?action=' + acaoUrl + '&q=' + encodeURIComponent(q));
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function() {
                try {
                    var items = JSON.parse(xhr.responseText);
                    if (!items.length) { list.innerHTML = '<div class="ag-ac-item" style="color:var(--text-muted);">Nenhum encontrado</div>'; list.style.display = 'block'; return; }
                    list.innerHTML = items.map(function(item) { return renderFn(item); }).join('');
                    list.style.display = 'block';
                    list.querySelectorAll('.ag-ac-item').forEach(function(el) {
                        el.addEventListener('click', function() {
                            hidden.value = el.getAttribute('data-id');
                            input.value = el.getAttribute('data-label');
                            list.style.display = 'none';
                            atualizarPreview();
                        });
                    });
                } catch(ex) { list.style.display = 'none'; }
            };
            xhr.send();
        }, 300);
    });
    document.addEventListener('click', function(e) {
        if (!input.contains(e.target) && !list.contains(e.target)) list.style.display = 'none';
    });
}

setupAC('agClienteBusca', 'agClienteList', 'agClienteId', 'busca_cliente', function(c) {
    return '<div class="ag-ac-item" data-id="' + c.id + '" data-label="' + esc(c.name) + '"><strong>' + esc(c.name) + '</strong>' + (c.phone ? ' — ' + esc(c.phone) : '') + '</div>';
});
setupAC('agCasoBusca', 'agCasoList', 'agCasoId', 'busca_caso', function(c) {
    return '<div class="ag-ac-item" data-id="' + c.id + '" data-label="' + esc(c.title) + '">' + esc(c.title) + (c.case_number ? ' — ' + esc(c.case_number) : '') + (c.client_name ? ' (' + esc(c.client_name) + ')' : '') + '</div>';
});

// ── UTILS ───────────────────────────────────────────────────
function pad(n) { return n < 10 ? '0'+n : ''+n; }
function fmtDate(d) { return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate()); }
function fmtDatetime(d) { return fmtDate(d)+'T'+pad(d.getHours())+':'+pad(d.getMinutes()); }
function esc(s) { if (!s) return ''; var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
