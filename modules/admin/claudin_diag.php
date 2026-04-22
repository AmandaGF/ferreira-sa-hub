<?php
/**
 * ============================================================
 * claudin_diag.php — Diagnóstico do Claudin
 * ============================================================
 * Executa bateria de testes e mostra o que está funcionando ou
 * quebrando. Roda o djen_monitor.php em FOREGROUND (sem &) e
 * captura toda a saída — diferente do dashboard que roda em
 * background e descarta stdout.
 * ============================================================
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('gestao')) { flash_set('error', 'Sem permissão.'); redirect(url('modules/dashboard/')); }

define('CLAUDIN_INCLUDED', true);
require_once APP_ROOT . '/cron/claudin_config.php';

$pdo = db();
$csrfToken = generate_csrf_token();

// ============================================================
// AJAX: rodar djen_monitor INLINE (sem shell_exec) e devolver
// o pedaço novo do log como "saída"
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rodar_fg') {
    header('Content-Type: application/json; charset=utf-8');
    if (!validate_csrf()) { echo json_encode(array('ok' => false, 'erro' => 'CSRF inválido', 'csrf' => generate_csrf_token())); exit; }

    @set_time_limit(300);
    @ini_set('max_execution_time', '300');
    ignore_user_abort(true);

    $dataManual = $_POST['data_manual'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataManual)) {
        echo json_encode(array('ok' => false, 'erro' => 'Data inválida', 'csrf' => generate_csrf_token()));
        exit;
    }

    // Marca offset do arquivo de log ANTES de executar
    $offsetAntes = file_exists(LOG_PATH) ? filesize(LOG_PATH) : 0;

    // Roda o monitor INLINE (sem shell_exec)
    define('CLAUDIN_NO_AUTORUN', true);
    $tIni = microtime(true);
    $erroExec = null;
    try {
        require_once APP_ROOT . '/cron/djen_monitor.php';
        claudin_executar('manual', $dataManual);
    } catch (Throwable $e) {
        $erroExec = $e->getMessage() . "\n" . $e->getTraceAsString();
    }
    $tempo = round(microtime(true) - $tIni, 2);

    // Lê o pedaço do log que foi adicionado durante a execução
    $output = '';
    if (file_exists(LOG_PATH)) {
        $fh = @fopen(LOG_PATH, 'r');
        if ($fh) {
            fseek($fh, $offsetAntes);
            $output = stream_get_contents($fh);
            fclose($fh);
        }
    }

    if ($erroExec) $output .= "\n=== EXCEPTION NÃO TRATADA ===\n" . $erroExec;

    echo json_encode(array(
        'ok'     => true,
        'modo'   => 'inline (sem shell_exec — rodou no próprio processo PHP)',
        'output' => $output ?: '(sem saída — verifique se claudin.log foi criado)',
        'tempo'  => $tempo,
        'csrf'   => generate_csrf_token(),
    ));
    exit;
}

// ============================================================
// Coleta diagnósticos
// ============================================================
$diag = array();

// 1. PHP CLI disponível?
$candidatos = array(
    '/usr/bin/php',
    '/usr/local/bin/php',
    '/opt/alt/php74/usr/bin/php',
    '/opt/cpanel/ea-php74/root/usr/bin/php',
    '/opt/cpanel/ea-php80/root/usr/bin/php',
    '/opt/cpanel/ea-php81/root/usr/bin/php',
    '/opt/cpanel/ea-php82/root/usr/bin/php',
    '/opt/cpanel/ea-php83/root/usr/bin/php',
    '/usr/bin/php74', '/usr/bin/php80', '/usr/bin/php81', '/usr/bin/php82',
);
$phpFound = array();
foreach ($candidatos as $p) {
    if (file_exists($p)) {
        $phpFound[] = array('path' => $p, 'exec' => is_executable($p));
    }
}
$diag['php_cli'] = $phpFound;

// 2. shell_exec habilitado?
$disabled = explode(',', (string)ini_get('disable_functions'));
$disabled = array_map('trim', $disabled);
$diag['shell_exec_disabled'] = in_array('shell_exec', $disabled, true);
$diag['exec_disabled']       = in_array('exec', $disabled, true);
$diag['proc_open_disabled']  = in_array('proc_open', $disabled, true);

// 3. Tabela claudin_runs existe?
$diag['claudin_runs'] = false;
try {
    $pdo->query("SELECT 1 FROM claudin_runs LIMIT 1");
    $diag['claudin_runs'] = true;
} catch (Exception $e) {
    $diag['claudin_runs_erro'] = $e->getMessage();
}

// 4. ANTHROPIC_API_KEY configurada?
$diag['anthropic_key'] = defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY && ANTHROPIC_API_KEY !== 'SUA_CHAVE_AQUI';
$diag['anthropic_key_prefix'] = defined('ANTHROPIC_API_KEY') ? substr((string)ANTHROPIC_API_KEY, 0, 15) . '...' : '(não definida)';

// 5. Pasta cron/logs/ existe e é gravável?
$logDir = dirname(LOG_PATH);
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
$diag['log_dir']        = $logDir;
$diag['log_dir_existe'] = is_dir($logDir);
$diag['log_dir_gravavel'] = is_writable($logDir);
$diag['log_path']       = LOG_PATH;
$diag['log_arquivo_existe'] = file_exists(LOG_PATH);
$diag['log_arquivo_tamanho'] = file_exists(LOG_PATH) ? filesize(LOG_PATH) : 0;

// 6. Script djen_monitor.php existe?
$scriptPath = APP_ROOT . '/cron/djen_monitor.php';
$diag['script_existe'] = file_exists($scriptPath);
$diag['script_path']   = $scriptPath;
$diag['script_tamanho'] = file_exists($scriptPath) ? filesize($scriptPath) : 0;

// 7. APP_ROOT correto
$diag['app_root'] = APP_ROOT;

// 8. Última tentativa de execução (se houver)
$ultimaRun = null;
try {
    $ultimaRun = $pdo->query("SELECT * FROM claudin_runs ORDER BY id DESC LIMIT 1")->fetch();
} catch (Exception $e) {}
$diag['ultima_run'] = $ultimaRun;

// Últimas linhas do log (se existir)
$logTail = '';
if (file_exists(LOG_PATH)) {
    $conteudo = @file_get_contents(LOG_PATH);
    $linhas = explode("\n", $conteudo);
    $logTail = implode("\n", array_slice($linhas, -30));
}

// Log do teste do cron (se foi agendado)
$testeCronLog = APP_ROOT . '/cron/logs/teste_cron.log';
$testeCronExiste = file_exists($testeCronLog);
$testeCronConteudo = '';
$testeCronModificado = '';
if ($testeCronExiste) {
    $testeCronConteudo = trim(@file_get_contents($testeCronLog));
    $testeCronModificado = date('d/m/Y H:i:s', filemtime($testeCronLog));
}

$pageTitle = 'Claudin — Diagnóstico';
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.diag-wrap { max-width:1000px; margin:0 auto; }
.diag-card { background:var(--bg-card); border:1px solid var(--border); border-radius:10px; padding:1rem 1.2rem; margin-bottom:1rem; }
.diag-card h3 { font-size:.95rem; color:var(--petrol-900); margin:0 0 .6rem; }
.diag-ok { color:#15803d; font-weight:700; }
.diag-bad { color:#b91c1c; font-weight:700; }
.diag-warn { color:#b45309; font-weight:700; }
.diag-row { display:flex; justify-content:space-between; padding:4px 0; font-size:.82rem; border-bottom:1px dashed #e5e7eb; }
.diag-row:last-child { border:none; }
.diag-row .key { color:#64748b; }
.diag-row .val { font-family:ui-monospace,monospace; font-size:.78rem; }
pre.diag-log { background:#0f172a; color:#cbd5e1; padding:10px; border-radius:8px; font-size:.72rem; max-height:400px; overflow:auto; white-space:pre-wrap; }
</style>

<div class="diag-wrap">
    <h2 style="color:var(--petrol-900);">🔍 Diagnóstico Claudin</h2>
    <p style="font-size:.82rem;color:var(--text-muted);">Testes de infraestrutura do robô. Use isto pra descobrir o que está bloqueando a execução.</p>

    <!-- 1. PHP CLI -->
    <div class="diag-card">
        <h3>1. Binário PHP CLI disponível</h3>
        <?php if (empty($phpFound)): ?>
            <p class="diag-bad">❌ Nenhum PHP CLI encontrado nos caminhos comuns. Abra ticket no TurboCloud perguntando "caminho absoluto do binário PHP CLI".</p>
        <?php else: ?>
            <?php foreach ($phpFound as $p): ?>
                <div class="diag-row">
                    <span class="key"><?= htmlspecialchars($p['path'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="val <?= $p['exec'] ? 'diag-ok' : 'diag-warn' ?>"><?= $p['exec'] ? '✅ executável' : '⚠️ existe mas não executável' ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- 2. Funções -->
    <div class="diag-card">
        <h3>2. Funções de sistema</h3>
        <div class="diag-row"><span class="key">shell_exec</span><span class="val <?= $diag['shell_exec_disabled'] ? 'diag-bad' : 'diag-ok' ?>"><?= $diag['shell_exec_disabled'] ? '❌ DESABILITADA' : '✅ habilitada' ?></span></div>
        <div class="diag-row"><span class="key">exec</span><span class="val <?= $diag['exec_disabled'] ? 'diag-bad' : 'diag-ok' ?>"><?= $diag['exec_disabled'] ? '❌ desabilitada' : '✅ habilitada' ?></span></div>
        <div class="diag-row"><span class="key">proc_open</span><span class="val <?= $diag['proc_open_disabled'] ? 'diag-warn' : 'diag-ok' ?>"><?= $diag['proc_open_disabled'] ? '⚠️ desabilitada' : '✅ habilitada' ?></span></div>
    </div>

    <!-- 3. Banco -->
    <div class="diag-card">
        <h3>3. Banco de dados</h3>
        <div class="diag-row">
            <span class="key">Tabela claudin_runs</span>
            <span class="val <?= $diag['claudin_runs'] ? 'diag-ok' : 'diag-bad' ?>">
                <?= $diag['claudin_runs'] ? '✅ existe' : '❌ NÃO EXISTE — rode o CREATE TABLE' ?>
            </span>
        </div>
        <?php if (!$diag['claudin_runs'] && !empty($diag['claudin_runs_erro'])): ?>
            <p class="diag-bad" style="font-size:.76rem;"><?= htmlspecialchars($diag['claudin_runs_erro'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </div>

    <!-- 4. Chave Anthropic -->
    <div class="diag-card">
        <h3>4. Chave Anthropic</h3>
        <div class="diag-row">
            <span class="key">ANTHROPIC_API_KEY</span>
            <span class="val <?= $diag['anthropic_key'] ? 'diag-ok' : 'diag-bad' ?>">
                <?= $diag['anthropic_key'] ? '✅ configurada' : '❌ NÃO CONFIGURADA' ?>
            </span>
        </div>
        <div class="diag-row">
            <span class="key">Prefixo</span>
            <span class="val"><?= htmlspecialchars($diag['anthropic_key_prefix'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>

    <!-- 5. Filesystem -->
    <div class="diag-card">
        <h3>5. Pasta de logs</h3>
        <div class="diag-row"><span class="key">APP_ROOT</span><span class="val"><?= htmlspecialchars($diag['app_root'], ENT_QUOTES, 'UTF-8') ?></span></div>
        <div class="diag-row"><span class="key">Pasta</span><span class="val"><?= htmlspecialchars($diag['log_dir'], ENT_QUOTES, 'UTF-8') ?></span></div>
        <div class="diag-row"><span class="key">Pasta existe?</span><span class="val <?= $diag['log_dir_existe'] ? 'diag-ok' : 'diag-bad' ?>"><?= $diag['log_dir_existe'] ? '✅ sim' : '❌ NÃO' ?></span></div>
        <div class="diag-row"><span class="key">Pasta gravável?</span><span class="val <?= $diag['log_dir_gravavel'] ? 'diag-ok' : 'diag-bad' ?>"><?= $diag['log_dir_gravavel'] ? '✅ sim' : '❌ NÃO' ?></span></div>
        <div class="diag-row"><span class="key">Arquivo claudin.log existe?</span><span class="val"><?= $diag['log_arquivo_existe'] ? '✅ ' . $diag['log_arquivo_tamanho'] . ' bytes' : '— (ainda não criado)' ?></span></div>
    </div>

    <!-- 6. Script -->
    <div class="diag-card">
        <h3>6. Script djen_monitor.php</h3>
        <div class="diag-row"><span class="key">Caminho</span><span class="val"><?= htmlspecialchars($diag['script_path'], ENT_QUOTES, 'UTF-8') ?></span></div>
        <div class="diag-row"><span class="key">Existe?</span><span class="val <?= $diag['script_existe'] ? 'diag-ok' : 'diag-bad' ?>"><?= $diag['script_existe'] ? '✅ ' . $diag['script_tamanho'] . ' bytes' : '❌ NÃO' ?></span></div>
    </div>

    <!-- 7. Última run -->
    <div class="diag-card">
        <h3>7. Última execução registrada</h3>
        <?php if ($ultimaRun): ?>
            <div class="diag-row"><span class="key">ID</span><span class="val">#<?= (int)$ultimaRun['id'] ?></span></div>
            <div class="diag-row"><span class="key">Quando</span><span class="val"><?= htmlspecialchars($ultimaRun['executado_em'], ENT_QUOTES, 'UTF-8') ?></span></div>
            <div class="diag-row"><span class="key">Status</span><span class="val"><?= htmlspecialchars($ultimaRun['status'], ENT_QUOTES, 'UTF-8') ?></span></div>
            <?php if (!empty($ultimaRun['erro_texto'])): ?>
                <div class="diag-row"><span class="key">Erro</span><span class="val diag-bad"><?= htmlspecialchars($ultimaRun['erro_texto'], ENT_QUOTES, 'UTF-8') ?></span></div>
            <?php endif; ?>
        <?php else: ?>
            <p class="diag-warn">Nenhuma execução registrada ainda.</p>
        <?php endif; ?>
    </div>

    <!-- 8. Log tail -->
    <?php if ($logTail): ?>
    <div class="diag-card">
        <h3>8. Últimas 30 linhas do log</h3>
        <pre class="diag-log"><?= htmlspecialchars($logTail, ENT_QUOTES, 'UTF-8') ?></pre>
    </div>
    <?php endif; ?>

    <!-- Bloco de teste do CRON -->
    <div class="diag-card" style="border-color:#6366f1;">
        <h3>⏰ Teste do CRON da hospedagem</h3>
        <p style="font-size:.8rem;">Script mínimo que só escreve uma linha num log quando é executado. Se o arquivo abaixo for atualizado depois do horário agendado, o cron da TurboCloud funciona.</p>

        <div class="diag-row">
            <span class="key">Arquivo de log do teste</span>
            <span class="val"><?= htmlspecialchars($testeCronLog, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="diag-row">
            <span class="key">Foi executado alguma vez?</span>
            <span class="val <?= $testeCronExiste ? 'diag-ok' : 'diag-warn' ?>">
                <?= $testeCronExiste ? '✅ sim — modificado em ' . htmlspecialchars($testeCronModificado, ENT_QUOTES, 'UTF-8') : '⚠️ ainda não' ?>
            </span>
        </div>

        <?php if ($testeCronConteudo): ?>
            <p style="font-size:.72rem;color:#64748b;margin-top:.5rem;margin-bottom:.3rem;">Conteúdo (cada linha = uma execução):</p>
            <pre class="diag-log"><?= htmlspecialchars($testeCronConteudo, ENT_QUOTES, 'UTF-8') ?></pre>
        <?php endif; ?>

        <details style="font-size:.78rem;margin-top:.6rem;">
            <summary style="cursor:pointer;font-weight:600;color:#4338ca;">Como agendar esse teste no cPanel</summary>
            <div style="background:#f8fafc;padding:.7rem;border-radius:6px;margin-top:.4rem;line-height:1.6;">
                Configure um cron job novo (temporário) com:<br>
                <br>
                <strong>Minute:</strong> (minuto atual + 2)<br>
                <strong>Hour:</strong> (hora atual)<br>
                <strong>Day / Month / Weekday:</strong> <code>*</code> <code>*</code> <code>*</code><br>
                <br>
                <strong>Command:</strong><br>
                <code style="user-select:all;background:#e5e7eb;padding:3px 6px;border-radius:4px;display:inline-block;margin-top:3px;">/usr/bin/php /home7/ferre3151357/public_html/conecta/cron/teste_cron.php &gt;&gt; /home7/ferre3151357/public_html/conecta/cron/logs/teste_cron_stdout.log 2&gt;&amp;1</code><br>
                <br>
                Espera 3 minutos e dá F5 nesta página. Se a seção "Foi executado alguma vez?" virar ✅, o cron funciona. Depois você apaga esse cron de teste.
            </div>
        </details>
    </div>

    <!-- 9. RODAR INLINE -->
    <div class="diag-card" style="border-color:#B87333;">
        <h3>9. 🧪 Rodar djen_monitor INLINE (sem shell_exec)</h3>
        <p style="font-size:.8rem;">Como a hospedagem TurboCloud desabilita <code>shell_exec</code>, o monitor roda no próprio processo PHP desta requisição. Vai demorar 1-3 minutos (consulta DJEN 3× + chama Claude em cada publicação). A tela espera a conclusão e mostra o log capturado.</p>

        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:.6rem;">
            <label style="font-size:.78rem;font-weight:600;">Data:</label>
            <input type="date" id="fgData" value="<?= date('Y-m-d') ?>" style="padding:4px 8px;border:1px solid var(--border);border-radius:6px;font-size:.78rem;">
            <button id="fgBtn" onclick="rodarFg()" class="btn btn-primary btn-sm" style="background:#B87333;">Rodar agora (inline)</button>
        </div>

        <div id="fgStatus" style="font-size:.78rem;margin-bottom:.5rem;"></div>
        <pre class="diag-log" id="fgOutput" style="display:none;"></pre>
    </div>
</div>

<script>
var DIAG_CSRF = <?= json_encode($csrfToken) ?>;

function rodarFg() {
    var data = document.getElementById('fgData').value;
    if (!data) { alert('Escolha data'); return; }

    var btn = document.getElementById('fgBtn');
    var status = document.getElementById('fgStatus');
    var output = document.getElementById('fgOutput');

    btn.disabled = true;
    btn.textContent = 'Rodando...';
    status.innerHTML = '⏳ Executando INLINE. Pode demorar 1-3 minutos. Não feche a aba.';
    output.style.display = 'none';

    var fd = new FormData();
    fd.append('action', 'rodar_fg');
    fd.append('csrf_token', DIAG_CSRF);
    fd.append('data_manual', data);

    fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            btn.disabled = false;
            btn.textContent = 'Rodar agora (inline)';
            if (j.csrf) DIAG_CSRF = j.csrf;
            if (!j.ok) {
                status.innerHTML = '<span style="color:#b91c1c">❌ Erro: ' + (j.erro || '') + '</span>';
                return;
            }
            status.innerHTML = '<span style="color:#15803d">✅ Retornou em ' + j.tempo + 's</span> '
                             + '<span style="font-size:.7rem;color:#64748b;">(' + (j.modo || '') + ')</span>';
            output.style.display = 'block';
            output.textContent = j.output || '(saída vazia)';
        })
        .catch(function(e) {
            btn.disabled = false;
            btn.textContent = 'Rodar agora (inline)';
            status.innerHTML = '<span style="color:#b91c1c">❌ Erro de rede: ' + e.message + '</span>';
        });
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
