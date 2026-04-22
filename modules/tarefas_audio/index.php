<?php
/**
 * Tarefa por áudio — UI.
 * Gravador MediaRecorder → envia pra api.php → preview editável → salvar.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$pageTitle = '🎙️ Nova tarefa por áudio';

// Lista de usuários ativos pra select de responsável
$usuarios = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();
// Processos ativos pra select (recentes)
$casos = $pdo->query(
    "SELECT cs.id, cs.title, cs.case_number, cl.name AS client_name
     FROM cases cs LEFT JOIN clients cl ON cl.id = cs.client_id
     WHERE cs.status NOT IN ('arquivado','cancelado','finalizado','concluido')
     ORDER BY cs.updated_at DESC LIMIT 150"
)->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.taud-card { background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.5rem;max-width:720px;margin:0 auto 1rem; }
.taud-rec { display:flex;flex-direction:column;align-items:center;gap:1rem;padding:2rem; }
.taud-btn { width:120px;height:120px;border-radius:50%;border:none;font-size:3rem;cursor:pointer;transition:all .2s;box-shadow:0 8px 24px rgba(0,0,0,.15); }
.taud-btn.idle { background:linear-gradient(135deg,#B87333,#8b5a26);color:#fff; }
.taud-btn.idle:hover { transform:scale(1.05);box-shadow:0 12px 32px rgba(184,115,51,.4); }
.taud-btn.rec { background:linear-gradient(135deg,#dc2626,#991b1b);color:#fff;animation:taud-pulse 1.5s infinite; }
.taud-btn:disabled { opacity:.5;cursor:wait; }
@keyframes taud-pulse { 0%,100% { box-shadow:0 8px 24px rgba(220,38,38,.5); } 50% { box-shadow:0 8px 24px rgba(220,38,38,1), 0 0 0 20px rgba(220,38,38,0); } }
.taud-status { font-weight:700;color:var(--petrol-900);font-size:1rem;text-align:center; }
.taud-timer { font-family:monospace;font-size:1.8rem;font-weight:800;color:var(--petrol-900); }
.taud-hint { font-size:.8rem;color:var(--text-muted);text-align:center;line-height:1.5; }
.taud-preview label { font-size:.7rem;font-weight:700;color:var(--text-muted);display:block;margin-bottom:.2rem;text-transform:uppercase;letter-spacing:.3px; }
.taud-preview input, .taud-preview select, .taud-preview textarea { width:100%;padding:.55rem .75rem;font-size:.88rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit; }
.taud-preview textarea { min-height:70px;resize:vertical; }
.taud-grid2 { display:grid;grid-template-columns:1fr 1fr;gap:.75rem; }
.taud-tag { display:inline-block;padding:2px 10px;border-radius:12px;font-size:.68rem;font-weight:700;color:#fff; }
.taud-tag.urgente { background:#dc2626; }
.taud-tag.alta { background:#f59e0b; }
.taud-tag.normal { background:#6b7280; }
@media (max-width:600px) { .taud-grid2 { grid-template-columns:1fr; } }
</style>

<div class="taud-card" id="stepRec">
    <h2 style="margin:0 0 .5rem;font-size:1.2rem;color:var(--petrol-900);">🎙️ Nova tarefa por áudio</h2>
    <p style="font-size:.85rem;color:var(--text-muted);margin:0 0 1rem;">Grave um áudio ditando a tarefa — mencione cliente, prazo e responsável. A IA extrai os dados, você revisa e salva.</p>

    <div class="taud-rec">
        <div class="taud-timer" id="timer">00:00</div>
        <button id="btnRec" class="taud-btn idle" title="Iniciar gravação">🎙️</button>
        <div class="taud-status" id="status">Clique no microfone pra começar</div>
        <div class="taud-hint">
            Exemplo: <i>"Peticionar resposta ao agravo do processo da Maria Silva até sexta-feira. Prioridade alta, responsável Andressia."</i>
        </div>
    </div>
</div>

<div class="taud-card" id="stepPreview" style="display:none;">
    <h2 style="margin:0 0 .5rem;font-size:1.1rem;color:var(--petrol-900);">✏️ Revise a tarefa extraída</h2>
    <p style="font-size:.78rem;color:var(--text-muted);margin:0 0 1rem;">Edite o que precisar antes de salvar.</p>

    <div id="transcricaoBox" style="background:#f9fafb;border-left:3px solid #B87333;padding:.5rem .8rem;margin-bottom:1rem;font-size:.78rem;color:#374151;border-radius:4px;font-style:italic;"></div>

    <div class="taud-preview" style="display:flex;flex-direction:column;gap:.75rem;">
        <div><label>Título *</label><input type="text" id="pvTitulo" maxlength="200" required></div>
        <div><label>Descrição</label><textarea id="pvDescricao"></textarea></div>
        <div class="taud-grid2">
            <div><label>Tipo</label><select id="pvTipo">
                <option value="prazo">⏰ Prazo</option>
                <option value="peticao">📄 Petição</option>
                <option value="audiencia">⚖️ Audiência</option>
                <option value="reuniao">🤝 Reunião</option>
                <option value="diligencia">🏛️ Diligência</option>
                <option value="outro" selected>📌 Outro</option>
            </select></div>
            <div><label>Prioridade</label><select id="pvPrioridade">
                <option value="normal">Normal</option>
                <option value="alta">🔥 Alta</option>
                <option value="urgente">🚨 Urgente</option>
            </select></div>
        </div>
        <div class="taud-grid2">
            <div><label>Prazo</label><input type="date" id="pvPrazo"></div>
            <div><label>Responsável</label><select id="pvResponsavel">
                <option value="">— Sem responsável —</option>
                <?php foreach ($usuarios as $u): ?>
                    <option value="<?= (int)$u['id'] ?>"><?= e(explode(' ', $u['name'])[0]) ?> · <?= e(mb_substr($u['name'], 0, 30)) ?></option>
                <?php endforeach; ?>
            </select></div>
        </div>
        <div><label>Processo vinculado *</label>
            <select id="pvCaseId" required>
                <option value="">— Selecione o processo —</option>
                <?php foreach ($casos as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= e(mb_substr($c['title'] ?: 'Processo #' . $c['id'], 0, 60)) ?><?= $c['client_name'] ? ' · ' . e(mb_substr($c['client_name'], 0, 30)) : '' ?></option>
                <?php endforeach; ?>
            </select>
            <div id="casosSugeridos" style="display:none;margin-top:.35rem;font-size:.72rem;"></div>
        </div>
    </div>

    <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--border);">
        <button id="btnVoltar" class="btn btn-outline btn-sm">← Gravar de novo</button>
        <button id="btnSalvar" class="btn btn-primary btn-sm" style="background:#B87333;">✓ Salvar tarefa</button>
    </div>
</div>

<script>
(function(){
    var CSRF = <?= json_encode(generate_csrf_token()) ?>;
    var API = <?= json_encode(module_url('tarefas_audio', 'api.php')) ?>;
    var mediaRecorder = null, audioChunks = [], startTs = 0, timerInt = null;
    var btnRec = document.getElementById('btnRec');
    var elStatus = document.getElementById('status');
    var elTimer = document.getElementById('timer');

    btnRec.addEventListener('click', function(){
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            mediaRecorder.stop();
        } else {
            iniciarGravacao();
        }
    });

    function iniciarGravacao() {
        if (!navigator.mediaDevices || !window.MediaRecorder) {
            alert('Seu navegador não suporta gravação. Use Chrome ou Edge atualizado.');
            return;
        }
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function(stream){
            audioChunks = [];
            var mimes = ['audio/webm;codecs=opus','audio/webm','audio/mp4','audio/ogg'];
            var mime = mimes.find(function(m){ return MediaRecorder.isTypeSupported(m); }) || '';
            mediaRecorder = mime ? new MediaRecorder(stream, { mimeType: mime }) : new MediaRecorder(stream);
            mediaRecorder.ondataavailable = function(e){ if (e.data.size > 0) audioChunks.push(e.data); };
            mediaRecorder.onstop = function(){
                stream.getTracks().forEach(function(t){ t.stop(); });
                clearInterval(timerInt);
                btnRec.classList.remove('rec'); btnRec.classList.add('idle');
                btnRec.innerHTML = '🎙️';
                var blob = new Blob(audioChunks, { type: mediaRecorder.mimeType || 'audio/webm' });
                enviarAudio(blob);
            };
            mediaRecorder.start();
            startTs = Date.now();
            timerInt = setInterval(function(){
                var s = Math.floor((Date.now() - startTs) / 1000);
                elTimer.textContent = String(Math.floor(s/60)).padStart(2,'0') + ':' + String(s%60).padStart(2,'0');
                if (s >= 120) { mediaRecorder.stop(); elStatus.textContent = 'Limite de 2 min atingido'; }
            }, 250);
            btnRec.classList.remove('idle'); btnRec.classList.add('rec');
            btnRec.innerHTML = '⏹';
            elStatus.textContent = 'Gravando... clique de novo pra parar';
            btnRec.title = 'Parar gravação';
        }).catch(function(err){
            alert('Permissão de microfone negada ou erro: ' + err.message);
        });
    }

    function enviarAudio(blob) {
        btnRec.disabled = true;
        elStatus.textContent = '🔄 Transcrevendo e analisando...';
        var fd = new FormData();
        fd.append('action', 'transcrever');
        fd.append('audio', blob, 'tarefa.webm');
        fd.append('csrf_token', CSRF);
        fetch(API, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r){ return r.json(); })
            .then(function(j){
                btnRec.disabled = false;
                if (j.csrf_expired) { alert('Sessão expirou. Recarregue a página.'); return; }
                if (j.error) {
                    alert('Erro: ' + j.error + (j.transcricao ? '\n\nTranscrição: ' + j.transcricao : ''));
                    elStatus.textContent = 'Tente gravar de novo';
                    return;
                }
                mostrarPreview(j);
            })
            .catch(function(e){
                btnRec.disabled = false;
                alert('Erro de conexão: ' + e.message);
                elStatus.textContent = 'Tente gravar de novo';
            });
    }

    function mostrarPreview(j) {
        var e = j.extraido || {};
        document.getElementById('transcricaoBox').innerHTML = '🎙️ <b>Transcrição:</b> ' + escapeHtml(j.transcricao || '');
        document.getElementById('pvTitulo').value = e.titulo || '';
        document.getElementById('pvDescricao').value = e.descricao || '';
        document.getElementById('pvTipo').value = e.tipo || 'outro';
        document.getElementById('pvPrioridade').value = e.prioridade || 'normal';
        document.getElementById('pvPrazo').value = e.prazo || '';

        // Responsável
        if (j.responsavel_sugerido) {
            document.getElementById('pvResponsavel').value = j.responsavel_sugerido.id;
        }
        // Caso: se tem UM caso sugerido, pré-seleciona; se tem vários, mostra lista
        var sugDiv = document.getElementById('casosSugeridos');
        if (j.casos_sugeridos && j.casos_sugeridos.length === 1) {
            document.getElementById('pvCaseId').value = j.casos_sugeridos[0].id;
            sugDiv.style.display = 'block';
            sugDiv.innerHTML = '<span style="color:#059669;">✓ Processo sugerido pelo cliente mencionado: <b>' + escapeHtml(j.casos_sugeridos[0].title) + '</b></span>';
        } else if (j.casos_sugeridos && j.casos_sugeridos.length > 1) {
            sugDiv.style.display = 'block';
            sugDiv.innerHTML = '💡 Achei ' + j.casos_sugeridos.length + ' processos deste cliente — escolha acima:<br>' +
                j.casos_sugeridos.map(function(c){ return '• #' + c.id + ' ' + escapeHtml(c.title); }).join('<br>');
            document.getElementById('pvCaseId').value = j.casos_sugeridos[0].id;
        }

        document.getElementById('stepRec').style.display = 'none';
        document.getElementById('stepPreview').style.display = 'block';
    }

    document.getElementById('btnVoltar').addEventListener('click', function(){
        document.getElementById('stepRec').style.display = 'block';
        document.getElementById('stepPreview').style.display = 'none';
        elStatus.textContent = 'Clique no microfone pra começar';
        elTimer.textContent = '00:00';
    });

    document.getElementById('btnSalvar').addEventListener('click', function(){
        var titulo = document.getElementById('pvTitulo').value.trim();
        var caseId = document.getElementById('pvCaseId').value;
        if (!titulo) { alert('Informe o título'); return; }
        if (!caseId) { alert('Selecione o processo vinculado'); return; }
        this.disabled = true; this.textContent = 'Salvando...';

        var fd = new FormData();
        fd.append('action', 'salvar');
        fd.append('titulo', titulo);
        fd.append('descricao', document.getElementById('pvDescricao').value);
        fd.append('tipo', document.getElementById('pvTipo').value);
        fd.append('prioridade', document.getElementById('pvPrioridade').value);
        fd.append('prazo', document.getElementById('pvPrazo').value);
        fd.append('assigned_to', document.getElementById('pvResponsavel').value);
        fd.append('case_id', caseId);
        fd.append('transcricao', document.getElementById('transcricaoBox').textContent.replace(/^🎙️\s*Transcrição:\s*/, ''));
        fd.append('csrf_token', CSRF);

        var btn = this;
        fetch(API, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r){ return r.json(); })
            .then(function(j){
                if (j.error) { alert('Erro: ' + j.error); btn.disabled = false; btn.textContent = '✓ Salvar tarefa'; return; }
                if (j.ok) {
                    alert('✓ Tarefa #' + j.task_id + ' criada com sucesso!');
                    window.location = '<?= module_url('operacional', 'caso_ver.php?id=') ?>' + j.case_id;
                }
            })
            .catch(function(e){ alert('Erro de conexão: ' + e.message); btn.disabled = false; btn.textContent = '✓ Salvar tarefa'; });
    });

    function escapeHtml(s){ return (s||'').replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
})();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
