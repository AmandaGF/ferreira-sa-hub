<?php
/**
 * Petição Geral com IA — Amanda descreve em linguagem natural
 * e Claude (Sonnet ou Haiku) elabora petição simples no padrão
 * Ferreira & Sá. Preview é editável (contenteditable) antes de
 * baixar DOCX / salvar no Drive.
 *
 * Endpoints (mesmo arquivo):
 *  GET                          — UI principal
 *  POST action=gerar            — chama Claude e devolve {ok, html}
 *  POST action=baixar_docx      — redireciona pro baixar_docx.php
 *  POST action=salvar_drive     — sobe DOCX gerado no Drive do case
 */
require_once __DIR__ . '/../../core/middleware.php';
require_access('documentos');
require_once __DIR__ . '/templates.php';
require_once __DIR__ . '/../../core/functions_ia.php';
require_once __DIR__ . '/../../core/google_drive.php';

$pdo = db();
$pageTitle = 'Petição Geral com IA';

// ─────────────────────────────────────────────────────────────────
// Endpoint AJAX: gerar petição via Claude
// ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'gerar') {
    header('Content-Type: application/json; charset=utf-8');
    if (!validate_csrf()) { echo json_encode(array('ok' => false, 'erro' => 'CSRF inválido.')); exit; }

    $clientId    = (int)($_POST['client_id'] ?? 0);
    $caseId      = (int)($_POST['case_id'] ?? 0);
    $modelo      = (string)($_POST['modelo'] ?? 'sonnet');
    $instrucao   = trim((string)($_POST['instrucao'] ?? ''));
    $varaJuizo   = trim((string)($_POST['vara_juizo'] ?? ''));
    $processoNum = trim((string)($_POST['processo_numero'] ?? ''));

    if (!$clientId)          { echo json_encode(array('ok' => false, 'erro' => 'Cliente obrigatório.')); exit; }
    if (mb_strlen($instrucao) < 20) { echo json_encode(array('ok' => false, 'erro' => 'Descreva melhor o que você quer (mínimo 20 caracteres).')); exit; }

    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute(array($clientId));
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cliente) { echo json_encode(array('ok' => false, 'erro' => 'Cliente não encontrado.')); exit; }

    $caso = null;
    if ($caseId) {
        $st2 = $pdo->prepare("SELECT * FROM cases WHERE id = ?");
        $st2->execute(array($caseId));
        $caso = $st2->fetch(PDO::FETCH_ASSOC);
    }

    // Mapeia modelo amigável → ID Anthropic
    $modeloId = ($modelo === 'haiku')
        ? 'claude-haiku-4-5-20251001'
        : 'claude-sonnet-4-6';

    $esc = escritorioData();

    // Contexto do caso pra IA
    $ctxCaso = array();
    if ($caso) {
        if ($caso['title'])         $ctxCaso[] = "Título do caso: {$caso['title']}";
        if ($caso['case_number'])   $ctxCaso[] = "Nº do processo: {$caso['case_number']}";
        if ($caso['case_type'])     $ctxCaso[] = "Tipo de ação: {$caso['case_type']}";
    }
    if ($varaJuizo)   $ctxCaso[] = "Juízo/Vara: {$varaJuizo}";
    if ($processoNum) $ctxCaso[] = "Nº processo (informado): {$processoNum}";
    if ($cliente['name']) $ctxCaso[] = "Cliente: {$cliente['name']}";
    $contextoTxt = implode("\n", $ctxCaso);

    $systemPrompt =
        "Você é uma advogada experiente do escritório FERREIRA & SÁ ADVOCACIA. "
      . "Sua tarefa é redigir uma PETIÇÃO INTERCORRENTE simples e direta, no padrão do escritório, com base na instrução do usuário e no contexto do caso.\n\n"
      . "── PADRÃO DO ESCRITÓRIO ──\n"
      . "• Petições são objetivas, em português jurídico claro, sem prolixidade.\n"
      . "• Use os fatos do caso e o que foi pedido. Não invente datas, valores ou nomes que não foram fornecidos — quando faltar dado, deixe uma lacuna `[...]` pra revisão humana.\n"
      . "• Estrutura padrão de petição intercorrente:\n"
      . "  1. Endereçamento ao juízo (em negrito, maiúsculas, alinhado à esquerda)\n"
      . "  2. Identificação do processo (número, partes)\n"
      . "  3. Identificação da parte representada — sempre 'PARTE, já qualificada nos autos do processo em epígrafe, por seus advogados infra-assinados, vem respeitosamente à presença de Vossa Excelência, expor e requerer o que segue:'\n"
      . "  4. Breve exposição dos fatos relevantes (1-2 parágrafos no máximo, justificados)\n"
      . "  5. Requerimentos (lista numerada quando houver mais de um, formato 'Diante do exposto, requer:')\n"
      . "  6. Termos em que pede deferimento + cidade/data + assinatura\n\n"
      . "── SAÍDA ──\n"
      . "Retorne EXCLUSIVAMENTE HTML válido (sem markdown, sem ```html). Use apenas estas tags:\n"
      . "  <p style=\"text-align:justify;text-indent:1.5cm;\">parágrafo normal</p>\n"
      . "  <p style=\"text-align:left;text-indent:0;font-weight:700;text-transform:uppercase;\">endereçamento ao juízo</p>\n"
      . "  <p style=\"text-align:center;font-weight:700;text-transform:uppercase;margin-top:24px;\">TÍTULO DA PETIÇÃO</p>\n"
      . "  <p style=\"text-align:justify;text-indent:1.5cm;margin-left:0;\">requerimento (use 'a)', 'b)' como prefixo no início)</p>\n"
      . "  <p style=\"text-align:center;margin-top:32px;\">cidade, dia de mês de ano.</p>\n"
      . "  <p style=\"text-align:center;font-weight:700;\">{$esc['adv1_nome']}<br>OAB/RJ {$esc['adv1_oab']}</p>\n"
      . "  <strong> e <em> são permitidos pra ênfase\n"
      . "NÃO use <div>, <span>, <h1-h6>, <ul>/<ol>, <table>, classes CSS, scripts.\n\n"
      . "── ASSINATURA OBRIGATÓRIA NO FINAL ──\n"
      . "Termine SEMPRE com 'Termos em que, pede deferimento.' centralizado, seguido de cidade/data e da assinatura {$esc['adv1_nome']} OAB/RJ {$esc['adv1_oab']}.";

    $userMsg = "── CONTEXTO DO CASO ──\n{$contextoTxt}\n\n── INSTRUÇÃO DA ADVOGADA ──\n{$instrucao}";

    $resp = ia_chamar(
        'peticao_ia',
        $modeloId,
        $systemPrompt,
        array(array('role' => 'user', 'content' => $userMsg)),
        array('max_tokens' => 2200, 'temperature' => 0.3, 'user_id' => current_user_id(), 'bypass_user_whitelist' => true)
    );

    if (empty($resp['ok']) || empty($resp['texto'])) {
        echo json_encode(array('ok' => false, 'erro' => $resp['erro'] ?? 'Falha ao chamar IA.'));
        exit;
    }

    $html = trim($resp['texto']);
    // Remove code fences acidentais
    $html = preg_replace('/^```(?:html)?\s*/i', '', $html);
    $html = preg_replace('/\s*```\s*$/', '', $html);

    audit_log('peticao_ia_gerada', 'client', $clientId, "Petição IA gerada — modelo={$modelo} caso={$caseId} tokens_in={$resp['input_tokens']} out={$resp['output_tokens']}");

    echo json_encode(array(
        'ok'            => true,
        'html'          => $html,
        'input_tokens'  => $resp['input_tokens'],
        'output_tokens' => $resp['output_tokens'],
        'custo_brl'     => $resp['custo_brl'],
        'modelo'        => $modelo,
    ));
    exit;
}

// ─────────────────────────────────────────────────────────────────
// Endpoint AJAX: salvar DOCX no Drive da pasta do caso
// ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'salvar_drive') {
    header('Content-Type: application/json; charset=utf-8');
    if (!validate_csrf()) { echo json_encode(array('ok' => false, 'erro' => 'CSRF inválido.')); exit; }

    $caseId   = (int)($_POST['case_id'] ?? 0);
    $base64   = (string)($_POST['docx_base64'] ?? '');
    $titulo   = trim((string)($_POST['titulo'] ?? 'peticao'));
    if (!$caseId)  { echo json_encode(array('ok' => false, 'erro' => 'Caso obrigatório pra salvar no Drive.')); exit; }
    if (!$base64)  { echo json_encode(array('ok' => false, 'erro' => 'DOCX vazio.')); exit; }

    $st = $pdo->prepare("SELECT drive_folder_url FROM cases WHERE id = ?");
    $st->execute(array($caseId));
    $folderUrl = $st->fetchColumn();
    if (!$folderUrl) { echo json_encode(array('ok' => false, 'erro' => 'Caso não tem pasta no Drive ainda.')); exit; }

    try {
        $sub = drive_get_or_create_subfolder($folderUrl, '01 - PARA DISTRIBUIR');
        if (empty($sub['ok']) || empty($sub['folderId'])) {
            throw new Exception('Falha ao criar/encontrar subpasta: ' . ($sub['erro'] ?? '?'));
        }
        $folderId = $sub['folderId'];

        // Nome com auto-numeração: "Petição (IA) - <título>.docx"
        $tituloLimpo = preg_replace('/[^\p{L}\p{N}\s\-]/u', '', $titulo);
        $tituloLimpo = mb_substr(trim($tituloLimpo), 0, 60);
        $prefixo = 'Petição (IA) - ' . $tituloLimpo;
        $nomeArquivo = drive_calcular_nome_disponivel($folderId, $prefixo, 'docx');

        $up = upload_file_to_drive_base64($folderId, $nomeArquivo, $base64, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        if (empty($up['ok'])) throw new Exception('Falha no upload: ' . ($up['erro'] ?? '?'));

        audit_log('peticao_ia_drive', 'case', $caseId, "DOCX '{$nomeArquivo}' salvo na subpasta '01 - PARA DISTRIBUIR'");

        echo json_encode(array('ok' => true, 'nome' => $nomeArquivo, 'link' => $up['fileUrl'] ?? null));
    } catch (Exception $e) {
        echo json_encode(array('ok' => false, 'erro' => $e->getMessage()));
    }
    exit;
}

// ─────────────────────────────────────────────────────────────────
// UI — GET
// ─────────────────────────────────────────────────────────────────
$preClientId = (int)($_GET['client_id'] ?? 0);
$preCaseId   = (int)($_GET['case_id'] ?? 0);

$clients = $pdo->query("SELECT id, name, cpf FROM clients ORDER BY name ASC")->fetchAll();

$casos = array();
if ($preClientId) {
    $st = $pdo->prepare("SELECT id, title, case_number FROM cases WHERE client_id = ? ORDER BY updated_at DESC");
    $st->execute(array($preClientId));
    $casos = $st->fetchAll();
}

$csrf = generate_csrf_token();
$iaAtiva = ia_feature_ativa('peticao_ia');

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.pia-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
@media (max-width:900px){ .pia-grid{grid-template-columns:1fr;} }
.pia-campo { margin-bottom:.75rem; }
.pia-campo label { font-size:.78rem; font-weight:700; color:var(--text-muted); display:block; margin-bottom:.25rem; }
.pia-campo input, .pia-campo select, .pia-campo textarea {
    width:100%; padding:.55rem .75rem; font-size:.88rem;
    border:1.5px solid var(--border); border-radius:8px; font-family:inherit;
}
.pia-campo textarea { min-height:200px; resize:vertical; }
.pia-modelo-card {
    display:flex; gap:.5rem; align-items:center;
    padding:.5rem .75rem; border:1.5px solid var(--border);
    border-radius:8px; cursor:pointer; font-size:.85rem;
}
.pia-modelo-card input { width:auto; margin:0; }
.pia-modelo-card.selected { border-color:var(--rose); background:rgba(215,171,144,.1); }
.pia-modelo-custo { font-size:.7rem; color:#64748b; }
.pia-preview {
    background:#fff; border:1px solid var(--border); border-radius:8px;
    padding:60px 50px; min-height:400px; max-height:75vh; overflow-y:auto;
    font-family:Calibri,'Segoe UI',Arial,sans-serif; font-size:12pt; line-height:1.8;
    color:#1A1A1A; outline:none;
}
.pia-preview[contenteditable="true"]:focus { box-shadow:0 0 0 3px rgba(215,171,144,.3); }
.pia-loading { text-align:center; padding:3rem; color:#64748b; }
.pia-loading .spinner { width:32px;height:32px;border:3px solid var(--border);border-top:3px solid var(--petrol-900);border-radius:50%;animation:spin 1s linear infinite;margin:0 auto 1rem; }
@keyframes spin { to { transform:rotate(360deg); } }
.pia-toolbar { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:.5rem; align-items:center; }
.pia-info-box { background:#fef3c7; border:1px solid #fcd34d; border-radius:8px; padding:.6rem .85rem; font-size:.78rem; color:#92400e; margin-bottom:1rem; }
</style>

<a href="<?= module_url('documentos') ?>" class="btn btn-outline btn-sm" style="margin-bottom:.75rem;">← Voltar</a>

<div class="card mb-2">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;">
        <h3>✍️ Petição Geral com IA</h3>
        <span style="font-size:.72rem;color:#64748b;">Modelo simples · Padrão F&S · Edite o resultado antes de baixar</span>
    </div>
    <div class="card-body">

        <?php if (!$iaAtiva): ?>
        <div class="pia-info-box" style="background:#fee2e2;border-color:#fca5a5;color:#991b1b;">
            ⚠️ Feature de IA desligada. Ligar em <code>configuracoes.ia_feature_peticao_ia_enabled</code> = '1'.
        </div>
        <?php endif; ?>

        <div class="pia-info-box">
            <strong>Como usar:</strong> selecione o cliente + caso (opcional, mas ajuda muito a IA), escolha o modelo,
            descreva em português o que você quer na petição (cite documentos, datas, pedidos específicos), e clique em <strong>Gerar</strong>.
            O resultado é editável — ajuste o que precisar antes de baixar.
        </div>

        <form id="piaForm" onsubmit="return false;">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

            <div class="pia-grid">
                <div class="pia-campo">
                    <label>Cliente *</label>
                    <select name="client_id" id="piaCliente" required onchange="piaCarregarCasos(this.value)">
                        <option value="">— Selecione —</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $preClientId === (int)$c['id'] ? 'selected' : '' ?>>
                                <?= e($c['name']) ?><?= $c['cpf'] ? ' — ' . e($c['cpf']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="pia-campo">
                    <label>Caso (opcional, mas recomendado)</label>
                    <select name="case_id" id="piaCaso">
                        <option value="">— Sem caso específico —</option>
                        <?php foreach ($casos as $cs): ?>
                            <option value="<?= $cs['id'] ?>" <?= $preCaseId === (int)$cs['id'] ? 'selected' : '' ?>>
                                <?= e($cs['title']) ?><?= $cs['case_number'] ? ' (' . e($cs['case_number']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="pia-grid">
                <div class="pia-campo">
                    <label>Juízo / Vara (opcional)</label>
                    <input type="text" name="vara_juizo" placeholder="Ex: 3ª Vara Cível da Comarca do Rio de Janeiro/RJ">
                </div>
                <div class="pia-campo">
                    <label>Nº do processo (se diferente do cadastro)</label>
                    <input type="text" name="processo_numero" placeholder="Ex: 0000000-00.0000.8.19.0000">
                </div>
            </div>

            <div class="pia-campo">
                <label>Modelo de IA</label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;">
                    <label class="pia-modelo-card selected" id="piaModSonnet" onclick="piaSelMod('sonnet')">
                        <input type="radio" name="modelo" value="sonnet" checked>
                        <div>
                            <div>🎯 <strong>Sonnet</strong> (recomendado)</div>
                            <div class="pia-modelo-custo">~R$ 0,30-0,80 · qualidade superior</div>
                        </div>
                    </label>
                    <label class="pia-modelo-card" id="piaModHaiku" onclick="piaSelMod('haiku')">
                        <input type="radio" name="modelo" value="haiku">
                        <div>
                            <div>⚡ <strong>Haiku</strong></div>
                            <div class="pia-modelo-custo">~R$ 0,03-0,08 · mais rápido, qualidade menor</div>
                        </div>
                    </label>
                </div>
            </div>

            <div class="pia-campo">
                <label>Descreva o que você quer (instrução pra IA) *</label>
                <textarea name="instrucao" id="piaInstrucao" placeholder="Ex: 'Quero uma petição informando que apesar da parte Ré ter sido intimada, deixou de apresentar os documentos necessários à finalização da perícia, requerendo a intimação do perito para se manifestar sobre a ausência de tais documentos e requerendo prazo de 10 dias pra ré cumprir, sob pena de inversão do ônus.'" required></textarea>
                <div style="font-size:.7rem;color:#64748b;margin-top:.25rem;">Dica: quanto mais específico (datas, documentos, fundamentação que você quer ver citada), melhor o resultado.</div>
            </div>

            <button type="button" class="btn btn-primary" onclick="piaGerar()" id="piaBtnGerar" <?= !$iaAtiva ? 'disabled' : '' ?>>
                ✨ Gerar petição com IA
            </button>
        </form>

        <div id="piaResultArea" style="display:none;margin-top:1.5rem;">
            <hr style="margin:1.5rem 0;">
            <div class="pia-toolbar">
                <strong>Preview editável</strong>
                <span id="piaCusto" style="font-size:.72rem;color:#64748b;"></span>
                <div style="margin-left:auto;display:flex;gap:.5rem;">
                    <button type="button" class="btn btn-outline btn-sm" onclick="piaRegerar()">🔄 Refazer</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="piaBaixarDocx()">📥 Baixar DOCX</button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="piaSalvarDrive()" id="piaBtnDrive">💾 Salvar no Drive</button>
                </div>
            </div>
            <div class="pia-preview" id="piaPreview" contenteditable="true"></div>
        </div>

        <div id="piaLoading" style="display:none;" class="pia-loading">
            <div class="spinner"></div>
            <p>Gerando petição... (pode levar 10-30 segundos)</p>
        </div>
    </div>
</div>

<script>
(function(){
    var CSRF = <?= json_encode($csrf) ?>;
    var BASE = <?= json_encode(rtrim(url(''), '/')) ?>;

    window.piaSelMod = function(m) {
        document.getElementById('piaModSonnet').classList.toggle('selected', m === 'sonnet');
        document.getElementById('piaModHaiku').classList.toggle('selected', m === 'haiku');
        document.querySelector('input[name=modelo][value=' + m + ']').checked = true;
    };

    window.piaCarregarCasos = function(clientId) {
        var sel = document.getElementById('piaCaso');
        sel.innerHTML = '<option value="">— Sem caso específico —</option>';
        if (!clientId) return;
        var x = new XMLHttpRequest();
        x.open('GET', BASE + '/modules/agenda/api.php?action=casos_por_cliente&client_id=' + encodeURIComponent(clientId));
        x.onload = function() {
            try {
                var r = JSON.parse(x.responseText);
                if (r.ok && r.casos) {
                    r.casos.forEach(function(c) {
                        var opt = document.createElement('option');
                        opt.value = c.id;
                        opt.textContent = c.title + (c.case_number ? ' (' + c.case_number + ')' : '');
                        sel.appendChild(opt);
                    });
                }
            } catch(e) {}
        };
        x.send();
    };

    window.piaGerar = function() {
        var form = document.getElementById('piaForm');
        var clientId = form.client_id.value;
        var instrucao = form.instrucao.value.trim();
        if (!clientId) { alert('Selecione um cliente.'); return; }
        if (instrucao.length < 20) { alert('Descreva melhor o que você quer (mínimo 20 caracteres).'); return; }

        document.getElementById('piaBtnGerar').disabled = true;
        document.getElementById('piaLoading').style.display = 'block';
        document.getElementById('piaResultArea').style.display = 'none';

        var fd = new FormData(form);
        fd.append('action', 'gerar');
        fd.append('csrf_token', CSRF);

        var x = new XMLHttpRequest();
        x.open('POST', window.location.pathname);
        x.onload = function() {
            document.getElementById('piaLoading').style.display = 'none';
            document.getElementById('piaBtnGerar').disabled = false;

            if (x.status === 401) { if (window.fsaMostrarSessaoExpirada) window.fsaMostrarSessaoExpirada(); return; }

            try {
                var r = JSON.parse(x.responseText);
                if (!r.ok) { alert('Erro: ' + (r.erro || 'desconhecido')); return; }
                document.getElementById('piaPreview').innerHTML = r.html;
                document.getElementById('piaResultArea').style.display = 'block';
                var custo = r.custo_brl ? ' • Custo: R$ ' + Number(r.custo_brl).toFixed(3).replace('.', ',') : '';
                document.getElementById('piaCusto').textContent = r.modelo.toUpperCase() + ' · ' + r.input_tokens + '→' + r.output_tokens + ' tokens' + custo;
                document.getElementById('piaResultArea').scrollIntoView({behavior:'smooth', block:'start'});
            } catch(e) {
                alert('Resposta inválida do servidor: ' + e.message);
            }
        };
        x.onerror = function() {
            document.getElementById('piaLoading').style.display = 'none';
            document.getElementById('piaBtnGerar').disabled = false;
            alert('Erro de rede. Tente novamente.');
        };
        x.send(fd);
    };

    window.piaRegerar = function() {
        if (confirm('Refazer perde as edições atuais. Continuar?')) piaGerar();
    };

    function piaTituloSugerido() {
        var instr = document.getElementById('piaInstrucao').value.trim();
        var primeiros = instr.split(/\s+/).slice(0, 6).join(' ');
        return primeiros.replace(/[^\p{L}\p{N}\s\-]/gu, '').substring(0, 50) || 'peticao';
    }

    window.piaBaixarDocx = function() {
        var html = document.getElementById('piaPreview').innerHTML;
        var f = document.createElement('form');
        f.method = 'POST';
        f.action = BASE + '/modules/documentos/baixar_docx.php';
        f.target = '_blank';
        var iHtml = document.createElement('input'); iHtml.type='hidden'; iHtml.name='html'; iHtml.value=html; f.appendChild(iHtml);
        var iTit = document.createElement('input'); iTit.type='hidden'; iTit.name='titulo'; iTit.value='Petição IA - ' + piaTituloSugerido(); f.appendChild(iTit);
        document.body.appendChild(f); f.submit();
        setTimeout(function(){ f.remove(); }, 1000);
    };

    window.piaSalvarDrive = function() {
        var caseId = document.getElementById('piaCaso').value;
        if (!caseId) { alert('Pra salvar no Drive, selecione um caso vinculado.'); return; }
        var html = document.getElementById('piaPreview').innerHTML;
        var titulo = piaTituloSugerido();

        // Pra salvar no Drive precisamos do DOCX em base64. Fazemos POST pro baixar_docx
        // com flag pra retornar base64 (vou adicionar suporte lá).
        var btn = document.getElementById('piaBtnDrive');
        btn.disabled = true; btn.textContent = '⏳ Gerando DOCX...';

        var fd = new FormData();
        fd.append('html', html);
        fd.append('titulo', 'Petição IA - ' + titulo);
        fd.append('retornar_base64', '1');

        var x = new XMLHttpRequest();
        x.open('POST', BASE + '/modules/documentos/baixar_docx.php');
        x.onload = function() {
            if (x.status !== 200) {
                btn.disabled = false; btn.textContent = '💾 Salvar no Drive';
                alert('Erro ao gerar DOCX: HTTP ' + x.status);
                return;
            }
            var base64 = null;
            try {
                var r = JSON.parse(x.responseText);
                if (r.ok && r.base64) base64 = r.base64;
            } catch(e) {}
            if (!base64) {
                btn.disabled = false; btn.textContent = '💾 Salvar no Drive';
                alert('Resposta inválida do gerador DOCX.');
                return;
            }
            btn.textContent = '⏳ Subindo no Drive...';
            // Agora envia pro endpoint deste arquivo
            var fd2 = new FormData();
            fd2.append('action', 'salvar_drive');
            fd2.append('csrf_token', CSRF);
            fd2.append('case_id', caseId);
            fd2.append('docx_base64', base64);
            fd2.append('titulo', titulo);
            var x2 = new XMLHttpRequest();
            x2.open('POST', window.location.pathname);
            x2.onload = function() {
                btn.disabled = false; btn.textContent = '💾 Salvar no Drive';
                if (x2.status === 401) { if (window.fsaMostrarSessaoExpirada) window.fsaMostrarSessaoExpirada(); return; }
                try {
                    var r2 = JSON.parse(x2.responseText);
                    if (r2.ok) {
                        alert('✓ Salvo no Drive como "' + r2.nome + '"' + (r2.link ? '\n\n' + r2.link : ''));
                        btn.textContent = '✓ Salvo!';
                        setTimeout(function(){ btn.textContent = '💾 Salvar no Drive'; }, 3000);
                    } else {
                        alert('Erro: ' + (r2.erro || '?'));
                    }
                } catch(e) { alert('Resposta inválida do servidor.'); }
            };
            x2.send(fd2);
        };
        x.send(fd);
    };
})();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
