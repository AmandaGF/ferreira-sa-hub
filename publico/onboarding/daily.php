<?php
/**
 * Página pública: Daily Planner da colaboradora.
 *
 * Cada dia tem:
 *  - Humor (emoji)
 *  - Foco principal do dia (1 frase)
 *  - Lista de tarefas (texto + checkbox)
 *  - Notas livres
 *  - Aprendizado / reflexão
 *
 * Navegação ◀ Ontem | Hoje | Amanhã ▶ + miniatura dos últimos 7 dias.
 * Salva via POST (sem AJAX). Self-heal da tabela.
 */
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';

@session_start();

$pdo = db();
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
if (!$token || !preg_match('/^[a-f0-9]{16,48}$/', $token)) {
    http_response_code(404); exit('Link inválido.');
}

$st = $pdo->prepare("SELECT * FROM colaboradores_onboarding WHERE token = ? AND status != 'arquivado'");
$st->execute(array($token));
$reg = $st->fetch();
if (!$reg) { http_response_code(404); exit('Link inválido.'); }

$sessKey = 'onb_auth_' . $token;
if (empty($_SESSION[$sessKey])) {
    header('Location: ./?token=' . urlencode($token)); exit;
}

// Self-heal
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS colaboradores_daily (
        id INT AUTO_INCREMENT PRIMARY KEY,
        colaborador_id INT NOT NULL,
        data DATE NOT NULL,
        humor VARCHAR(20) NULL,
        foco_principal VARCHAR(300) NULL,
        tarefas_json LONGTEXT NULL,
        notas TEXT NULL,
        aprendizados TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_col_data (colaborador_id, data),
        INDEX idx_data (data)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Data alvo (default hoje)
$dataFiltro = $_GET['data'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFiltro)) $dataFiltro = date('Y-m-d');
$dataAnt = date('Y-m-d', strtotime($dataFiltro . ' -1 day'));
$dataProx = date('Y-m-d', strtotime($dataFiltro . ' +1 day'));
$hoje = date('Y-m-d');

// Salvamento
$msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_daily'])) {
    $humor = trim($_POST['humor'] ?? '');
    $foco = trim($_POST['foco_principal'] ?? '');
    $notas = trim($_POST['notas'] ?? '');
    $aprend = trim($_POST['aprendizados'] ?? '');
    $tarefasRaw = $_POST['tarefas_json'] ?? '[]';
    $tarefas = json_decode($tarefasRaw, true);
    if (!is_array($tarefas)) $tarefas = array();
    $dataSalvar = trim($_POST['data'] ?? $dataFiltro);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataSalvar)) $dataSalvar = $dataFiltro;

    try {
        $pdo->prepare("INSERT INTO colaboradores_daily
                       (colaborador_id, data, humor, foco_principal, tarefas_json, notas, aprendizados)
                       VALUES (?, ?, ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE
                            humor = VALUES(humor),
                            foco_principal = VALUES(foco_principal),
                            tarefas_json = VALUES(tarefas_json),
                            notas = VALUES(notas),
                            aprendizados = VALUES(aprendizados)")
            ->execute(array($reg['id'], $dataSalvar, $humor ?: null, $foco ?: null,
                            json_encode($tarefas, JSON_UNESCAPED_UNICODE), $notas ?: null, $aprend ?: null));
        header('Location: ?token=' . urlencode($token) . '&data=' . $dataSalvar . '&ok=1'); exit;
    } catch (Exception $e) {
        $msg = array('tipo' => 'erro', 'txt' => 'Erro ao salvar: ' . $e->getMessage());
    }
}

// Carrega o daily do dia
$st = $pdo->prepare("SELECT * FROM colaboradores_daily WHERE colaborador_id = ? AND data = ?");
$st->execute(array($reg['id'], $dataFiltro));
$daily = $st->fetch();

$humor = $daily['humor'] ?? '';
$foco = $daily['foco_principal'] ?? '';
$tarefas = array();
if (!empty($daily['tarefas_json'])) {
    $decoded = json_decode($daily['tarefas_json'], true);
    if (is_array($decoded)) $tarefas = $decoded;
}
$notas = $daily['notas'] ?? '';
$aprend = $daily['aprendizados'] ?? '';

// Últimos 7 dias (miniatura)
$ultimos = array();
try {
    $st = $pdo->prepare("SELECT data, humor, foco_principal, tarefas_json
                         FROM colaboradores_daily
                         WHERE colaborador_id = ? AND data BETWEEN DATE_SUB(?, INTERVAL 6 DAY) AND ?
                         ORDER BY data DESC");
    $st->execute(array($reg['id'], $hoje, $hoje));
    foreach ($st->fetchAll() as $r) {
        $ultimos[$r['data']] = $r;
    }
} catch (Exception $e) {}

// Formata data BR
function data_br($iso) {
    if (!$iso) return '';
    $dt = DateTime::createFromFormat('Y-m-d', $iso);
    if (!$dt) return $iso;
    $diasSemana = array('domingo','segunda','terça','quarta','quinta','sexta','sábado');
    return $diasSemana[(int)$dt->format('w')] . ', ' . $dt->format('d/m/Y');
}

$primeiroNome = explode(' ', $reg['nome_completo'])[0];

$humorOpcoes = array(
    '😄' => 'Ótimo',
    '🙂' => 'Bem',
    '😐' => 'Neutro',
    '😞' => 'Difícil',
    '😩' => 'Exausta(o)',
);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>📓 Daily Planner — Ferreira e Sá</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root { --petrol-900:#052228; --petrol-700:#173d46; --cobre:#6a3c2c; --cobre-light:#B87333; --nude:#d7ab90; --nude-light:#fff7ed; --bg:#f8f4ef; }
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Open Sans',sans-serif; background:var(--bg); color:#1a1a1a; min-height:100vh; line-height:1.55; }
h1,h2,h3 { font-family:'Playfair Display',serif; color:var(--petrol-900); }

.toolbar { background:linear-gradient(135deg,var(--petrol-900),var(--petrol-700)); color:#fff; padding:1rem 1.5rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap; justify-content:space-between; position:sticky; top:0; z-index:100; box-shadow:0 4px 14px rgba(0,0,0,.15); }
.toolbar h1 { color:#fff; font-size:1.1rem; }
.toolbar a.btn-back { background:rgba(255,255,255,.15); color:#fff; padding:.5rem 1rem; border-radius:8px; text-decoration:none; font-size:.85rem; font-weight:600; }

.container { max-width:880px; margin:1.5rem auto 3rem; padding:0 1.2rem; }
.card-block { background:#fff; border-radius:14px; box-shadow:0 4px 18px rgba(0,0,0,.06); padding:1.5rem 1.5rem; margin-bottom:1.2rem; }
.card-block h2 { font-size:1.2rem; margin-bottom:.6rem; }

.dia-nav { display:flex; align-items:center; justify-content:space-between; gap:.5rem; background:#fff; border-radius:14px; padding:.85rem 1.1rem; margin-bottom:1rem; box-shadow:0 2px 8px rgba(0,0,0,.04); }
.dia-nav a { background:var(--nude-light); color:var(--cobre); padding:.45rem .9rem; border-radius:8px; text-decoration:none; font-weight:700; font-size:.85rem; }
.dia-nav a.hoje { background:var(--cobre-light); color:#fff; }
.dia-nav .central { font-weight:800; color:var(--petrol-900); text-transform:capitalize; }

.semana-mini { display:grid; grid-template-columns:repeat(7,1fr); gap:.4rem; margin-bottom:1rem; }
.semana-mini a { background:#fff; border:1.5px solid #e5e7eb; border-radius:10px; padding:.55rem .3rem; text-align:center; text-decoration:none; color:#374151; font-size:.7rem; font-weight:700; transition:all .15s; }
.semana-mini a.atual { border-color:var(--cobre-light); background:var(--nude-light); color:var(--cobre); }
.semana-mini a.preenchido { border-color:#34d399; background:#ecfdf5; color:#065f46; }
.semana-mini a .num { font-size:1.1rem; display:block; }
.semana-mini a .emj { font-size:.95rem; }

.humor-grid { display:flex; gap:.5rem; flex-wrap:wrap; }
.humor-grid label { flex:1; min-width:90px; cursor:pointer; }
.humor-grid label input { display:none; }
.humor-grid label .pill { display:flex; flex-direction:column; align-items:center; gap:.2rem; padding:.7rem .5rem; border:2px solid #e5e7eb; border-radius:12px; transition:all .15s; }
.humor-grid label input:checked + .pill { border-color:var(--cobre-light); background:var(--nude-light); transform:scale(1.04); }
.humor-grid label .emoji { font-size:1.8rem; line-height:1; }
.humor-grid label .lbl { font-size:.74rem; color:#6b7280; font-weight:700; }
.humor-grid label input:checked ~ .pill .lbl { color:var(--cobre); }

input[type="text"], input[type="date"], textarea { width:100%; padding:.65rem .85rem; border:1.5px solid #e5e7eb; border-radius:10px; font-size:.92rem; font-family:inherit; }
input[type="text"]:focus, textarea:focus { outline:none; border-color:var(--cobre-light); box-shadow:0 0 0 3px rgba(184,115,51,.15); }
textarea { resize:vertical; min-height:90px; }

.tarefa-row { display:flex; gap:.5rem; align-items:center; padding:.4rem 0; }
.tarefa-row input[type="checkbox"] { width:20px; height:20px; flex-shrink:0; cursor:pointer; }
.tarefa-row input[type="text"] { flex:1; padding:.45rem .65rem; }
.tarefa-row .feito input[type="text"] { text-decoration:line-through; opacity:.6; color:#6b7280; }
.tarefa-row button { background:#fee2e2; color:#991b1b; border:0; width:30px; height:30px; border-radius:7px; cursor:pointer; font-weight:700; flex-shrink:0; }
.btn-add { background:var(--nude-light); border:1.5px dashed var(--nude); color:var(--cobre); padding:.55rem 1rem; border-radius:10px; font-weight:700; font-size:.82rem; cursor:pointer; font-family:inherit; margin-top:.4rem; }

.btn-primary { background:linear-gradient(135deg,var(--petrol-900),var(--petrol-700)); color:#fff; border:0; padding:.85rem 1.8rem; border-radius:10px; font-weight:700; cursor:pointer; font-family:inherit; font-size:.92rem; }
.sucesso-banner { background:linear-gradient(135deg,#ecfdf5,#d1fae5); border:1.5px solid #34d399; color:#065f46; padding:.75rem 1.1rem; border-radius:10px; margin-bottom:1rem; }
.erro-banner { background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; padding:.75rem 1rem; border-radius:10px; margin-bottom:1rem; }
</style>
</head>
<body>

<div class="toolbar">
    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
        <a href="./?token=<?= htmlspecialchars($token) ?>" class="btn-back">← Página principal</a>
        <h1>📓 Daily Planner</h1>
    </div>
    <span style="font-size:.78rem;opacity:.85;">Olá, <?= htmlspecialchars($primeiroNome) ?> ✨</span>
</div>

<div class="container">

<?php if (!empty($_GET['ok'])): ?>
    <div class="sucesso-banner">✓ Daily salvo! Pode fechar a página ou seguir editando.</div>
<?php endif; ?>
<?php if (!empty($msg) && $msg['tipo'] === 'erro'): ?>
    <div class="erro-banner">⚠ <?= htmlspecialchars($msg['txt']) ?></div>
<?php endif; ?>

<!-- Mini-calendário (últimos 7 dias) -->
<div class="semana-mini">
    <?php
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime($hoje . ' -' . $i . ' day'));
        $dDt = new DateTime($d);
        $diaSem = array('Dom','Seg','Ter','Qua','Qui','Sex','Sáb')[(int)$dDt->format('w')];
        $cls = ($d === $dataFiltro ? 'atual' : '') . ' ' . (isset($ultimos[$d]) ? 'preenchido' : '');
        $emj = $ultimos[$d]['humor'] ?? '';
        echo '<a href="?token=' . urlencode($token) . '&data=' . $d . '" class="' . $cls . '" title="' . $diaSem . ' ' . $dDt->format('d/m') . '">'
           . '<span class="num">' . $dDt->format('d') . '</span>'
           . '<span style="font-size:.66rem;color:#6b7280;">' . $diaSem . '</span>'
           . ($emj ? '<span class="emj">' . htmlspecialchars($emj) . '</span>' : '')
           . '</a>';
    }
    ?>
</div>

<!-- Navegação de dia -->
<div class="dia-nav">
    <a href="?token=<?= urlencode($token) ?>&data=<?= $dataAnt ?>">◀ Ontem</a>
    <span class="central"><?= htmlspecialchars(data_br($dataFiltro)) ?></span>
    <a href="?token=<?= urlencode($token) ?>&data=<?= $dataProx ?>">Amanhã ▶</a>
</div>
<?php if ($dataFiltro !== $hoje): ?>
<div style="text-align:center;margin-bottom:1rem;">
    <a href="?token=<?= urlencode($token) ?>" style="color:var(--cobre);font-size:.82rem;font-weight:700;text-decoration:none;">↺ Voltar pra hoje</a>
</div>
<?php endif; ?>

<form method="POST" id="dailyForm">
    <input type="hidden" name="salvar_daily" value="1">
    <input type="hidden" name="data" value="<?= htmlspecialchars($dataFiltro) ?>">
    <input type="hidden" name="tarefas_json" id="tarefasJson" value="">

    <!-- Humor -->
    <div class="card-block">
        <h2>💭 Como você está hoje?</h2>
        <p style="color:#6b7280;font-size:.85rem;margin-bottom:.8rem;">Marca rapidinho como tá o seu dia. É anônimo pra ninguém da equipe — só pra você (e pra Amanda/Luiz se quiserem ver tendência).</p>
        <div class="humor-grid">
            <?php foreach ($humorOpcoes as $emj => $lbl): ?>
                <label>
                    <input type="radio" name="humor" value="<?= htmlspecialchars($emj) ?>" <?= $humor === $emj ? 'checked' : '' ?>>
                    <span class="pill">
                        <span class="emoji"><?= $emj ?></span>
                        <span class="lbl"><?= $lbl ?></span>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Foco principal -->
    <div class="card-block">
        <h2>🎯 Qual o foco principal de hoje?</h2>
        <p style="color:#6b7280;font-size:.85rem;margin-bottom:.6rem;">A 1 coisa mais importante. Se conseguir só essa, o dia já valeu.</p>
        <input type="text" name="foco_principal" maxlength="300" value="<?= htmlspecialchars($foco) ?>" placeholder="Ex: Terminar minuta da audiência da próxima semana">
    </div>

    <!-- Tarefas -->
    <div class="card-block">
        <h2>✅ Tarefas do dia</h2>
        <p style="color:#6b7280;font-size:.85rem;margin-bottom:.6rem;">Lista corrida. Marque o que for fazendo. Não precisa caber tudo, só o que conta hoje.</p>
        <div id="tarefasBox"></div>
        <button type="button" class="btn-add" onclick="addTarefa()">+ Adicionar tarefa</button>
    </div>

    <!-- Notas -->
    <div class="card-block">
        <h2>📝 Notas / Lembretes</h2>
        <p style="color:#6b7280;font-size:.85rem;margin-bottom:.6rem;">Anotações soltas, lembretes, links, número de processo, decisões importantes...</p>
        <textarea name="notas" rows="5" placeholder="Anote o que vier à cabeça."><?= htmlspecialchars($notas) ?></textarea>
    </div>

    <!-- Aprendizados -->
    <div class="card-block">
        <h2>💡 Aprendi hoje que...</h2>
        <p style="color:#6b7280;font-size:.85rem;margin-bottom:.6rem;">Reflexão curta no fim do dia. Pode ser técnico, comportamental, sobre você, sobre o caso. Não precisa ser todo dia.</p>
        <textarea name="aprendizados" rows="3" placeholder="Ex: Aprendi que pedir ajuda mais cedo economiza horas no fim."><?= htmlspecialchars($aprend) ?></textarea>
    </div>

    <div style="text-align:center;margin-top:1.5rem;">
        <button type="submit" class="btn-primary">💾 Salvar Daily de <?= htmlspecialchars(date('d/m', strtotime($dataFiltro))) ?></button>
    </div>
</form>

</div>

<script>
var TAREFAS = <?= json_encode($tarefas, JSON_UNESCAPED_UNICODE) ?>;
if (!Array.isArray(TAREFAS)) TAREFAS = [];

function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
}

function renderTarefas() {
    var box = document.getElementById('tarefasBox');
    if (!TAREFAS.length) {
        box.innerHTML = '<p style="color:#9ca3af;font-size:.85rem;margin:.5rem 0;">Nenhuma tarefa ainda. Adicione abaixo ↓</p>';
        sincTarefas();
        return;
    }
    var html = '';
    TAREFAS.forEach(function(t, i) {
        html += '<div class="tarefa-row ' + (t.feito ? 'feito' : '') + '">'
              + '<input type="checkbox" ' + (t.feito ? 'checked' : '') + ' onchange="toggleTarefa(' + i + ', this.checked)">'
              + '<input type="text" value="' + escapeHtml(t.texto) + '" oninput="setTexto(' + i + ', this.value)" placeholder="Descreva a tarefa">'
              + '<button type="button" onclick="rmTarefa(' + i + ')" title="Remover">×</button>'
              + '</div>';
    });
    box.innerHTML = html;
    sincTarefas();
}
function addTarefa() {
    TAREFAS.push({ texto: '', feito: false });
    renderTarefas();
    var inputs = document.querySelectorAll('#tarefasBox input[type="text"]');
    if (inputs.length) inputs[inputs.length - 1].focus();
}
function rmTarefa(i) {
    TAREFAS.splice(i, 1);
    renderTarefas();
}
function toggleTarefa(i, v) {
    TAREFAS[i].feito = !!v;
    var row = document.querySelectorAll('.tarefa-row')[i];
    if (row) row.classList.toggle('feito', !!v);
    sincTarefas();
}
function setTexto(i, v) {
    TAREFAS[i].texto = v;
    sincTarefas();
}
function sincTarefas() {
    document.getElementById('tarefasJson').value = JSON.stringify(TAREFAS);
}

renderTarefas();
</script>

</body>
</html>
