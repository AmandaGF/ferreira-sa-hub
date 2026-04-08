<?php
/**
 * Ferreira & Sá Hub — Planilha de Débito
 * Upload PDF (Jusfy) → Claude AI extrai dados → Gera XLSX/PDF com layout FeS
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Planilha de Débito';
$pdo = db();

// Buscar planilhas geradas
$planilhas = array();
try {
    $planilhas = $pdo->query(
        "SELECT pd.*, u.name as user_name, cs.title as case_title, cl.name as client_name
         FROM planilha_debito pd
         LEFT JOIN users u ON u.id = pd.created_by
         LEFT JOIN cases cs ON cs.id = pd.case_id
         LEFT JOIN clients cl ON cl.id = pd.client_id
         ORDER BY pd.created_at DESC LIMIT 50"
    )->fetchAll();
} catch (Exception $e) {}

// Buscar casos para select
$cases = $pdo->query("SELECT id, title, case_number, client_id FROM cases WHERE status NOT IN ('cancelado','arquivado') ORDER BY title")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.pd-upload { background:var(--bg-card); border:2px dashed var(--border); border-radius:16px; padding:2rem; text-align:center; cursor:pointer; transition:all .2s; margin-bottom:1.5rem; }
.pd-upload:hover { border-color:#B87333; background:rgba(184,115,51,.03); }
.pd-upload.dragover { border-color:#052228; background:rgba(5,34,40,.05); }
.pd-upload-icon { font-size:2.5rem; margin-bottom:.5rem; }
.pd-upload-text { font-size:.88rem; color:var(--petrol-900); font-weight:700; }
.pd-upload-hint { font-size:.75rem; color:var(--text-muted); margin-top:.3rem; }
.pd-progress { display:none; margin-top:1rem; }
.pd-progress-bar { height:6px; background:#e5e7eb; border-radius:3px; overflow:hidden; }
.pd-progress-fill { height:100%; background:linear-gradient(90deg,#052228,#B87333); border-radius:3px; transition:width .3s; width:0; }
.pd-status { font-size:.78rem; color:var(--text-muted); margin-top:.4rem; text-align:center; }
.pd-list table { width:100%; border-collapse:collapse; font-size:.82rem; }
.pd-list th { background:var(--petrol-900); color:#fff; padding:.5rem .75rem; text-align:left; font-size:.72rem; text-transform:uppercase; }
.pd-list td { padding:.5rem .75rem; border-bottom:1px solid var(--border); }
</style>

<div style="max-width:800px;">
    <!-- Upload -->
    <div class="pd-upload" id="uploadZone" onclick="document.getElementById('pdfInput').click()">
        <div class="pd-upload-icon">📄</div>
        <div class="pd-upload-text">Arraste o PDF da planilha (Jusfy) ou clique para selecionar</div>
        <div class="pd-upload-hint">O sistema vai extrair os dados com IA e gerar a planilha no layout do escritório</div>
        <input type="file" id="pdfInput" accept=".pdf" style="display:none" onchange="iniciarProcessamento()">
    </div>

    <!-- Caso vinculado (opcional) -->
    <div id="pdOpcoes" style="display:none;margin-bottom:1rem;">
        <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:end;">
            <div style="flex:1;min-width:200px;">
                <label style="font-size:.75rem;font-weight:700;color:var(--text-muted);display:block;margin-bottom:.2rem;">Vincular a processo (opcional)</label>
                <select id="pdCaseId" class="form-select" style="font-size:.85rem;">
                    <option value="">— Nenhum —</option>
                    <?php foreach ($cases as $c): ?>
                        <option value="<?= $c['id'] ?>" data-client="<?= (int)($c['client_id'] ?? 0) ?>"><?= e($c['title']) ?><?= $c['case_number'] ? ' — ' . e($c['case_number']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="min-width:200px;">
                <label style="font-size:.75rem;font-weight:700;color:var(--text-muted);display:block;margin-bottom:.2rem;">Título da planilha</label>
                <input type="text" id="pdTitulo" class="form-input" style="font-size:.85rem;" placeholder="Ex: Débito alimentar Jan/2024 a Mar/2026">
            </div>
        </div>
    </div>

    <!-- Progresso -->
    <div class="pd-progress" id="pdProgress">
        <div class="pd-progress-bar"><div class="pd-progress-fill" id="pdProgressFill"></div></div>
        <div class="pd-status" id="pdStatus">Preparando...</div>
    </div>

    <!-- Resultado -->
    <div id="pdResultado" style="display:none;margin-top:1.5rem;"></div>

    <!-- Planilhas geradas -->
    <?php if (!empty($planilhas)): ?>
    <div class="card pd-list" style="margin-top:1.5rem;">
        <div class="card-header"><h3>Planilhas Geradas</h3></div>
        <table>
            <thead><tr><th>Título</th><th>Processo</th><th>Gerada por</th><th>Data</th><th>Ações</th></tr></thead>
            <tbody>
            <?php foreach ($planilhas as $pl): ?>
            <tr>
                <td style="font-weight:600;"><?= e($pl['titulo'] ?: 'Planilha #' . $pl['id']) ?></td>
                <td style="font-size:.78rem;"><?= e($pl['case_title'] ?: '—') ?></td>
                <td style="font-size:.78rem;"><?= e($pl['user_name'] ?: '—') ?></td>
                <td style="font-size:.78rem;"><?= date('d/m/Y H:i', strtotime($pl['created_at'])) ?></td>
                <td style="white-space:nowrap;">
                    <?php if ($pl['xlsx_path']): ?>
                        <a href="<?= url($pl['xlsx_path']) ?>" class="btn btn-primary btn-sm" style="font-size:.68rem;background:#059669;" download>📥 XLSX</a>
                    <?php endif; ?>
                    <a href="<?= module_url('planilha_debito', 'ver.php?id=' . $pl['id']) ?>" class="btn btn-outline btn-sm" style="font-size:.68rem;" target="_blank">🖨️ PDF</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
var CSRF = '<?= generate_csrf_token() ?>';
var API = '<?= module_url("planilha_debito", "api.php") ?>';

// Drag & drop
var zone = document.getElementById('uploadZone');
zone.addEventListener('dragover', function(e) { e.preventDefault(); zone.classList.add('dragover'); });
zone.addEventListener('dragleave', function() { zone.classList.remove('dragover'); });
zone.addEventListener('drop', function(e) {
    e.preventDefault();
    zone.classList.remove('dragover');
    var files = e.dataTransfer.files;
    if (files.length > 0 && files[0].type === 'application/pdf') {
        document.getElementById('pdfInput').files = files;
        iniciarProcessamento();
    } else {
        alert('Selecione um arquivo PDF.');
    }
});

function iniciarProcessamento() {
    var file = document.getElementById('pdfInput').files[0];
    if (!file) return;
    if (file.type !== 'application/pdf') { alert('Selecione um arquivo PDF.'); return; }
    if (file.size > 10 * 1024 * 1024) { alert('Arquivo muito grande (máx 10MB).'); return; }

    // Mostrar opções e progresso
    document.getElementById('pdOpcoes').style.display = '';
    document.getElementById('pdProgress').style.display = '';
    document.getElementById('pdResultado').style.display = 'none';
    document.getElementById('uploadZone').style.display = 'none';

    setProgress(10, 'Lendo PDF...');

    // Converter para base64
    var reader = new FileReader();
    reader.onload = function() {
        var base64 = reader.result.split(',')[1];
        setProgress(20, 'Enviando para IA (Claude)... pode levar até 1 minuto');

        // Enviar para API
        var fd = new FormData();
        fd.append('action', 'processar_pdf');
        fd.append('csrf_token', CSRF);
        fd.append('pdf_base64', base64);
        fd.append('pdf_name', file.name);
        fd.append('case_id', document.getElementById('pdCaseId').value);
        fd.append('titulo', document.getElementById('pdTitulo').value || file.name.replace('.pdf', ''));

        var xhr = new XMLHttpRequest();
        xhr.open('POST', API);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.timeout = 180000; // 3 min

        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
                var pct = Math.round((e.loaded / e.total) * 30) + 20;
                setProgress(pct, 'Enviando...');
            }
        };

        xhr.onload = function() {
            try {
                var r = JSON.parse(xhr.responseText);
                if (r.csrf) CSRF = r.csrf;
                if (r.error) {
                    setProgress(0, 'Erro: ' + r.error);
                    document.getElementById('uploadZone').style.display = '';
                    return;
                }
                setProgress(100, 'Planilha gerada com sucesso!');
                mostrarResultado(r);
            } catch(e) {
                setProgress(0, 'Erro ao processar: ' + (xhr.responseText || '').substring(0, 200));
                document.getElementById('uploadZone').style.display = '';
            }
        };
        xhr.onerror = function() { setProgress(0, 'Erro de rede.'); document.getElementById('uploadZone').style.display = ''; };
        xhr.ontimeout = function() { setProgress(0, 'Timeout — a IA demorou demais.'); document.getElementById('uploadZone').style.display = ''; };
        xhr.send(fd);
    };
    reader.readAsDataURL(file);
}

function setProgress(pct, msg) {
    document.getElementById('pdProgressFill').style.width = pct + '%';
    document.getElementById('pdStatus').textContent = msg;
}

function mostrarResultado(r) {
    var html = '<div class="card"><div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">'
        + '<h3>Planilha Gerada</h3>'
        + '<div style="display:flex;gap:.5rem;">'
        + (r.xlsx_url ? '<a href="' + r.xlsx_url + '" class="btn btn-primary btn-sm" style="background:#059669;" download>📥 Baixar XLSX</a>' : '')
        + (r.id ? '<a href="' + API.replace('api.php', 'ver.php?id=' + r.id) + '" class="btn btn-outline btn-sm" target="_blank">🖨️ Ver/Imprimir PDF</a>' : '')
        + '<button onclick="location.reload()" class="btn btn-outline btn-sm">Nova planilha</button>'
        + '</div></div>'
        + '<div class="card-body">'
        + '<p style="font-size:.82rem;color:var(--text-muted);">Débito total: <strong style="color:var(--petrol-900);font-size:1rem;">R$ ' + (r.total || '—') + '</strong></p>'
        + '<p style="font-size:.75rem;color:var(--text-muted);">Parcelas: ' + (r.parcelas || '—') + ' · Gerado em ' + (r.gerado_em || '') + '</p>'
        + '</div></div>';
    document.getElementById('pdResultado').innerHTML = html;
    document.getElementById('pdResultado').style.display = '';
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
