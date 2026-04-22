<?php
/**
 * ============================================================
 * claudin_dashboard.php — Painel de monitoramento do Claudin
 * ============================================================
 *
 * PROPÓSITO:
 *   Lista as últimas execuções do cron djen_monitor.php, mostra
 *   contadores (parsed/imported/duplicated/pending/errors) e
 *   status colorido. Permite ver o trecho do log de cada run e
 *   disparar execução manual "Rodar agora".
 *
 * ACESSO:
 *   Apenas gestao+ (admin, gestao). Mesma autenticação dos
 *   outros painéis admin do Hub.
 *
 * EXECUÇÃO MANUAL:
 *   Quando clica "Rodar agora", o PHP chama shell_exec passando
 *   --horario=manual --data=YYYY-MM-DD --token=<CLAUDIN_MANUAL_TOKEN>.
 *   O processo roda em background (> /dev/null 2>&1 &) pra não
 *   bloquear a resposta. Após 30s, a página faz polling pra
 *   atualizar a tabela e mostrar a run nova.
 *
 * DEPENDÊNCIAS:
 *   - core/middleware.php (require_login, has_min_role)
 *   - Tabela claudin_runs (Passo 1)
 *   - cron/claudin_config.php (Passo 2)
 *   - cron/djen_monitor.php (Passo 4)
 *
 * ============================================================
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('gestao')) { flash_set('error', 'Sem permissão.'); redirect(url('modules/dashboard/')); }

$pdo = db();

// Carrega config (só pra pegar CLAUDIN_MANUAL_TOKEN e LOG_PATH)
define('CLAUDIN_INCLUDED', true);
require_once APP_ROOT . '/cron/claudin_config.php';

// ============================================================
// AJAX — listar runs (polling)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'listar') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $rows = $pdo->query("SELECT * FROM claudin_runs ORDER BY executado_em DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(array('ok' => true, 'rows' => $rows));
    } catch (Exception $e) {
        echo json_encode(array('ok' => false, 'erro' => $e->getMessage()));
    }
    exit;
}

// ============================================================
// AJAX — ler log de uma run
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'ler_log') {
    header('Content-Type: application/json; charset=utf-8');
    $runId = (int)($_GET['run_id'] ?? 0);
    if (!$runId) { echo json_encode(array('ok' => false, 'erro' => 'run_id inválido')); exit; }
    try {
        $stmt = $pdo->prepare("SELECT executado_em FROM claudin_runs WHERE id = ?");
        $stmt->execute(array($runId));
        $run = $stmt->fetch();
        if (!$run) { echo json_encode(array('ok' => false, 'erro' => 'run não encontrada')); exit; }

        $linhas = array();
        if (file_exists(LOG_PATH)) {
            $conteudo = @file_get_contents(LOG_PATH);
            $linhasAll = explode("\n", $conteudo);
            // Filtra ± próximas ao timestamp da run (± 5 min)
            $tsRun = strtotime($run['executado_em']);
            $janela = 300; // segundos
            $filtradas = array();
            foreach ($linhasAll as $l) {
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $l, $m)) {
                    $tsL = strtotime($m[1]);
                    if ($tsL && abs($tsL - $tsRun) <= $janela) $filtradas[] = $l;
                }
            }
            // Se não achou nada na janela, pega as últimas 80 linhas do log
            $linhas = !empty($filtradas) ? $filtradas : array_slice($linhasAll, -80);
            $linhas = array_slice($linhas, -80); // cap final
        }
        echo json_encode(array('ok' => true, 'linhas' => $linhas));
    } catch (Exception $e) {
        echo json_encode(array('ok' => false, 'erro' => $e->getMessage()));
    }
    exit;
}

// ============================================================
// AJAX — disparar execução manual (shell_exec em background)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rodar_agora') {
    header('Content-Type: application/json; charset=utf-8');
    if (!validate_csrf()) { echo json_encode(array('ok' => false, 'erro' => 'CSRF inválido', 'csrf' => generate_csrf_token())); exit; }

    $dataManual = $_POST['data_manual'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataManual)) {
        echo json_encode(array('ok' => false, 'erro' => 'Data inválida', 'csrf' => generate_csrf_token()));
        exit;
    }

    $script = APP_ROOT . '/cron/djen_monitor.php';
    if (!file_exists($script)) {
        echo json_encode(array('ok' => false, 'erro' => 'Script não encontrado', 'csrf' => generate_csrf_token()));
        exit;
    }

    // Descobre binário PHP disponível
    $phpBin = '/usr/bin/php';
    if (!file_exists($phpBin)) {
        // Fallback: procura em caminhos comuns
        foreach (array('/usr/local/bin/php', '/opt/alt/php74/usr/bin/php', '/usr/bin/php74') as $p) {
            if (file_exists($p)) { $phpBin = $p; break; }
        }
    }

    $cmd = escapeshellcmd($phpBin) . ' '
         . escapeshellarg($script)
         . ' --horario=manual'
         . ' --data=' . escapeshellarg($dataManual)
         . ' --token=' . escapeshellarg(CLAUDIN_MANUAL_TOKEN)
         . ' > /dev/null 2>&1 &';

    @shell_exec($cmd);

    echo json_encode(array(
        'ok'   => true,
        'msg'  => 'Execução disparada em background. Aguarde ~30s e atualize.',
        'cmd_debug' => substr($cmd, 0, 200),
        'csrf' => generate_csrf_token(),
    ));
    exit;
}

// ============================================================
// Carrega runs iniciais
// ============================================================
$runs = array();
try {
    $runs = $pdo->query("SELECT * FROM claudin_runs ORDER BY executado_em DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    flash_set('error', 'Erro ao carregar runs: ' . $e->getMessage());
}

// Estatísticas rápidas (últimos 30 dias)
$stats = array('total' => 0, 'ok' => 0, 'parcial' => 0, 'falha' => 0, 'parsed_total' => 0, 'imported_total' => 0, 'pending_total' => 0);
try {
    $row = $pdo->query(
        "SELECT COUNT(*) t,
                SUM(status='ok') ok,
                SUM(status='parcial') parcial,
                SUM(status='falha') falha,
                COALESCE(SUM(total_parsed),0) p,
                COALESCE(SUM(imported),0) i,
                COALESCE(SUM(pending),0) pend
         FROM claudin_runs
         WHERE executado_em >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    )->fetch();
    if ($row) {
        $stats['total'] = (int)$row['t'];
        $stats['ok'] = (int)$row['ok'];
        $stats['parcial'] = (int)$row['parcial'];
        $stats['falha'] = (int)$row['falha'];
        $stats['parsed_total'] = (int)$row['p'];
        $stats['imported_total'] = (int)$row['i'];
        $stats['pending_total'] = (int)$row['pend'];
    }
} catch (Exception $e) {}

$csrfToken = generate_csrf_token();
$pageTitle = 'Claudin — Monitor DJEN';
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.cl-wrap { max-width:1300px; margin:0 auto; }
.cl-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:.8rem; }
.cl-header h2 { font-size:1.35rem; color:var(--petrol-900); margin:0; }
.cl-header p { font-size:.8rem; color:var(--text-muted); margin:.2rem 0 0; }

.cl-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:.6rem; margin-bottom:1rem; }
.cl-stat { background:var(--bg-card); border:1px solid var(--border); border-radius:10px; padding:.8rem 1rem; }
.cl-stat-label { font-size:.65rem; text-transform:uppercase; color:var(--text-muted); letter-spacing:.5px; font-weight:700; }
.cl-stat-val { font-size:1.5rem; font-weight:800; color:var(--petrol-900); margin-top:2px; }

.cl-table { width:100%; border-collapse:collapse; font-size:.8rem; background:var(--bg-card); border-radius:10px; overflow:hidden; }
.cl-table th { background:var(--petrol-900); color:#fff; padding:.55rem .6rem; text-align:center; font-size:.68rem; text-transform:uppercase; letter-spacing:.5px; font-weight:700; }
.cl-table td { padding:.5rem .6rem; border-bottom:1px solid var(--border); text-align:center; vertical-align:middle; }
.cl-table tr:hover { background:rgba(215,171,144,.05); }
.cl-table td.left { text-align:left; }

.cl-badge { display:inline-block; padding:2px 10px; border-radius:12px; font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.3px; }
.cl-badge.ok      { background:#dcfce7; color:#15803d; }
.cl-badge.parcial { background:#fef3c7; color:#b45309; }
.cl-badge.falha   { background:#fee2e2; color:#b91c1c; }
.cl-badge.horario { background:#eef2ff; color:#4338ca; }

.cl-btn-log { background:none; border:1px solid var(--border); padding:3px 10px; border-radius:6px; font-size:.7rem; cursor:pointer; color:var(--petrol-900); }
.cl-btn-log:hover { background:var(--bg-secondary); border-color:#052228; }

.cl-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:9999; justify-content:center; align-items:center; padding:20px; }
.cl-modal.aberto { display:flex; }
.cl-modal-box { background:#fff; border-radius:12px; max-width:900px; width:100%; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,.3); }
.cl-modal-head { padding:1rem 1.2rem; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; }
.cl-modal-head h3 { margin:0; font-size:1rem; color:var(--petrol-900); }
.cl-modal-body { padding:1rem 1.2rem; overflow:auto; flex:1; }
.cl-modal-body pre { background:#0f172a; color:#cbd5e1; padding:12px; border-radius:8px; font-size:.72rem; white-space:pre-wrap; word-wrap:break-word; max-height:60vh; overflow-y:auto; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; }
.cl-modal-close { background:none; border:none; font-size:1.4rem; cursor:pointer; color:#64748b; }

.cl-spinner { display:inline-block; width:14px; height:14px; border:2px solid #e5e7eb; border-top-color:#B87333; border-radius:50%; animation:clSpin .8s linear infinite; vertical-align:middle; margin-right:6px; }
@keyframes clSpin { to { transform:rotate(360deg); } }
.cl-running { color:#b45309; font-size:.75rem; font-weight:600; margin-left:1rem; }
</style>

<div class="cl-wrap">
    <div class="cl-header">
        <div>
            <h2>🤖 Claudin — Monitor DJEN</h2>
            <p>Histórico das execuções do robô que puxa publicações do DJEN duas vezes por dia.</p>
        </div>
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
            <a href="<?= htmlspecialchars(module_url('admin', 'djen_importar.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline btn-sm">📬 Ver pendências</a>
            <button onclick="clAtualizar()" class="btn btn-outline btn-sm">🔄 Atualizar</button>
            <button onclick="clAbrirModalRodar()" class="btn btn-primary btn-sm" style="background:#B87333;">▶️ Rodar agora (manual)</button>
            <span id="clStatusExec" class="cl-running" style="display:none;"><span class="cl-spinner"></span>Execução em andamento...</span>
        </div>
    </div>

    <div class="cl-stats">
        <div class="cl-stat"><div class="cl-stat-label">Últimos 30 dias</div><div class="cl-stat-val"><?= (int)$stats['total'] ?></div></div>
        <div class="cl-stat"><div class="cl-stat-label">OKs</div><div class="cl-stat-val" style="color:#15803d;"><?= (int)$stats['ok'] ?></div></div>
        <div class="cl-stat"><div class="cl-stat-label">Parciais</div><div class="cl-stat-val" style="color:#b45309;"><?= (int)$stats['parcial'] ?></div></div>
        <div class="cl-stat"><div class="cl-stat-label">Falhas</div><div class="cl-stat-val" style="color:#b91c1c;"><?= (int)$stats['falha'] ?></div></div>
        <div class="cl-stat"><div class="cl-stat-label">Publicações processadas</div><div class="cl-stat-val"><?= (int)$stats['parsed_total'] ?></div></div>
        <div class="cl-stat"><div class="cl-stat-label">Pendentes acumulados</div><div class="cl-stat-val" style="color:#4338ca;"><?= (int)$stats['pending_total'] ?></div></div>
    </div>

    <div style="overflow-x:auto;">
        <table class="cl-table" id="clTable">
            <thead>
                <tr>
                    <th>Quando rodou</th>
                    <th>Data-alvo</th>
                    <th>Horário</th>
                    <th>Parsed</th>
                    <th>Imported</th>
                    <th>Duplicated</th>
                    <th>Pending</th>
                    <th>Errors</th>
                    <th>Tempo (s)</th>
                    <th>Status</th>
                    <th>Log</th>
                </tr>
            </thead>
            <tbody id="clTbody">
                <?php if (empty($runs)): ?>
                    <tr><td colspan="11" style="padding:2rem;color:#94a3b8;">Nenhuma execução registrada ainda. Clique em "Rodar agora" pra disparar a primeira.</td></tr>
                <?php else: foreach ($runs as $r): ?>
                    <tr data-id="<?= (int)$r['id'] ?>">
                        <td class="left"><?= htmlspecialchars(date('d/m/Y H:i:s', strtotime($r['executado_em'])), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(date('d/m/Y', strtotime($r['data_alvo'])), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="cl-badge horario"><?= htmlspecialchars($r['horario'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= (int)$r['total_parsed'] ?></td>
                        <td style="color:#15803d;font-weight:600;"><?= (int)$r['imported'] ?></td>
                        <td><?= (int)$r['duplicated'] ?></td>
                        <td style="color:<?= $r['pending']>0 ? '#b45309;font-weight:700' : '#64748b' ?>;"><?= (int)$r['pending'] ?></td>
                        <td style="color:<?= $r['errors']>0 ? '#b91c1c;font-weight:700' : '#64748b' ?>;"><?= (int)$r['errors'] ?></td>
                        <td><?= htmlspecialchars(number_format((float)$r['tempo_execucao_segundos'], 1, ',', ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="cl-badge <?= htmlspecialchars($r['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(strtoupper($r['status']), ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><button class="cl-btn-log" onclick="clVerLog(<?= (int)$r['id'] ?>)">📄</button></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Rodar agora -->
<div class="cl-modal" id="clModalRodar">
    <div class="cl-modal-box" style="max-width:450px;">
        <div class="cl-modal-head">
            <h3>▶️ Rodar Claudin manualmente</h3>
            <button class="cl-modal-close" onclick="clFecharModal('clModalRodar')">✕</button>
        </div>
        <div class="cl-modal-body">
            <p style="font-size:.82rem;margin-bottom:.8rem;">Qual data deseja consultar no DJEN? O robô vai buscar todas as publicações disponibilizadas nessa data para as OABs do escritório.</p>
            <label style="font-size:.78rem;font-weight:600;display:block;margin-bottom:4px;">Data-alvo:</label>
            <input type="date" id="clDataManual" value="<?= date('Y-m-d') ?>" style="padding:8px;border:1.5px solid #e5e7eb;border-radius:6px;font-size:.9rem;width:100%;">
            <div style="margin-top:1rem;display:flex;gap:.5rem;justify-content:flex-end;">
                <button class="btn btn-outline btn-sm" onclick="clFecharModal('clModalRodar')">Cancelar</button>
                <button id="clBtnDisparar" class="btn btn-primary btn-sm" style="background:#B87333;" onclick="clDisparar()">Disparar</button>
            </div>
            <div id="clMsgRodar" style="margin-top:.8rem;font-size:.78rem;"></div>
        </div>
    </div>
</div>

<!-- Modal: Ver log -->
<div class="cl-modal" id="clModalLog">
    <div class="cl-modal-box">
        <div class="cl-modal-head">
            <h3>📄 Log da execução <span id="clLogRunId" style="color:#64748b;font-weight:400;"></span></h3>
            <button class="cl-modal-close" onclick="clFecharModal('clModalLog')">✕</button>
        </div>
        <div class="cl-modal-body">
            <pre id="clLogContent">Carregando...</pre>
        </div>
    </div>
</div>

<script>
var CL_CSRF = <?= json_encode($csrfToken) ?>;
var CL_POLLING_TIMER = null;

function clAbrirModalRodar() {
    document.getElementById('clMsgRodar').textContent = '';
    document.getElementById('clBtnDisparar').disabled = false;
    document.getElementById('clModalRodar').classList.add('aberto');
}

function clFecharModal(id) {
    document.getElementById(id).classList.remove('aberto');
}

function clDisparar() {
    var data = document.getElementById('clDataManual').value;
    if (!data) { alert('Escolha uma data.'); return; }

    var btn = document.getElementById('clBtnDisparar');
    var msg = document.getElementById('clMsgRodar');
    btn.disabled = true;
    msg.innerHTML = '<span class="cl-spinner"></span> Disparando...';

    var fd = new FormData();
    fd.append('action', 'rodar_agora');
    fd.append('csrf_token', CL_CSRF);
    fd.append('data_manual', data);

    fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (j.csrf) CL_CSRF = j.csrf;
            if (j.ok) {
                msg.style.color = '#15803d';
                msg.textContent = '✅ ' + j.msg + ' Fechando em 3s...';
                document.getElementById('clStatusExec').style.display = 'inline-block';
                clIniciarPolling();
                setTimeout(function() { clFecharModal('clModalRodar'); }, 3000);
            } else {
                msg.style.color = '#b91c1c';
                msg.textContent = '❌ ' + (j.erro || 'Erro desconhecido');
                btn.disabled = false;
            }
        })
        .catch(function(e) {
            msg.style.color = '#b91c1c';
            msg.textContent = '❌ Erro de rede: ' + e.message;
            btn.disabled = false;
        });
}

function clIniciarPolling() {
    // Checa a cada 10s por até 3 minutos
    var count = 0;
    if (CL_POLLING_TIMER) clearInterval(CL_POLLING_TIMER);
    CL_POLLING_TIMER = setInterval(function() {
        count++;
        clAtualizar();
        if (count >= 18) { // 18 × 10s = 3min
            clearInterval(CL_POLLING_TIMER);
            CL_POLLING_TIMER = null;
            document.getElementById('clStatusExec').style.display = 'none';
        }
    }, 10000);
}

function clAtualizar() {
    fetch(window.location.pathname + '?action=listar', { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok || !Array.isArray(j.rows)) return;
            clRenderizar(j.rows);
        })
        .catch(function(e) { /* silent */ });
}

function clEsc(s) {
    return String(s).replace(/[&<>"']/g, function(c) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
}

function clFmtDt(s) {
    var d = new Date(s.replace(' ','T'));
    var f = function(n){ return (n<10?'0':'')+n; };
    return f(d.getDate())+'/'+f(d.getMonth()+1)+'/'+d.getFullYear()+' '+f(d.getHours())+':'+f(d.getMinutes())+':'+f(d.getSeconds());
}
function clFmtD(s) {
    var d = new Date(s+'T00:00');
    var f = function(n){ return (n<10?'0':'')+n; };
    return f(d.getDate())+'/'+f(d.getMonth()+1)+'/'+d.getFullYear();
}

function clRenderizar(rows) {
    var tb = document.getElementById('clTbody');
    if (!rows.length) {
        tb.innerHTML = '<tr><td colspan="11" style="padding:2rem;color:#94a3b8;">Nenhuma execução ainda.</td></tr>';
        return;
    }
    var html = '';
    rows.forEach(function(r) {
        var corPend = parseInt(r.pending) > 0 ? '#b45309;font-weight:700' : '#64748b';
        var corErr = parseInt(r.errors) > 0 ? '#b91c1c;font-weight:700' : '#64748b';
        html += '<tr data-id="' + r.id + '">'
             + '<td class="left">' + clEsc(clFmtDt(r.executado_em)) + '</td>'
             + '<td>' + clEsc(clFmtD(r.data_alvo)) + '</td>'
             + '<td><span class="cl-badge horario">' + clEsc(r.horario) + '</span></td>'
             + '<td>' + clEsc(r.total_parsed) + '</td>'
             + '<td style="color:#15803d;font-weight:600;">' + clEsc(r.imported) + '</td>'
             + '<td>' + clEsc(r.duplicated) + '</td>'
             + '<td style="color:' + corPend + ';">' + clEsc(r.pending) + '</td>'
             + '<td style="color:' + corErr + ';">' + clEsc(r.errors) + '</td>'
             + '<td>' + clEsc(parseFloat(r.tempo_execucao_segundos).toFixed(1)) + '</td>'
             + '<td><span class="cl-badge ' + clEsc(r.status) + '">' + clEsc(r.status.toUpperCase()) + '</span></td>'
             + '<td><button class="cl-btn-log" onclick="clVerLog(' + r.id + ')">📄</button></td>'
             + '</tr>';
    });
    tb.innerHTML = html;
}

function clVerLog(runId) {
    document.getElementById('clLogRunId').textContent = '#' + runId;
    document.getElementById('clLogContent').textContent = 'Carregando...';
    document.getElementById('clModalLog').classList.add('aberto');

    fetch(window.location.pathname + '?action=ler_log&run_id=' + runId, { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok) { document.getElementById('clLogContent').textContent = 'Erro: ' + (j.erro || ''); return; }
            if (!j.linhas || !j.linhas.length) {
                document.getElementById('clLogContent').textContent = '(log vazio ou arquivo ainda não criado)';
                return;
            }
            document.getElementById('clLogContent').textContent = j.linhas.join('\n');
        })
        .catch(function(e) {
            document.getElementById('clLogContent').textContent = 'Erro de rede: ' + e.message;
        });
}

// Fecha modal clicando fora
document.querySelectorAll('.cl-modal').forEach(function(m) {
    m.addEventListener('click', function(e) { if (e.target === m) m.classList.remove('aberto'); });
});
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
