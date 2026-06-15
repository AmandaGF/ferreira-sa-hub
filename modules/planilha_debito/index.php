<?php
/**
 * Ferreira & Sá Hub — Planilha de Cálculo
 * Aceita PDF (Jusfy/DrCalc), imagem (paste Ctrl+V ou upload) ou texto colado.
 * Claude AI extrai dados → Gera XLSX/PDF com layout FeS.
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Planilha de Cálculo';
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
// Amanda 10/06/2026: clientes pra vincular calculo direto (quando nao tem processo cadastrado)
$clientesLista = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();

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
.pd-tab { background:transparent; border:none; padding:.55rem .9rem; font-size:.82rem; font-weight:600; color:#6b7280; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; }
.pd-tab.pd-tab-ativa { color:#B87333; border-bottom-color:#B87333; background:rgba(184,115,51,.05); border-radius:6px 6px 0 0; }
.pd-tab:hover:not(.pd-tab-ativa) { color:#052228; background:rgba(0,0,0,.03); }
</style>

<div style="max-width:900px;">
    <!-- Bloco "vinculo" no topo (Amanda 10/06/2026: dica visivel) -->
    <div style="font-size:.78rem;color:#0c4a6e;background:#e0f2fe;border:1px solid #7dd3fc;border-radius:8px;padding:.5rem .85rem;margin-bottom:.6rem;">
        💡 <strong>1º passo</strong> (opcional): preencha o processo ou cliente abaixo. <strong>2º passo</strong>: envie o PDF/imagem/texto e a IA processa.
    </div>

    <!-- Caso/cliente vinculado — AGORA NO TOPO pra a Amanda preencher antes do upload -->
    <div id="pdOpcoes" style="margin-bottom:1rem;background:#fff;border:1px solid var(--border);border-radius:10px;padding:.75rem 1rem;">
        <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:end;">
            <div style="flex:1;min-width:200px;">
                <label style="font-size:.75rem;font-weight:700;color:var(--text-muted);display:block;margin-bottom:.2rem;">Vincular a processo (opcional)</label>
                <select id="pdCaseId" class="form-select" style="font-size:.85rem;" onchange="pdAtualizarClienteDoCase(this)">
                    <option value="">— Nenhum —</option>
                    <?php foreach ($cases as $c): ?>
                        <option value="<?= $c['id'] ?>" data-client="<?= (int)($c['client_id'] ?? 0) ?>"><?= e($c['title']) ?><?= $c['case_number'] ? ' — ' . e($c['case_number']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:1;min-width:200px;">
                <label style="font-size:.75rem;font-weight:700;color:var(--text-muted);display:block;margin-bottom:.2rem;">Vincular ao cliente (quando não tem processo)</label>
                <input type="text" id="pdClientBusca" list="pdClientList" class="form-input" style="font-size:.85rem;" placeholder="Digite o nome do cliente..." oninput="pdSelecionarCliente()">
                <datalist id="pdClientList">
                    <?php foreach ($clientesLista as $cl): ?>
                        <option data-id="<?= (int)$cl['id'] ?>" value="<?= e($cl['name']) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <input type="hidden" id="pdClientId" value="">
            </div>
            <div style="min-width:200px;">
                <label style="font-size:.75rem;font-weight:700;color:var(--text-muted);display:block;margin-bottom:.2rem;">Título da planilha</label>
                <input type="text" id="pdTitulo" class="form-input" style="font-size:.85rem;" placeholder="Ex: Débito alimentar Jan/2024 a Mar/2026">
            </div>
        </div>
    </div>

    <!-- Tabs de entrada -->
    <div style="display:flex;gap:.3rem;border-bottom:2px solid var(--border);margin-bottom:1rem;">
        <button type="button" onclick="pdTrocarTab('pdf')" id="pdTabPdf" class="pd-tab pd-tab-ativa">📄 PDF</button>
        <button type="button" onclick="pdTrocarTab('img')" id="pdTabImg" class="pd-tab">🖼️ Imagem (Ctrl+V ou upload)</button>
        <button type="button" onclick="pdTrocarTab('txt')" id="pdTabTxt" class="pd-tab">📋 Colar texto</button>
    </div>

    <!-- Tab PDF -->
    <div id="pdPaneP" class="pd-pane">
        <div class="pd-upload" id="uploadZone" onclick="document.getElementById('pdfInput').click()">
            <div class="pd-upload-icon">📄</div>
            <div class="pd-upload-text">Arraste o PDF da planilha (Jusfy / DrCalc / etc) ou clique para selecionar</div>
            <div class="pd-upload-hint">A IA extrai os dados e gera o XLSX/PDF no layout do escritório</div>
            <input type="file" id="pdfInput" accept=".pdf" style="display:none" onchange="iniciarProcessamento('pdf')">
        </div>
    </div>

    <!-- Tab Imagem -->
    <div id="pdPaneI" class="pd-pane" style="display:none;">
        <div class="pd-upload" id="imgZone" onclick="document.getElementById('imgInput').click()">
            <div class="pd-upload-icon">🖼️</div>
            <div class="pd-upload-text">Cole (Ctrl+V) o print da planilha do DrCalc/site, arraste a imagem ou clique para selecionar</div>
            <div class="pd-upload-hint">Aceita PNG/JPG/JPEG (máx 10MB). Tira print da tela e cola aqui — a IA lê e converte.</div>
            <input type="file" id="imgInput" accept="image/png,image/jpeg,image/jpg" style="display:none" onchange="iniciarProcessamento('img')">
        </div>
        <div id="imgPreview" style="display:none;margin-top:.75rem;text-align:center;">
            <img id="imgPreviewEl" style="max-width:100%;max-height:300px;border:1px solid var(--border);border-radius:8px;">
        </div>
    </div>

    <!-- Tab Texto -->
    <div id="pdPaneT" class="pd-pane" style="display:none;">
        <textarea id="txtColado" class="form-input" rows="14"
            style="width:100%;font-family:ui-monospace,Consolas,monospace;font-size:.78rem;padding:.75rem;"
            placeholder="Cole aqui o texto da planilha do DrCalc, Jusfy ou outro sistema. Ex:&#10;&#10;PLANILHA DE DÉBITOS JUDICIAIS&#10;Data de atualização: junho/2026&#10;Indexador: IPCA (IBGE)&#10;Juros: Taxa Legal-art 406...&#10;&#10;ITEM  DESCRIÇÃO  DATA       VALOR SINGELO  VALOR ATUALIZADO  JUROS  PERÍODO            TOTAL&#10;1               20/04/2026  20.000,00      20.134,00         1.999,16  22/05/2025 a 10/06/2026  22.133,16&#10;TOTAIS         20.000,00      20.134,00         1.999,16            22.133,16"></textarea>
        <button type="button" class="btn btn-primary" style="margin-top:.5rem;" onclick="iniciarProcessamento('txt')">⚡ Processar texto</button>
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
        <div class="card-header"><h3>Cálculos Gerados</h3></div>
        <table>
            <thead><tr><th>Título</th><th>Cliente</th><th>Processo</th><th>Gerada por</th><th>Data</th><th>Ações</th></tr></thead>
            <tbody>
            <?php foreach ($planilhas as $pl): ?>
            <tr>
                <td style="font-weight:600;"><?= e($pl['titulo'] ?: 'Planilha #' . $pl['id']) ?></td>
                <td style="font-size:.78rem;"><?= e($pl['client_name'] ?: '—') ?></td>
                <td style="font-size:.78rem;"><?= e($pl['case_title'] ?: '—') ?></td>
                <td style="font-size:.78rem;"><?= e($pl['user_name'] ?: '—') ?></td>
                <td style="font-size:.78rem;"><?= date('d/m/Y H:i', strtotime($pl['created_at'])) ?></td>
                <td style="white-space:nowrap;">
                    <?php if ($pl['xlsx_path']): ?>
                        <a href="<?= url($pl['xlsx_path']) ?>" class="btn btn-primary btn-sm" style="font-size:.68rem;background:#059669;" download>📥 XLSX</a>
                    <?php endif; ?>
                    <a href="<?= module_url('planilha_debito', 'ver.php?id=' . $pl['id']) ?>" class="btn btn-outline btn-sm" style="font-size:.68rem;" target="_blank">🖨️ PDF</a>
                    <?php
                    // Amanda 15/06/2026: botao 'Salvar no Drive' aparece quando ha
                    // vinculo com processo. Se ja foi salvo, mostra link pra Drive.
                    if (!empty($pl['drive_file_url'])):
                    ?>
                        <a href="<?= e($pl['drive_file_url']) ?>" target="_blank" class="btn btn-sm" style="font-size:.68rem;background:#10b981;color:#fff;border:none;" title="Abrir XLSX salvo no Drive">📁 No Drive</a>
                    <?php elseif (!empty($pl['case_id'])): ?>
                        <button type="button" onclick="pdSalvarNoDrive(<?= (int)$pl['id'] ?>, this)" class="btn btn-sm" style="font-size:.68rem;background:#4285f4;color:#fff;border:none;">📁 Salvar Drive</button>
                    <?php endif; ?>
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
var imgPasted = null; // {base64, name} quando colado por Ctrl+V

function pdTrocarTab(t) {
    ['pdf','img','txt'].forEach(function(k){
        var pane = document.getElementById('pdPane' + k.charAt(0).toUpperCase());
        var tab  = document.getElementById('pdTab' + k.charAt(0).toUpperCase() + k.slice(1));
        if (pane) pane.style.display = (k === t) ? '' : 'none';
        if (tab) tab.classList.toggle('pd-tab-ativa', k === t);
    });
}

// Amanda 10/06/2026: pega o ID do cliente a partir do nome digitado (datalist)
function pdSelecionarCliente() {
    var nome = (document.getElementById('pdClientBusca').value || '').trim().toLowerCase();
    var opts = document.querySelectorAll('#pdClientList option');
    var achouId = '';
    for (var i = 0; i < opts.length; i++) {
        if ((opts[i].value || '').toLowerCase() === nome) {
            achouId = opts[i].dataset.id || '';
            break;
        }
    }
    document.getElementById('pdClientId').value = achouId;
}

// Quando escolher um processo, preenche o cliente automaticamente
function pdAtualizarClienteDoCase(sel) {
    var opt = sel.options[sel.selectedIndex];
    var clientId = opt && opt.dataset ? (opt.dataset.client || '') : '';
    if (clientId && clientId !== '0') {
        document.getElementById('pdClientId').value = clientId;
        // Tenta achar o nome no datalist pra refletir na busca
        var opts = document.querySelectorAll('#pdClientList option');
        for (var i = 0; i < opts.length; i++) {
            if (opts[i].dataset.id === clientId) {
                document.getElementById('pdClientBusca').value = opts[i].value;
                break;
            }
        }
    }
}

// Drag & drop PDF
(function(){
    var zone = document.getElementById('uploadZone');
    if (!zone) return;
    zone.addEventListener('dragover', function(e) { e.preventDefault(); zone.classList.add('dragover'); });
    zone.addEventListener('dragleave', function() { zone.classList.remove('dragover'); });
    zone.addEventListener('drop', function(e) {
        e.preventDefault();
        zone.classList.remove('dragover');
        var files = e.dataTransfer.files;
        if (files.length > 0 && files[0].type === 'application/pdf') {
            document.getElementById('pdfInput').files = files;
            iniciarProcessamento('pdf');
        } else { alert('Selecione um arquivo PDF.'); }
    });
})();

// Drag & drop Imagem
(function(){
    var zone = document.getElementById('imgZone');
    if (!zone) return;
    zone.addEventListener('dragover', function(e) { e.preventDefault(); zone.classList.add('dragover'); });
    zone.addEventListener('dragleave', function() { zone.classList.remove('dragover'); });
    zone.addEventListener('drop', function(e) {
        e.preventDefault();
        zone.classList.remove('dragover');
        var files = e.dataTransfer.files;
        if (files.length > 0 && /^image\/(png|jpe?g)$/i.test(files[0].type)) {
            document.getElementById('imgInput').files = files;
            iniciarProcessamento('img');
        } else { alert('Solte uma imagem PNG ou JPG.'); }
    });
})();

// Ctrl+V global: detecta imagem na area de transferencia
document.addEventListener('paste', function(e) {
    if (!e.clipboardData || !e.clipboardData.items) return;
    // ignora paste em textarea/input que nao seja o nosso txtColado
    var alvo = e.target;
    if (alvo && (alvo.tagName === 'INPUT' || alvo.tagName === 'SELECT')) return;
    for (var i = 0; i < e.clipboardData.items.length; i++) {
        var it = e.clipboardData.items[i];
        if (it.type && it.type.indexOf('image/') === 0) {
            var blob = it.getAsFile();
            if (!blob) continue;
            e.preventDefault();
            pdTrocarTab('img');
            var fr = new FileReader();
            fr.onload = function(){
                imgPasted = { base64: fr.result.split(',')[1], name: 'colado_' + Date.now() + '.png', mime: blob.type };
                var prev = document.getElementById('imgPreviewEl');
                prev.src = fr.result;
                document.getElementById('imgPreview').style.display = '';
                iniciarProcessamento('img');
            };
            fr.readAsDataURL(blob);
            return;
        }
    }
});

function iniciarProcessamento(tipo) {
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    // Amanda 10/06/2026: pega snapshot de vinculo + log + warn se ambos vazios
    var caseIdVal = document.getElementById('pdCaseId').value || '';
    var clientIdVal = document.getElementById('pdClientId').value || '';
    var clientBuscaVal = (document.getElementById('pdClientBusca').value || '').trim();

    // Defesa: cliente digitou nome mas datalist nao bateu — tenta achar parcial
    if (!clientIdVal && clientBuscaVal) {
        var opts = document.querySelectorAll('#pdClientList option');
        for (var i = 0; i < opts.length; i++) {
            if ((opts[i].value || '').toLowerCase() === clientBuscaVal.toLowerCase()) {
                clientIdVal = opts[i].dataset.id || '';
                document.getElementById('pdClientId').value = clientIdVal;
                break;
            }
        }
    }

    // Amanda 15/06/2026: envia tambem o NOME do cliente e o LABEL do caso —
    // backend usa como fallback se os IDs vierem vazios (datalist nao casou
    // exato, etc). Resolve casos onde o select tinha valor mas o JS leu vazio.
    var caseLabelVal = '';
    var caseSel = document.getElementById('pdCaseId');
    if (caseSel && caseSel.selectedIndex > 0) {
        caseLabelVal = (caseSel.options[caseSel.selectedIndex].textContent || '').trim();
    }
    fd.append('case_id', caseIdVal);
    fd.append('client_id', clientIdVal || '0');
    fd.append('case_label', caseLabelVal);
    fd.append('client_nome', clientBuscaVal);

    // Avisa se nada foi vinculado (a Amanda pode confirmar antes de gastar IA)
    if (!caseIdVal && !clientIdVal) {
        var ok = confirm('Você não vinculou a um processo NEM a um cliente — a planilha vai ficar solta (PROCESSO=—). Deseja continuar mesmo assim?');
        if (!ok) return;
    } else if (clientBuscaVal && !clientIdVal) {
        alert('Atenção: o nome "' + clientBuscaVal + '" não bateu com nenhum cliente cadastrado. Clique numa opção da lista (datalist) ou deixe em branco.');
        return;
    }

    console.log('[planilha_calculo] enviando', { tipo: tipo, case_id: caseIdVal, client_id: clientIdVal, client_nome: clientBuscaVal });

    if (tipo === 'pdf') {
        var file = document.getElementById('pdfInput').files[0];
        if (!file) return;
        if (file.type !== 'application/pdf') { alert('Selecione um arquivo PDF.'); return; }
        if (file.size > 10 * 1024 * 1024) { alert('Arquivo muito grande (máx 10MB).'); return; }
        fd.append('action', 'processar_pdf');
        fd.append('pdf_name', file.name);
        fd.append('titulo', document.getElementById('pdTitulo').value || file.name.replace('.pdf', ''));
        var rd = new FileReader();
        rd.onload = function(){ fd.append('pdf_base64', rd.result.split(',')[1]); enviarFD(fd, 'PDF'); };
        rd.readAsDataURL(file);
        prepararUI(); setProgress(10, 'Lendo PDF...');
        return;
    }

    if (tipo === 'img') {
        var file = document.getElementById('imgInput').files[0];
        if (!file && !imgPasted) { alert('Selecione, arraste ou cole uma imagem (Ctrl+V).'); return; }
        prepararUI(); setProgress(10, 'Lendo imagem...');
        fd.append('action', 'processar_imagem');
        if (imgPasted) {
            fd.append('img_base64', imgPasted.base64);
            fd.append('img_mime', imgPasted.mime || 'image/png');
            fd.append('img_name', imgPasted.name);
            fd.append('titulo', document.getElementById('pdTitulo').value || 'Cálculo colado ' + new Date().toLocaleDateString('pt-BR'));
            enviarFD(fd, 'Imagem');
            imgPasted = null;
        } else {
            if (file.size > 10 * 1024 * 1024) { alert('Imagem muito grande (máx 10MB).'); return; }
            var rd2 = new FileReader();
            rd2.onload = function(){
                fd.append('img_base64', rd2.result.split(',')[1]);
                fd.append('img_mime', file.type || 'image/png');
                fd.append('img_name', file.name);
                fd.append('titulo', document.getElementById('pdTitulo').value || file.name.replace(/\.(png|jpe?g)$/i, ''));
                document.getElementById('imgPreviewEl').src = rd2.result;
                document.getElementById('imgPreview').style.display = '';
                enviarFD(fd, 'Imagem');
            };
            rd2.readAsDataURL(file);
        }
        return;
    }

    if (tipo === 'txt') {
        var txt = (document.getElementById('txtColado').value || '').trim();
        if (txt.length < 50) { alert('Cole o texto da planilha (mínimo 50 caracteres).'); return; }
        prepararUI(); setProgress(20, 'Enviando texto para IA...');
        fd.append('action', 'processar_texto');
        fd.append('texto', txt);
        fd.append('titulo', document.getElementById('pdTitulo').value || 'Cálculo colado ' + new Date().toLocaleDateString('pt-BR'));
        enviarFD(fd, 'Texto');
        return;
    }
}

function prepararUI() {
    // pdOpcoes ja fica sempre visivel no topo (Amanda 10/06/2026)
    document.getElementById('pdProgress').style.display = '';
    document.getElementById('pdResultado').style.display = 'none';
}

function enviarFD(fd, qual) {
    setProgress(30, 'Enviando ' + qual.toLowerCase() + ' para IA (Claude)... pode levar até 1 minuto');
    var xhr = new XMLHttpRequest();
    xhr.open('POST', API);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.timeout = 180000;
    xhr.upload.onprogress = function(e) {
        if (e.lengthComputable) {
            var pct = Math.round((e.loaded / e.total) * 30) + 30;
            setProgress(pct, 'Enviando ' + qual.toLowerCase() + '...');
        }
    };
    xhr.onload = function() {
        try {
            var r = JSON.parse(xhr.responseText);
            if (r.csrf) CSRF = r.csrf;
            if (r.error) { setProgress(0, 'Erro: ' + r.error); return; }
            setProgress(100, 'Cálculo gerado com sucesso!');
            mostrarResultado(r);
        } catch(e) {
            setProgress(0, 'Erro ao processar: ' + (xhr.responseText || '').substring(0, 200));
        }
    };
    xhr.onerror = function() { setProgress(0, 'Erro de rede.'); };
    xhr.ontimeout = function() { setProgress(0, 'Timeout — a IA demorou demais.'); };
    xhr.send(fd);
}

function setProgress(pct, msg) {
    document.getElementById('pdProgressFill').style.width = pct + '%';
    document.getElementById('pdStatus').textContent = msg;
}

function mostrarResultado(r) {
    var vincStyle = (r.case_id_salvo || r.client_id_salvo)
        ? 'background:#dcfce7;border-left:3px solid #10b981;color:#14532d;'
        : 'background:#fef3c7;border-left:3px solid #f59e0b;color:#7c2d12;';
    // Amanda 15/06/2026: botao 'Salvar no Drive' so faz sentido se vinculou a processo
    var btnDrive = '';
    if (r.id && r.case_id_salvo) {
        btnDrive = '<button id="pdBtnDrive" onclick="pdSalvarNoDrive(' + r.id + ')" class="btn btn-sm" style="background:#4285f4;color:#fff;border:none;">📁 Salvar no Drive</button>';
    }
    var html = '<div class="card"><div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.4rem;">'
        + '<h3>Cálculo Gerado</h3>'
        + '<div style="display:flex;gap:.5rem;flex-wrap:wrap;">'
        + (r.xlsx_url ? '<a href="' + r.xlsx_url + '" class="btn btn-primary btn-sm" style="background:#059669;" download>📥 Baixar XLSX</a>' : '')
        + (r.id ? '<a href="' + API.replace('api.php', 'ver.php?id=' + r.id) + '" class="btn btn-outline btn-sm" target="_blank">🖨️ Ver/Imprimir PDF</a>' : '')
        + btnDrive
        + '<button onclick="location.reload()" class="btn btn-outline btn-sm">Novo cálculo</button>'
        + '</div></div>'
        + '<div class="card-body">'
        + '<div style="' + vincStyle + 'padding:.5rem .8rem;border-radius:6px;margin-bottom:.6rem;font-size:.8rem;font-weight:600;">'
        + (r.vinculo_txt || '—')
        + '</div>'
        + '<div id="pdDriveStatus" style="display:none;font-size:.78rem;padding:.5rem .8rem;border-radius:6px;margin-bottom:.6rem;"></div>'
        + '<p style="font-size:.82rem;color:var(--text-muted);">Total: <strong style="color:var(--petrol-900);font-size:1rem;">R$ ' + (r.total || '—') + '</strong></p>'
        + '<p style="font-size:.75rem;color:var(--text-muted);">Itens: ' + (r.parcelas || '—') + ' · Gerado em ' + (r.gerado_em || '') + '</p>'
        + '</div></div>';
    document.getElementById('pdResultado').innerHTML = html;
    document.getElementById('pdResultado').style.display = '';
}

// Amanda 15/06/2026: upload do XLSX pra pasta do Drive do caso.
// Pode ser chamado do card resultado (sem btn) OU dos botoes da lista
// 'Calculos Gerados' (passa o proprio btn como 2o arg).
window.pdSalvarNoDrive = function(planilhaId, btnOpcional) {
    var btn = btnOpcional || document.getElementById('pdBtnDrive');
    var status = document.getElementById('pdDriveStatus'); // pode nao existir (botao na lista)
    if (!btn) return;
    var orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ ...';
    if (status) {
        status.style.display = 'block';
        status.style.background = '#dbeafe';
        status.style.color = '#1e3a8a';
        status.style.borderLeft = '3px solid #3b82f6';
        status.innerHTML = '📤 Salvando XLSX na subpasta <strong>Cálculos</strong> da pasta do Drive...';
    }

    var fd = new FormData();
    fd.append('action', 'salvar_drive');
    fd.append('planilha_id', planilhaId);
    fd.append('csrf_token', CSRF);

    fetch(API, { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            btn.disabled = false;
            if (d.csrf) CSRF = d.csrf;
            if (d.error) {
                if (status) {
                    status.style.background = '#fef2f2';
                    status.style.color = '#991b1b';
                    status.style.borderLeftColor = '#dc2626';
                    status.innerHTML = '⚠️ ' + d.error;
                } else {
                    alert('⚠️ ' + d.error);
                }
                btn.innerHTML = orig;
                return;
            }
            if (status) {
                status.style.background = '#dcfce7';
                status.style.color = '#14532d';
                status.style.borderLeftColor = '#10b981';
                status.innerHTML = '✓ Salvo na pasta <strong>' + d.case_title + ' / Cálculos / ' + d.nome_arquivo + '</strong> · '
                                + '<a href="' + d.drive_url + '" target="_blank" style="color:#1e40af;text-decoration:underline;">Abrir no Drive ↗</a>';
            }
            // Converte o botão em link pro Drive
            if (btnOpcional) {
                var a = document.createElement('a');
                a.href = d.drive_url;
                a.target = '_blank';
                a.className = 'btn btn-sm';
                a.style.cssText = 'font-size:.68rem;background:#10b981;color:#fff;border:none;text-decoration:none;display:inline-block;';
                a.title = 'Abrir XLSX salvo no Drive';
                a.textContent = '📁 No Drive';
                btn.parentNode.replaceChild(a, btn);
            } else {
                btn.innerHTML = '✓ No Drive';
                btn.style.background = '#10b981';
            }
        })
        .catch(function(){
            btn.disabled = false;
            btn.innerHTML = orig;
            if (status) {
                status.style.background = '#fef2f2';
                status.style.color = '#991b1b';
                status.innerHTML = '⚠️ Erro de rede. Tente novamente.';
            } else {
                alert('⚠️ Erro de rede. Tente novamente.');
            }
        });
};
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
