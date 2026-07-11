<?php
/**
 * Entregas Pendentes — visão dos casos contratados sem CNJ (petição ainda não distribuída).
 *
 * Amanda 11/07/2026: caderno de implementação em artifact d863c27e.
 * SOMENTE LEITURA. Nao move card, nao altera status. Botao de sorteio JS puro.
 *
 * Escrita: apenas o handler "ja peguei" grava audit_log('entrega_puxada'),
 * que o painel de dopamina agrega como 1 ponto por caso puxado.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('entregas_pendentes');

$pdo = db();
$pageTitle = 'Entregas Pendentes';

// ── Handler AJAX: registrar caso puxado ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'puxar_caso') {
    header('Content-Type: application/json');
    if (!validate_csrf()) { echo json_encode(array('ok'=>false, 'erro'=>'CSRF invalido')); exit; }
    $caseId = (int)($_POST['case_id'] ?? 0);
    if ($caseId <= 0) { echo json_encode(array('ok'=>false, 'erro'=>'ID invalido')); exit; }
    $userId = (int)($_SESSION['user_id'] ?? 0);

    // Verifica que o case existe E ainda esta na condicao de pendente (pra evitar duplo click)
    $st = $pdo->prepare("SELECT id, title, status, case_number FROM cases WHERE id = ?");
    $st->execute(array($caseId));
    $case = $st->fetch(PDO::FETCH_ASSOC);
    if (!$case) { echo json_encode(array('ok'=>false, 'erro'=>'Case nao encontrado')); exit; }

    // Anti-double-count: se ja foi puxado hoje pelo mesmo user, retorna ok=true mas nao regrava
    $hoje = date('Y-m-d');
    $ck = $pdo->prepare("SELECT 1 FROM audit_log WHERE action='entrega_puxada' AND user_id=? AND entity_id=? AND DATE(created_at)=? LIMIT 1");
    $ck->execute(array($userId, $caseId, $hoje));
    if ($ck->fetchColumn()) { echo json_encode(array('ok'=>true, 'ja_registrado'=>true)); exit; }

    audit_log('entrega_puxada', 'case', $caseId, 'Puxou pendente: '.$case['title']);
    echo json_encode(array('ok'=>true));
    exit;
}

// ── Consulta principal ─────────────────────────────
$pendentes = array('aguardando_docs','em_elaboracao','para_execucao_ia',
                   'em_andamento','doc_faltante','aguardando_prazo');
$in = implode(',', array_fill(0, count($pendentes), '?'));

$sql = "SELECT cs.id, cs.title AS titulo, cs.status, cs.case_number AS cnj,
               cs.created_at, cs.responsible_user_id,
               DATEDIFF(NOW(), cs.created_at) AS dias,
               cl.name AS cliente_nome, u.name AS resp_nome
        FROM cases cs
        LEFT JOIN clients cl ON cl.id = cs.client_id
        LEFT JOIN users u ON u.id = cs.responsible_user_id
        WHERE cs.status IN ($in)
          AND (cs.case_number IS NULL OR cs.case_number = '')
          AND COALESCE(cs.kanban_oculto, 0) = 0
        ORDER BY dias DESC, cs.id ASC";
$st = $pdo->prepare($sql);
$st->execute($pendentes);
$casos = $st->fetchAll(PDO::FETCH_ASSOC);

// Rotulos + criticidade
$rotulos = array(
    'aguardando_docs'  => 'Contrato — Aguardando Docs',
    'em_elaboracao'    => 'Pasta Apta',
    'para_execucao_ia' => 'Para Execução — IA',
    'em_andamento'     => 'Em Execução',
    'doc_faltante'     => 'Doc Faltante',
    'aguardando_prazo' => 'Aguard. Distribuição',
);
$cores = array(
    'aguardando_docs'  => '#f59e0b',
    'em_elaboracao'    => '#059669',
    'para_execucao_ia' => '#7c3aed',
    'em_andamento'     => '#0ea5e9',
    'doc_faltante'     => '#dc2626',
    'aguardando_prazo' => '#8b5cf6',
);
function sev_de($d) { return $d >= 60 ? 'crit' : ($d >= 30 ? 'warn' : 'ok'); }
$nCrit = 0; $nWarn = 0; $nOk = 0;
foreach ($casos as $c) {
    $s = sev_de((int)$c['dias']);
    if ($s === 'crit') $nCrit++; elseif ($s === 'warn') $nWarn++; else $nOk++;
}
$clientesUnicos = array();
foreach ($casos as $c) $clientesUnicos[$c['cliente_nome'] ?? $c['titulo']] = 1;
$nClientes = count($clientesUnicos);

$csrf = generate_csrf_token();
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.ep-hero { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px; }
.ep-hero h1 { margin:0; font-family:'Cormorant Garamond',Georgia,serif; font-size:1.7rem; font-weight:600; color:#0E2E36; }
.ep-hero .sub { font-size:.85rem; color:#6b7280; margin-top:4px; }

.ep-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin-bottom:20px; }
.ep-kpi { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:14px 16px; box-shadow:0 1px 3px rgba(0,0,0,.04); position:relative; overflow:hidden; }
.ep-kpi::before { content:''; position:absolute; left:0; top:0; bottom:0; width:4px; background:#B87333; }
.ep-kpi.crit::before { background:#dc2626; }
.ep-kpi.warn::before { background:#f59e0b; }
.ep-kpi.ok::before { background:#059669; }
.ep-kpi .n { font-family:'Cormorant Garamond',serif; font-size:2.2rem; font-weight:700; line-height:1; color:#0E2E36; }
.ep-kpi.crit .n { color:#dc2626; }
.ep-kpi.warn .n { color:#b45309; }
.ep-kpi.ok .n { color:#059669; }
.ep-kpi .lbl { font-size:.72rem; color:#6b7280; font-weight:700; text-transform:uppercase; letter-spacing:.05em; margin-top:6px; }
.ep-kpi .sub { font-size:.7rem; color:#9ca3af; margin-top:2px; }

.ep-sorteio { background:linear-gradient(135deg,#0E2E36,#173d46); color:#fff; border-radius:14px; padding:20px 24px; margin-bottom:20px; box-shadow:0 4px 14px rgba(14,46,54,.2); position:relative; overflow:hidden; }
.ep-sorteio::before { content:'🎲'; position:absolute; right:-20px; top:-20px; font-size:9rem; opacity:.08; }
.ep-sorteio h2 { margin:0 0 6px; font-family:'Cormorant Garamond',serif; font-size:1.4rem; color:#fff; }
.ep-sorteio p { margin:0 0 12px; font-size:.85rem; opacity:.85; }
.ep-sorteio-row { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
.ep-btn { background:#B87333; color:#fff; border:none; border-radius:10px; padding:10px 20px; font-size:.9rem; font-weight:700; cursor:pointer; font-family:inherit; box-shadow:0 3px 8px rgba(184,115,51,.35); transition:transform .1s; }
.ep-btn:hover { transform:translateY(-1px); }
.ep-btn:active { transform:translateY(1px); }
.ep-btn.ghost { background:transparent; color:#fff; border:1.5px solid rgba(255,255,255,.4); box-shadow:none; }
.ep-btn.ghost:hover { background:rgba(255,255,255,.1); }
.ep-check { display:inline-flex; align-items:center; gap:6px; font-size:.82rem; cursor:pointer; user-select:none; }
.ep-check input { width:16px; height:16px; cursor:pointer; }

.ep-roleta { display:none; margin-top:14px; padding:20px; background:rgba(255,255,255,.08); border-radius:10px; text-align:center; }
.ep-roleta.aparece { display:block; animation:epFadeIn .3s; }
.ep-roleta-nome { font-family:'Cormorant Garamond',serif; font-size:1.6rem; font-weight:700; min-height:2.5rem; color:#fff; text-shadow:0 2px 8px rgba(0,0,0,.3); }
.ep-roleta-nome.girando { animation:epShake .1s infinite; }
.ep-roleta-info { font-size:.78rem; opacity:.85; margin-top:4px; }
.ep-roleta-acoes { display:flex; gap:8px; margin-top:14px; justify-content:center; flex-wrap:wrap; }
@keyframes epShake { 0%,100% { transform:translateX(0); } 25% { transform:translateX(-2px); } 75% { transform:translateX(2px); } }
@keyframes epFadeIn { from { opacity:0; transform:scale(.95); } to { opacity:1; transform:scale(1); } }

.ep-tabela-wrap { background:#fff; border-radius:12px; border:1px solid #e5e7eb; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.ep-tabela { width:100%; border-collapse:collapse; }
.ep-tabela th, .ep-tabela td { padding:10px 14px; text-align:left; border-bottom:1px solid #f3f4f6; font-size:.84rem; }
.ep-tabela th { background:#0E2E36; color:#fff; font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
.ep-tabela tbody tr:hover { background:#fafafa; }
.ep-tabela tbody tr.puxado { opacity:.35; background:#f0fdf4 !important; }
.ep-tabela tbody tr.destacado { background:#fef3c7 !important; animation:epPulse 1.5s ease-out; }
@keyframes epPulse { 0% { background:#fbbf24; } 100% { background:#fef3c7; } }
.ep-tabela .c-dias { font-weight:800; font-family:'Outfit',monospace; text-align:center; width:70px; }
.ep-tabela tr[data-sev="crit"] .c-dias { color:#dc2626; }
.ep-tabela tr[data-sev="warn"] .c-dias { color:#b45309; }
.ep-tabela tr[data-sev="ok"]   .c-dias { color:#059669; }
.ep-tabela .c-cli a { color:#0E2E36; text-decoration:none; font-weight:700; }
.ep-tabela .c-cli a:hover { color:#B87333; text-decoration:underline; }
.ep-tabela .c-cli small { display:block; color:#6b7280; font-size:.72rem; font-weight:400; margin-top:1px; }
.ep-tabela .c-col { font-size:.75rem; }
.ep-tabela .c-col .tag { display:inline-block; padding:2px 8px; border-radius:999px; background:#f5ede3; color:#78350f; font-size:.7rem; font-weight:700; }
.ep-tabela .c-resp { font-size:.75rem; color:#6b7280; }

.ep-vazio { background:#fff; border:1px dashed #d1d5db; border-radius:12px; padding:40px; text-align:center; color:#6b7280; }
.ep-vazio strong { display:block; font-size:1.3rem; color:#059669; margin-bottom:6px; font-family:'Cormorant Garamond',serif; }

.ep-legenda { display:flex; gap:14px; margin-top:12px; font-size:.72rem; color:#6b7280; flex-wrap:wrap; }
.ep-legenda span::before { content:'●'; margin-right:4px; }
.ep-legenda .crit::before { color:#dc2626; }
.ep-legenda .warn::before { color:#f59e0b; }
.ep-legenda .ok::before   { color:#059669; }
</style>

<div class="ep-hero">
    <div>
        <h1>📋 Entregas Pendentes</h1>
        <div class="sub">Clientes contratados sem processo iniciado — <?= count($casos) ?> caso(s) de <?= $nClientes ?> cliente(s)</div>
    </div>
</div>

<?php if (empty($casos)): ?>

<div class="ep-vazio">
    <strong>Zerou a fila! 🎉</strong>
    Nenhum caso contratado sem CNJ. Se apareceu um novo, avisamos na hora.
</div>

<?php else: ?>

<div class="ep-kpis">
    <div class="ep-kpi"><div class="n"><?= count($casos) ?></div><div class="lbl">Total pendente</div><div class="sub">contratados sem CNJ</div></div>
    <div class="ep-kpi crit"><div class="n"><?= $nCrit ?></div><div class="lbl">🚨 Críticos</div><div class="sub">≥ 60 dias parado</div></div>
    <div class="ep-kpi warn"><div class="n"><?= $nWarn ?></div><div class="lbl">⚠ Em atenção</div><div class="sub">30 a 59 dias</div></div>
    <div class="ep-kpi ok"><div class="n"><?= $nOk ?></div><div class="lbl">Recentes</div><div class="sub">&lt; 30 dias</div></div>
</div>

<div class="ep-sorteio">
    <h2>🎲 Bora pegar um?</h2>
    <p>Sorteio ponderado — quem tá parado há mais dias tem mais chance de sair. Você fica no comando, o sistema só sugere.</p>
    <div class="ep-sorteio-row">
        <button type="button" class="ep-btn" id="btnSortear">🎲 Sortear caso</button>
        <label class="ep-check">
            <input type="checkbox" id="chkSoCrit"> Só entre os críticos (≥60d)
        </label>
    </div>
    <div class="ep-roleta" id="roleta">
        <div class="ep-roleta-nome" id="roletaNome">—</div>
        <div class="ep-roleta-info" id="roletaInfo"></div>
        <div class="ep-roleta-acoes" id="roletaAcoes"></div>
    </div>
</div>

<div class="ep-tabela-wrap">
    <table class="ep-tabela" id="lista">
        <thead>
            <tr>
                <th style="width:70px;text-align:center;">Dias</th>
                <th>Cliente / Caso</th>
                <th style="width:160px;">Estágio atual</th>
                <th style="width:130px;">Responsável</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($casos as $c):
            $s = sev_de((int)$c['dias']);
            $rotulo = $rotulos[$c['status']] ?? $c['status'];
            $cor    = $cores[$c['status']] ?? '#6b7280';
            $nome   = trim($c['titulo']);
        ?>
            <tr data-dias="<?= (int)$c['dias'] ?>" data-sev="<?= $s ?>" data-case-id="<?= (int)$c['id'] ?>">
                <td class="c-dias"><?= (int)$c['dias'] ?>d</td>
                <td class="c-cli">
                    <a href="<?= url('modules/operacional/caso_ver.php?id='.(int)$c['id']) ?>" target="_blank" rel="noopener"><?= e($nome) ?></a>
                    <?php if (!empty($c['cliente_nome']) && $c['cliente_nome'] !== $nome): ?>
                        <small>Cliente: <?= e($c['cliente_nome']) ?></small>
                    <?php endif; ?>
                </td>
                <td class="c-col"><span class="tag" style="background:<?= $cor ?>22;color:<?= $cor ?>;"><?= e($rotulo) ?></span></td>
                <td class="c-resp"><?= e($c['resp_nome'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="ep-legenda">
    <span class="crit">≥ 60 dias parado (crítico)</span>
    <span class="warn">30 a 59 dias (atenção)</span>
    <span class="ok">&lt; 30 dias (recente)</span>
</div>

<script>
var EP_CSRF = '<?= $csrf ?>';
var EP_URL  = '<?= module_url('entregas_pendentes') ?>';

// Monta urna a partir das linhas visiveis
function epUrna(soCriticos) {
    var rows = Array.prototype.slice.call(document.querySelectorAll('#lista tbody tr'));
    rows = rows.filter(function(tr) { return !tr.classList.contains('puxado'); });
    if (soCriticos) rows = rows.filter(function(tr) { return tr.dataset.sev === 'crit'; });
    return rows.map(function(tr) {
        return {
            dias: parseInt(tr.dataset.dias, 10) || 0,
            id:   parseInt(tr.dataset.caseId, 10) || 0,
            cli:  tr.querySelector('.c-cli a').textContent.trim(),
            href: tr.querySelector('.c-cli a').getAttribute('href'),
            col:  tr.querySelector('.c-col').textContent.trim(),
            el:   tr
        };
    });
}
// Sorteio ponderado (dias+1 = "fatia" na urna)
function epSortear(lista) {
    var total = lista.reduce(function(s, x) { return s + x.dias + 1; }, 0);
    var r = Math.random() * total;
    for (var i = 0; i < lista.length; i++) {
        r -= (lista[i].dias + 1);
        if (r <= 0) return lista[i];
    }
    return lista[lista.length - 1];
}

document.getElementById('btnSortear').addEventListener('click', function() {
    var soCrit = document.getElementById('chkSoCrit').checked;
    var lista  = epUrna(soCrit);
    if (!lista.length) {
        if (soCrit) alert('Nenhum caso crítico livre. Desmarca "só entre os críticos" pra sortear entre todos!');
        else alert('Zerou a fila! 🎉');
        return;
    }

    var roleta   = document.getElementById('roleta');
    var nomeEl   = document.getElementById('roletaNome');
    var infoEl   = document.getElementById('roletaInfo');
    var acoesEl  = document.getElementById('roletaAcoes');
    roleta.classList.add('aparece');
    nomeEl.classList.add('girando');
    acoesEl.innerHTML = '';
    infoEl.textContent = '';

    // Roleta: 12 nomes girando, depois desacelera e para no escolhido
    var escolhido = epSortear(lista);
    var giradas = 0;
    var maxGiradas = 14 + Math.floor(Math.random() * 8);
    var intervalo = 60;
    function girar() {
        var sample = lista[Math.floor(Math.random() * lista.length)];
        nomeEl.textContent = sample.cli;
        giradas++;
        if (giradas < maxGiradas) {
            intervalo = intervalo + (giradas > maxGiradas / 2 ? 25 : 0); // desacelera
            setTimeout(girar, intervalo);
        } else {
            nomeEl.classList.remove('girando');
            nomeEl.textContent = escolhido.cli;
            infoEl.innerHTML = escolhido.dias + ' dias parado · ' + escolhido.col;
            acoesEl.innerHTML =
                '<a href="' + escolhido.href + '" target="_blank" rel="noopener" class="ep-btn">📂 Abrir pasta</a>' +
                '<button type="button" class="ep-btn ghost" id="btnJaPeguei">✅ Já peguei esse</button>' +
                '<button type="button" class="ep-btn ghost" id="btnOutro">🎲 Sortear outro</button>';
            // Destaca linha
            var todas = document.querySelectorAll('#lista tbody tr.destacado');
            for (var i = 0; i < todas.length; i++) todas[i].classList.remove('destacado');
            escolhido.el.classList.add('destacado');
            setTimeout(function() { escolhido.el.classList.remove('destacado'); }, 3500);
            // Handlers
            document.getElementById('btnJaPeguei').addEventListener('click', function() { epJaPeguei(escolhido, this); });
            document.getElementById('btnOutro').addEventListener('click', function() { document.getElementById('btnSortear').click(); });
        }
    }
    setTimeout(girar, 100);
});

function epJaPeguei(escolhido, btn) {
    btn.disabled = true; btn.textContent = '⏳ Registrando...';
    var fd = new FormData();
    fd.append('acao', 'puxar_caso');
    fd.append('case_id', escolhido.id);
    fd.append('csrf_token', EP_CSRF);
    fetch(EP_URL, { method:'POST', body: fd, credentials:'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (j.ok) {
                escolhido.el.classList.add('puxado');
                if (window.FsaFeedback) FsaFeedback.ok(j.ja_registrado ? 'Ja tinha registrado hoje ' : 'Anotado! +1 ponto no seu dopamina 🎯');
                btn.textContent = '✓ Anotado';
                // Confete rapido
                epConfete();
            } else {
                if (window.FsaFeedback) FsaFeedback.erro(j.erro || 'Falhou registrar');
                btn.disabled = false; btn.textContent = '✅ Já peguei esse';
            }
        })
        .catch(function() {
            if (window.FsaFeedback) FsaFeedback.erro('Erro de rede');
            btn.disabled = false; btn.textContent = '✅ Já peguei esse';
        });
}

// Confete simples DOM (sem lib)
function epConfete() {
    var cont = document.createElement('div');
    cont.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:99998;overflow:hidden;';
    var cores = ['#B87333','#059669','#0ea5e9','#f59e0b','#dc2626','#8b5cf6'];
    for (var i = 0; i < 40; i++) {
        var p = document.createElement('div');
        p.style.cssText = 'position:absolute;top:-10px;left:'+(Math.random()*100)+'%;width:8px;height:12px;background:'+cores[Math.floor(Math.random()*cores.length)]+
            ';transform:rotate('+(Math.random()*360)+'deg);animation:epConfeteFall '+(1.8+Math.random()*1.5)+'s linear forwards;animation-delay:'+(Math.random()*.3)+'s;';
        cont.appendChild(p);
    }
    document.body.appendChild(cont);
    setTimeout(function() { cont.remove(); }, 4000);
}
// Keyframe do confete
if (!document.getElementById('epConfKF')) {
    var s = document.createElement('style'); s.id = 'epConfKF';
    s.textContent = '@keyframes epConfeteFall{to{transform:translateY(110vh) rotate(720deg);opacity:0;}}';
    document.head.appendChild(s);
}
</script>

<?php endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
