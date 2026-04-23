<?php
/**
 * ============================================================
 * claudin_backfill.php — Puxar publicações DJEN retroativas
 * ============================================================
 *
 * Amanda escolhe um intervalo de datas e o robô roda dia por dia
 * (segunda a sexta), chamando claudin_executar('manual', $data)
 * pra cada um. PULA dias que já tenham execução com status='ok'
 * em claudin_runs — economiza tokens da API Anthropic.
 *
 * Como funciona:
 *   1. Frontend chama action=iniciar com data_inicial/final
 *   2. Backend calcula lista de dias úteis, grava arquivo
 *      de progresso em /cron/logs/backfill_progress.json
 *   3. Fecha conexão HTTP (fastcgi_finish_request) e continua
 *      rodando em background — loop itera dia a dia
 *   4. Frontend faz polling em action=ler_progresso a cada 3s
 *
 * ============================================================
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('gestao')) { flash_set('error', 'Sem permissão.'); redirect(url('modules/dashboard/')); }

define('CLAUDIN_INCLUDED', true);
require_once APP_ROOT . '/cron/claudin_config.php';

$pdo = db();
$csrfToken = generate_csrf_token();
$progressoPath = APP_ROOT . '/cron/logs/backfill_progress.json';

// ============================================================
// AJAX — ler progresso atual (com auto-destravamento)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'ler_progresso') {
    header('Content-Type: application/json; charset=utf-8');
    if (!file_exists($progressoPath)) {
        echo json_encode(array('ok' => true, 'existe' => false));
        exit;
    }
    $data = json_decode(@file_get_contents($progressoPath), true);
    $travado = false;
    // Auto-destrava: se tá rodando e o arquivo não é atualizado há mais de 5 min,
    // o processo de background morreu (timeout LiteSpeed, OOM, API DJEN não respondeu).
    // Marca o dia 'processando' como 'timeout' e encerra.
    if (is_array($data) && empty($data['ended_at'])) {
        $idle = time() - filemtime($progressoPath);
        if ($idle > 300) {
            foreach ($data['dias'] as $i => $dia) {
                if (($dia['status'] ?? '') === 'processando') {
                    $data['dias'][$i]['status'] = 'timeout';
                    $data['dias'][$i]['motivo'] = 'Processo de fundo travou (idle ' . floor($idle/60) . 'min)';
                    $data['dias'][$i]['finalizado_em'] = date('H:i:s');
                }
            }
            $data['ended_at'] = date('Y-m-d H:i:s');
            $data['destravado_auto'] = true;
            @file_put_contents($progressoPath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $travado = true;
        }
    }
    echo json_encode(array('ok' => true, 'existe' => true, 'dados' => $data, 'travado' => $travado));
    exit;
}

// ============================================================
// AJAX — destravar manualmente (botão emergência)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'destravar') {
    header('Content-Type: application/json; charset=utf-8');
    if (!validate_csrf()) { echo json_encode(array('ok' => false, 'erro' => 'CSRF inválido', 'csrf' => generate_csrf_token())); exit; }
    if (!file_exists($progressoPath)) {
        echo json_encode(array('ok' => false, 'erro' => 'Sem backfill em andamento', 'csrf' => generate_csrf_token()));
        exit;
    }
    $data = json_decode(@file_get_contents($progressoPath), true);
    if (is_array($data)) {
        foreach (($data['dias'] ?? []) as $i => $dia) {
            if (($dia['status'] ?? '') === 'processando' || ($dia['status'] ?? '') === 'aguardando') {
                $data['dias'][$i]['status'] = 'timeout';
                $data['dias'][$i]['motivo'] = 'Cancelado manualmente';
                if (empty($data['dias'][$i]['finalizado_em'])) $data['dias'][$i]['finalizado_em'] = date('H:i:s');
            }
        }
        $data['ended_at'] = date('Y-m-d H:i:s');
        @file_put_contents($progressoPath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    echo json_encode(array('ok' => true, 'csrf' => generate_csrf_token()));
    exit;
}

// ============================================================
// AJAX — iniciar backfill
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'iniciar') {
    header('Content-Type: application/json; charset=utf-8');
    if (!validate_csrf()) { echo json_encode(array('ok' => false, 'erro' => 'CSRF inválido', 'csrf' => generate_csrf_token())); exit; }

    $dataIni = $_POST['data_inicial'] ?? '';
    $dataFim = $_POST['data_final'] ?? '';

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataIni) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim)) {
        echo json_encode(array('ok' => false, 'erro' => 'Datas inválidas', 'csrf' => generate_csrf_token()));
        exit;
    }
    if ($dataIni > $dataFim) {
        echo json_encode(array('ok' => false, 'erro' => 'Data inicial maior que final', 'csrf' => generate_csrf_token()));
        exit;
    }
    if ($dataFim > date('Y-m-d')) {
        echo json_encode(array('ok' => false, 'erro' => 'Data final não pode ser no futuro', 'csrf' => generate_csrf_token()));
        exit;
    }

    // Checa se já tem backfill em andamento
    if (file_exists($progressoPath)) {
        $atual = json_decode(@file_get_contents($progressoPath), true);
        if (is_array($atual) && empty($atual['ended_at'])) {
            echo json_encode(array(
                'ok' => false,
                'erro' => 'Já existe um backfill em andamento (iniciado em ' . ($atual['started_at'] ?? '') . '). Aguarde terminar ou delete /cron/logs/backfill_progress.json no Gerenciador de Arquivos.',
                'csrf' => generate_csrf_token(),
            ));
            exit;
        }
    }

    // Calcula lista de dias úteis no intervalo
    $dias = array();
    $d = new DateTime($dataIni);
    $fim = new DateTime($dataFim);
    while ($d <= $fim) {
        $dow = (int)$d->format('N');
        if ($dow < 6) {
            $dias[] = array(
                'data'   => $d->format('Y-m-d'),
                'status' => 'aguardando',
            );
        }
        $d->modify('+1 day');
    }

    if (empty($dias)) {
        echo json_encode(array('ok' => false, 'erro' => 'Nenhum dia útil no intervalo', 'csrf' => generate_csrf_token()));
        exit;
    }

    $progresso = array(
        'started_at' => date('Y-m-d H:i:s'),
        'ended_at'   => null,
        'data_inicial' => $dataIni,
        'data_final'   => $dataFim,
        'total_dias'   => count($dias),
        'processados'  => 0,
        'pulados'      => 0,
        'dias'         => $dias,
    );
    @mkdir(dirname($progressoPath), 0755, true);
    @file_put_contents($progressoPath, json_encode($progresso, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // Retorna imediato e fecha conexão
    $newCsrf = generate_csrf_token();
    $respJson = json_encode(array(
        'ok'   => true,
        'msg'  => count($dias) . ' dia(s) úteis na fila. Progresso atualiza sozinho aqui na tela.',
        'csrf' => $newCsrf,
    ));

    ignore_user_abort(true);
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');

    header('Content-Type: application/json; charset=utf-8');
    header('Connection: close');
    header('Content-Length: ' . strlen($respJson));
    echo $respJson;

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_end_flush();
        @flush();
    }

    // ============================================================
    // Loop de backfill em background
    // ============================================================
    define('CLAUDIN_NO_AUTORUN', true);
    try {
        require_once APP_ROOT . '/cron/djen_monitor.php';
    } catch (Throwable $e) {
        $progresso['ended_at'] = date('Y-m-d H:i:s');
        $progresso['erro'] = 'Falha ao carregar djen_monitor: ' . $e->getMessage();
        @file_put_contents($progressoPath, json_encode($progresso, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        exit;
    }

    $pdoBg = db();

    foreach ($progresso['dias'] as $idx => $dia) {
        // Recarrega o arquivo a cada iteração (caso outro processo interfira)
        $progresso['dias'][$idx]['status'] = 'processando';
        $progresso['dias'][$idx]['iniciado_em'] = date('H:i:s');
        @file_put_contents($progressoPath, json_encode($progresso, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        try {
            // Checa se já tem run OK pra esse dia — se sim, PULA
            $stmt = $pdoBg->prepare("SELECT id, total_parsed, imported, duplicated, pending FROM claudin_runs WHERE data_alvo = ? AND status = 'ok' ORDER BY id DESC LIMIT 1");
            $stmt->execute(array($dia['data']));
            $jaOk = $stmt->fetch();

            if ($jaOk) {
                $progresso['dias'][$idx]['status'] = 'pulado';
                $progresso['dias'][$idx]['motivo'] = 'já processado com sucesso (run #' . (int)$jaOk['id'] . ')';
                $progresso['dias'][$idx]['parsed'] = (int)$jaOk['total_parsed'];
                $progresso['dias'][$idx]['imported'] = (int)$jaOk['imported'];
                $progresso['dias'][$idx]['duplicated'] = (int)$jaOk['duplicated'];
                $progresso['pulados']++;
            } else {
                $tIni = microtime(true);
                // Heartbeat: toca o arquivo logo antes da chamada longa, evita falso-positivo
                // do auto-destravamento se o dia demorar > 5 min (raro, mas possível)
                @touch($progressoPath);
                $resultado = claudin_executar('manual', $dia['data']);
                $tempo = round(microtime(true) - $tIni, 1);

                $progresso['dias'][$idx]['status'] = isset($resultado['status']) ? $resultado['status'] : 'ok';
                $progresso['dias'][$idx]['parsed'] = isset($resultado['contadores']['total_parsed']) ? $resultado['contadores']['total_parsed'] : 0;
                $progresso['dias'][$idx]['imported'] = isset($resultado['contadores']['imported']) ? $resultado['contadores']['imported'] : 0;
                $progresso['dias'][$idx]['duplicated'] = isset($resultado['contadores']['duplicated']) ? $resultado['contadores']['duplicated'] : 0;
                $progresso['dias'][$idx]['pending'] = isset($resultado['contadores']['pending']) ? $resultado['contadores']['pending'] : 0;
                $progresso['dias'][$idx]['tempo'] = $tempo;
            }
        } catch (Throwable $e) {
            $progresso['dias'][$idx]['status'] = 'erro';
            $progresso['dias'][$idx]['motivo'] = substr($e->getMessage(), 0, 200);
        }

        $progresso['dias'][$idx]['finalizado_em'] = date('H:i:s');
        $progresso['processados']++;
        @file_put_contents($progressoPath, json_encode($progresso, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // Respira 1s entre dias (gentil com a API DJEN)
        sleep(1);
    }

    $progresso['ended_at'] = date('Y-m-d H:i:s');
    @file_put_contents($progressoPath, json_encode($progresso, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    exit;
}

// ============================================================
// AJAX — limpar progresso (pra reiniciar)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'limpar') {
    header('Content-Type: application/json; charset=utf-8');
    if (!validate_csrf()) { echo json_encode(array('ok' => false, 'erro' => 'CSRF inválido', 'csrf' => generate_csrf_token())); exit; }
    if (file_exists($progressoPath)) @unlink($progressoPath);
    echo json_encode(array('ok' => true, 'csrf' => generate_csrf_token()));
    exit;
}

$pageTitle = 'Claudin — Backfill Retroativo';
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.bf-wrap { max-width:1100px; margin:0 auto; }
.bf-card { background:var(--bg-card); border:1px solid var(--border); border-radius:12px; padding:1.2rem 1.4rem; margin-bottom:1rem; }
.bf-card h3 { margin:0 0 .6rem; font-size:1rem; color:var(--petrol-900); }
.bf-form { display:flex; gap:.6rem; align-items:flex-end; flex-wrap:wrap; }
.bf-form label { font-size:.72rem; font-weight:700; color:var(--text-muted); display:block; margin-bottom:3px; }
.bf-form input[type=date] { padding:6px 10px; border:1.5px solid var(--border); border-radius:6px; font-size:.85rem; }

.bf-table { width:100%; border-collapse:collapse; font-size:.8rem; background:var(--bg-card); border-radius:10px; overflow:hidden; }
.bf-table th { background:var(--petrol-900); color:#fff; padding:.45rem .6rem; text-align:center; font-size:.68rem; text-transform:uppercase; letter-spacing:.5px; }
.bf-table td { padding:.4rem .6rem; border-bottom:1px solid var(--border); text-align:center; vertical-align:middle; }
.bf-table tr.row-pulado { background:#f1f5f9; color:#64748b; }
.bf-table tr.row-processando { background:#fef3c7; }
.bf-table tr.row-erro { background:#fee2e2; color:#b91c1c; }
.bf-table tr.row-ok { background:#dcfce7; }

.bf-status { display:inline-block; padding:2px 8px; border-radius:12px; font-size:.66rem; font-weight:700; text-transform:uppercase; letter-spacing:.3px; }
.bf-status.aguardando { background:#e2e8f0; color:#475569; }
.bf-status.processando { background:#fef3c7; color:#b45309; }
.bf-status.ok { background:#dcfce7; color:#15803d; }
.bf-status.parcial { background:#fef3c7; color:#b45309; }
.bf-status.falha { background:#fee2e2; color:#b91c1c; }
.bf-status.pulado { background:#f1f5f9; color:#64748b; }
.bf-status.erro { background:#fee2e2; color:#b91c1c; }
.bf-status.timeout { background:#fed7aa; color:#9a3412; }

.bf-progress { height:22px; background:#e2e8f0; border-radius:11px; overflow:hidden; margin-bottom:.8rem; position:relative; }
.bf-progress-bar { height:100%; background:linear-gradient(90deg, #B87333, #052228); transition:width .3s; }
.bf-progress-label { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:.78rem; font-weight:700; color:#052228; }
</style>

<div class="bf-wrap">
    <h2 style="color:var(--petrol-900);margin-bottom:.2rem;">🔄 Backfill Retroativo DJEN</h2>
    <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:1.2rem;">Puxa publicações de dias passados. Processa segunda a sexta, ignora sábado e domingo. <strong>Pula dias que já foram processados com status OK</strong> (economiza tokens de IA).</p>

    <!-- Formulário -->
    <div class="bf-card">
        <h3>📅 Escolher período</h3>
        <form id="bfForm" class="bf-form">
            <div>
                <label>Data inicial</label>
                <input type="date" id="bfInicio" required>
            </div>
            <div>
                <label>Data final</label>
                <input type="date" id="bfFim" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
            </div>
            <button type="submit" id="bfBtnRodar" class="btn btn-primary btn-sm" style="background:#B87333;">Iniciar backfill</button>
            <button type="button" id="bfBtnDestravar" class="btn btn-sm" onclick="bfDestravar()" style="display:none;background:#dc2626;color:#fff;border:none;">🚨 Destravar (cancelar)</button>
            <button type="button" id="bfBtnLimpar" class="btn btn-outline btn-sm" onclick="bfLimpar()" style="display:none;">Limpar e rodar novo</button>
        </form>
        <div id="bfMsg" style="margin-top:.6rem;font-size:.78rem;"></div>
    </div>

    <!-- Progresso -->
    <div class="bf-card" id="bfProgressoBox" style="display:none;">
        <h3>⏳ Progresso</h3>
        <div class="bf-progress">
            <div class="bf-progress-bar" id="bfBar" style="width:0%;"></div>
            <div class="bf-progress-label" id="bfBarLabel">0 de 0 dias</div>
        </div>
        <div id="bfResumo" style="font-size:.78rem;color:var(--text-muted);margin-bottom:.6rem;"></div>

        <div style="overflow-x:auto;">
            <table class="bf-table" id="bfTable">
                <thead>
                    <tr>
                        <th>Dia</th>
                        <th>Status</th>
                        <th>Início</th>
                        <th>Fim</th>
                        <th>Parsed</th>
                        <th>Importadas</th>
                        <th>Duplicadas</th>
                        <th>Pendentes</th>
                        <th>Tempo</th>
                        <th>Obs</th>
                    </tr>
                </thead>
                <tbody id="bfTbody"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
var BF_CSRF = <?= json_encode($csrfToken) ?>;
var BF_TIMER = null;

document.getElementById('bfForm').addEventListener('submit', function(e) {
    e.preventDefault();
    bfIniciar();
});

function bfIniciar() {
    var ini = document.getElementById('bfInicio').value;
    var fim = document.getElementById('bfFim').value;
    if (!ini || !fim) { alert('Escolha as duas datas.'); return; }
    if (ini > fim) { alert('Data inicial precisa ser menor ou igual à final.'); return; }

    if (!confirm('Iniciar backfill de ' + ini + ' até ' + fim + '? Vai rodar em background — pode levar vários minutos.')) return;

    var btn = document.getElementById('bfBtnRodar');
    var msg = document.getElementById('bfMsg');
    btn.disabled = true;
    btn.textContent = 'Iniciando...';
    msg.innerHTML = '⏳ Disparando...';

    var fd = new FormData();
    fd.append('action', 'iniciar');
    fd.append('csrf_token', BF_CSRF);
    fd.append('data_inicial', ini);
    fd.append('data_final', fim);

    fetch(window.location.pathname, { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(j) {
            if (j.csrf) BF_CSRF = j.csrf;
            btn.disabled = false;
            btn.textContent = 'Iniciar backfill';
            if (!j.ok) {
                msg.innerHTML = '<span style="color:#b91c1c">❌ ' + (j.erro || 'Erro') + '</span>';
                return;
            }
            msg.innerHTML = '<span style="color:#15803d">✅ ' + j.msg + '</span>';
            document.getElementById('bfProgressoBox').style.display = 'block';
            document.getElementById('bfBtnLimpar').style.display = 'inline-block';
            bfIniciarPolling();
        })
        .catch(function(e) {
            btn.disabled = false;
            btn.textContent = 'Iniciar backfill';
            msg.innerHTML = '<span style="color:#b91c1c">❌ Erro de rede: ' + e.message + '</span>';
        });
}

function bfIniciarPolling() {
    if (BF_TIMER) clearInterval(BF_TIMER);
    bfLerProgresso();
    BF_TIMER = setInterval(bfLerProgresso, 3000);
}

function bfLerProgresso() {
    fetch(window.location.pathname + '?action=ler_progresso', { credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(j) {
            if (!j.ok || !j.existe) return;
            bfRenderProgresso(j.dados);
            // Parar polling quando terminar
            if (j.dados && j.dados.ended_at) {
                clearInterval(BF_TIMER);
                BF_TIMER = null;
            }
        })
        .catch(function(e) { /* silent */ });
}

function bfEsc(s) {
    return String(s).replace(/[&<>"']/g, function(c) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
}

function bfFmtData(s) {
    if (!s) return '—';
    var p = s.split('-');
    return p[2] + '/' + p[1] + '/' + p[0];
}

function bfRenderProgresso(d) {
    if (!d || !d.dias) return;
    var pct = d.total_dias > 0 ? Math.round((d.processados / d.total_dias) * 100) : 0;
    document.getElementById('bfBar').style.width = pct + '%';
    document.getElementById('bfBarLabel').textContent = d.processados + ' de ' + d.total_dias + ' dias (' + pct + '%)';

    var resumo = '';
    if (d.ended_at) {
        if (d.destravado_auto) {
            resumo = '⚠️ Backfill destravado automaticamente (processo de fundo travou). Fim ' + d.ended_at + '. Processados: ' + d.processados + '. Clique em "Limpar e rodar novo" pra continuar dos dias restantes.';
        } else {
            resumo = '✅ Finalizado em ' + d.ended_at + '. Pulados: ' + (d.pulados || 0) + '.';
        }
    } else {
        resumo = '⏳ Rodando... iniciado em ' + d.started_at + ' — pulados: ' + (d.pulados || 0) + ' de ' + d.processados + ' processados';
    }
    document.getElementById('bfResumo').textContent = resumo;
    // Mostra botão destravar só quando está rodando
    var btnDestr = document.getElementById('bfBtnDestravar');
    if (btnDestr) btnDestr.style.display = d.ended_at ? 'none' : 'inline-block';

    var tb = document.getElementById('bfTbody');
    var html = '';
    d.dias.forEach(function(dia) {
        var cls = '';
        if (dia.status === 'processando') cls = 'row-processando';
        else if (dia.status === 'pulado') cls = 'row-pulado';
        else if (dia.status === 'erro' || dia.status === 'falha') cls = 'row-erro';
        else if (dia.status === 'ok') cls = 'row-ok';

        html += '<tr class="' + cls + '">'
             + '<td><strong>' + bfEsc(bfFmtData(dia.data)) + '</strong></td>'
             + '<td><span class="bf-status ' + bfEsc(dia.status) + '">' + bfEsc((dia.status || '').toUpperCase()) + '</span></td>'
             + '<td>' + bfEsc(dia.iniciado_em || '—') + '</td>'
             + '<td>' + bfEsc(dia.finalizado_em || '—') + '</td>'
             + '<td>' + (dia.parsed !== undefined ? dia.parsed : '—') + '</td>'
             + '<td style="color:#15803d;font-weight:600;">' + (dia.imported !== undefined ? dia.imported : '—') + '</td>'
             + '<td>' + (dia.duplicated !== undefined ? dia.duplicated : '—') + '</td>'
             + '<td>' + (dia.pending !== undefined ? dia.pending : '—') + '</td>'
             + '<td>' + (dia.tempo !== undefined ? dia.tempo + 's' : '—') + '</td>'
             + '<td style="font-size:.7rem;color:#64748b;">' + bfEsc(dia.motivo || '') + '</td>'
             + '</tr>';
    });
    tb.innerHTML = html;
}

function bfDestravar() {
    if (!confirm('Destravar o backfill? Marca o dia que está "processando" como timeout e cancela os pendentes. Depois você pode rodar novo backfill dos dias restantes.')) return;
    var fd = new FormData();
    fd.append('action', 'destravar');
    fd.append('csrf_token', BF_CSRF);
    fetch(window.location.pathname, { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(j) {
            if (j.csrf) BF_CSRF = j.csrf;
            if (j.ok) { bfLerProgresso(); }
            else alert('Erro: ' + (j.erro || '(sem detalhes)'));
        });
}

function bfLimpar() {
    if (!confirm('Limpar o progresso anterior? (Não desfaz as publicações já importadas — só limpa esta tela.)')) return;
    var fd = new FormData();
    fd.append('action', 'limpar');
    fd.append('csrf_token', BF_CSRF);
    fetch(window.location.pathname, { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(j) {
            if (j.csrf) BF_CSRF = j.csrf;
            if (BF_TIMER) { clearInterval(BF_TIMER); BF_TIMER = null; }
            document.getElementById('bfProgressoBox').style.display = 'none';
            document.getElementById('bfBtnLimpar').style.display = 'none';
            document.getElementById('bfMsg').textContent = '';
            document.getElementById('bfTbody').innerHTML = '';
        });
}

// Ao abrir, se já houver backfill em andamento, mostra
bfLerProgresso();
setTimeout(function() {
    var box = document.getElementById('bfProgressoBox');
    // Se renderizou algo no tbody, mostra o box
    if (document.getElementById('bfTbody').children.length > 0) {
        box.style.display = 'block';
        document.getElementById('bfBtnLimpar').style.display = 'inline-block';
        // Se ainda não finalizou, continua polling
        fetch(window.location.pathname + '?action=ler_progresso', { credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(j) {
                if (j.ok && j.existe && j.dados && !j.dados.ended_at) {
                    bfIniciarPolling();
                }
            });
    }
}, 500);
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
