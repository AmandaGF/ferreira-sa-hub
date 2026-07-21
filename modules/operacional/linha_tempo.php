<?php
/**
 * Ferreira & Sá Conecta — Editor da Linha do Tempo do Cliente
 *
 * Monta a página narrativa que o cliente recebe por link exclusivo.
 * Fluxo: IA rascunha a partir dos andamentos → Amanda revisa/edita → publica.
 *
 * A página do cliente vive em publico/linha/index.php.
 */

require_once __DIR__ . '/../../core/middleware.php';
require_access('operacional');
require_once APP_ROOT . '/core/functions_linha_tempo.php';
require_once APP_ROOT . '/core/functions_ia.php';

$pdo    = db();
$caseId = isset($_GET['case_id']) ? (int)$_GET['case_id'] : 0;

$stCaso = $pdo->prepare(
    "SELECT c.id, c.title, c.case_number, c.case_type, cl.name AS cliente_nome, cl.cpf AS cliente_cpf
     FROM cases c LEFT JOIN clients cl ON cl.id = c.client_id WHERE c.id = ?"
);
$stCaso->execute(array($caseId));
$_caso = $stCaso->fetch();
if (!$_caso) {
    http_response_code(404);
    $pageTitle = 'Caso não encontrado';
    require_once APP_ROOT . '/templates/layout_start.php';
    echo '<div class="card"><p>Caso não encontrado.</p></div>';
    require_once APP_ROOT . '/templates/layout_end.php';
    exit;
}

lt_self_heal($pdo);
$_tl      = lt_get_or_create($pdo, $caseId, (int)current_user_id());
$_marcos  = lt_marcos($pdo, (int)$_tl['id']);
$_docsPen = lt_docs_pendentes($pdo, $caseId);
$_labels  = lt_tipos_labels();
$_iaOn    = ia_feature_ativa('linha_tempo');
$_urlPub  = lt_url_publica($_tl['token']);

$pageTitle = 'Linha do Tempo — ' . $_caso['title'];
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.lt-wrap { max-width:1000px; }
.lt-bar { display:flex; gap:.6rem; align-items:center; flex-wrap:wrap; margin-bottom:1rem; }
.lt-badge { font-size:.7rem; font-weight:800; text-transform:uppercase; letter-spacing:.4px; padding:.25rem .6rem; border-radius:999px; }
.lt-badge.pub { background:#065f46; color:#fff; }
.lt-badge.rasc { background:#fef3c7; color:#92400e; }
.lt-link-box { display:flex; gap:.4rem; align-items:center; background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; padding:.45rem .6rem; flex:1; min-width:280px; }
.lt-link-box input { flex:1; border:none; background:none; font-family:monospace; font-size:.74rem; color:#334155; outline:none; }
.lt-sec { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:1rem 1.1rem; margin-bottom:1rem; }
.lt-sec > h3 { margin:0 0 .2rem; font-size:.95rem; color:var(--petrol-900); }
.lt-sec > .hint { margin:0 0 .8rem; font-size:.76rem; color:#6b7280; line-height:1.45; }
.lt-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:.8rem; }
.lt-campo { display:flex; flex-direction:column; gap:.25rem; }
.lt-campo label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#6b7280; }
.lt-campo input[type=text], .lt-campo input[type=url], .lt-campo select, .lt-campo textarea {
    border:1px solid #e5e7eb; border-radius:6px; padding:.45rem .55rem; font-size:.85rem; font-family:inherit; width:100%;
}
.lt-campo textarea { resize:vertical; min-height:70px; line-height:1.5; }
.lt-campo input:focus, .lt-campo textarea:focus, .lt-campo select:focus { outline:none; border-color:var(--rose); box-shadow:0 0 0 3px rgba(215,171,144,.25); }

.lt-marco { border:1px solid #e5e7eb; border-left:4px solid #cbd5e1; border-radius:8px; padding:.7rem .85rem; margin-bottom:.6rem; background:#fff; }
.lt-marco.oculto { opacity:.5; background:#f9fafb; }
.lt-marco.destaque { border-left-color:#b45309; background:#fffbeb; }
.lt-marco-head { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; margin-bottom:.5rem; }
.lt-marco-head .arrasta { cursor:grab; color:#9ca3af; font-size:1rem; user-select:none; }
.lt-marco-head .ia-tag { font-size:.62rem; font-weight:800; text-transform:uppercase; letter-spacing:.4px; background:#ede9fe; color:#6d28d9; padding:.12rem .4rem; border-radius:4px; }
.lt-marco-head .man-tag { background:#dcfce7; color:#166534; }
.lt-marco-acoes { margin-left:auto; display:flex; gap:.4rem; align-items:center; }
.lt-chk { display:flex; align-items:center; gap:.3rem; font-size:.74rem; color:#4b5563; cursor:pointer; white-space:nowrap; }
.lt-marco[data-dirty="1"] { border-left-color:#f59e0b; }
.lt-vazio { text-align:center; padding:2rem 1rem; color:#9ca3af; font-size:.85rem; border:1px dashed #d1d5db; border-radius:8px; }

.lt-modal-bg { position:fixed; inset:0; background:rgba(5,34,40,.6); z-index:9000; display:none; align-items:center; justify-content:center; padding:1rem; }
.lt-modal-bg.on { display:flex; }
.lt-modal { background:#fff; border-radius:12px; padding:1.2rem; max-width:560px; width:100%; max-height:88vh; overflow:auto; }
.lt-modal h3 { margin:0 0 .5rem; font-size:1rem; color:var(--petrol-900); }
.lt-modal textarea { width:100%; min-height:200px; border:1px solid #e5e7eb; border-radius:8px; padding:.6rem; font-size:.85rem; font-family:inherit; line-height:1.55; resize:vertical; }
</style>

<div class="lt-wrap">

<a href="<?= e(module_url('operacional', 'caso_ver.php?id=' . $caseId)) ?>#linha_tempo" class="btn btn-outline btn-sm" style="margin-bottom:.8rem;display:inline-block;">← Voltar ao processo</a>

<h2 style="margin:0 0 .15rem;font-size:1.15rem;color:var(--petrol-900);">🕰️ Linha do Tempo do Cliente</h2>
<p style="margin:0 0 1rem;font-size:.8rem;color:#6b7280;">
    <?= e($_caso['title']) ?><?= $_caso['cliente_nome'] ? ' · ' . e($_caso['cliente_nome']) : '' ?>
</p>

<!-- ── Barra de status / link / publicação ────────────────────── -->
<div class="lt-sec">
    <div class="lt-bar">
        <span class="lt-badge <?= (int)$_tl['publicado'] ? 'pub' : 'rasc' ?>" id="ltBadge">
            <?= (int)$_tl['publicado'] ? '● Publicado' : '○ Rascunho' ?>
        </span>
        <div class="lt-link-box">
            <input type="text" id="ltLink" value="<?= e($_urlPub) ?>" readonly onclick="this.select()">
            <button type="button" class="btn btn-outline btn-sm" onclick="ltCopiar()">Copiar</button>
        </div>
        <a href="<?= e($_urlPub) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-sm">Pré-visualizar</a>
    </div>
    <div class="lt-bar" style="margin-bottom:0;">
        <button type="button" class="btn btn-primary btn-sm" id="ltBtnPublicar" onclick="ltPublicar()">
            <?= (int)$_tl['publicado'] ? 'Despublicar' : 'Publicar para o cliente' ?>
        </button>
        <button type="button" class="btn btn-outline btn-sm" onclick="ltEnviarWa()">📱 Enviar no WhatsApp</button>
        <button type="button" class="btn btn-outline btn-sm" onclick="ltRegerar()" title="Invalida o link atual e cria um novo. Use se o link foi parar em quem não devia.">🔄 Gerar link novo</button>
        <span style="margin-left:auto;font-size:.74rem;color:#6b7280;">
            👁 <?= (int)$_tl['visualizacoes'] ?> visualização(ões)<?php
            if (!empty($_tl['ultima_visualizacao'])) echo ' · última em ' . date('d/m/Y H:i', strtotime($_tl['ultima_visualizacao'])); ?>
        </span>
    </div>
    <p style="margin:.7rem 0 0;font-size:.72rem;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:.45rem .6rem;">
        Enquanto estiver em <strong>Rascunho</strong>, o link só abre pra quem está logado no Hub. O cliente vê "página não encontrada".
    </p>
</div>

<!-- ── Rascunho por IA ────────────────────────────────────────── -->
<div class="lt-sec" style="border-color:#ddd6fe;background:#faf5ff;">
    <h3>✨ Rascunho com IA</h3>
    <p class="hint">
        A IA lê os andamentos desta pasta e escreve os marcos em linguagem de leigo, já com painel de situação e próximos passos.
        <strong>Marcos que você editou à mão nunca são sobrescritos</strong> — e os campos de texto que você já preencheu ficam intactos.
    </p>
    <?php if ($_iaOn): ?>
        <button type="button" class="btn btn-primary btn-sm" id="ltBtnIa" onclick="ltGerarIa()">✨ Gerar rascunho com IA</button>
        <span style="font-size:.72rem;color:#6b7280;margin-left:.5rem;">Leva de 20 a 60 segundos.</span>
    <?php else: ?>
        <p style="margin:0;font-size:.8rem;color:#92400e;background:#fef3c7;border:1px solid #fde68a;border-radius:6px;padding:.5rem .7rem;">
            O rascunho por IA está <strong>desligado</strong>.
            Ligue em <a href="<?= e(url('modules/admin/ia_custo.php')) ?>">Admin → Custos de IA</a> →
            "Rascunho da Linha do Tempo do Cliente". Você pode montar os marcos à mão mesmo assim.
        </p>
    <?php endif; ?>
</div>

<!-- ── Abertura ───────────────────────────────────────────────── -->
<form id="ltForm" onsubmit="return false;">
<?= csrf_input() ?>
<input type="hidden" name="case_id" value="<?= (int)$caseId ?>">

<div class="lt-sec">
    <h3>Abertura</h3>
    <p class="hint">É a primeira coisa que o cliente lê. Fale com ele, não sobre ele.</p>
    <div class="lt-campo" style="margin-bottom:.8rem;">
        <label for="ltTitulo">Título da página</label>
        <input type="text" id="ltTitulo" name="titulo" maxlength="200"
               placeholder="Ex: A linha do tempo do processo do Théo"
               value="<?= e($_tl['titulo']) ?>">
    </div>
    <div class="lt-campo">
        <label for="ltLede">Parágrafo de abertura</label>
        <textarea id="ltLede" name="lede" placeholder="Duas ou três frases situando o cliente: o que a gente pediu, quando começou, onde está agora."><?= e($_tl['lede']) ?></textarea>
    </div>
</div>

<!-- ── Painel "onde estamos agora" ────────────────────────────── -->
<div class="lt-sec">
    <h3>Onde estamos agora</h3>
    <p class="hint">Três cartões no topo da página. Deixe em branco o que não se aplica — o cartão vazio some.</p>
    <div class="lt-grid">
        <div class="lt-campo">
            <label for="ltPok" style="color:#065f46;">✓ Já conquistamos</label>
            <textarea id="ltPok" name="painel_ok"><?= e($_tl['painel_ok']) ?></textarea>
        </div>
        <div class="lt-campo">
            <label for="ltPat" style="color:#b45309;">◐ Em andamento</label>
            <textarea id="ltPat" name="painel_atencao"><?= e($_tl['painel_atencao']) ?></textarea>
        </div>
        <div class="lt-campo">
            <label for="ltPac" style="color:#9a3412;">! Exige atenção</label>
            <textarea id="ltPac" name="painel_acao"><?= e($_tl['painel_acao']) ?></textarea>
        </div>
    </div>
</div>

<!-- ── Marcos ─────────────────────────────────────────────────── -->
<div class="lt-sec">
    <h3>Marcos da linha do tempo</h3>
    <p class="hint">
        Arraste pra reordenar. Prefira poucos marcos que mudaram alguma coisa de verdade — o cliente não quer ler cartório.
    </p>
    <div id="ltMarcos">
        <?php foreach ($_marcos as $_m): ?>
            <?php require __DIR__ . '/_linha_tempo_marco.php'; ?>
        <?php endforeach; ?>
    </div>
    <?php if (!$_marcos): ?>
        <div class="lt-vazio" id="ltVazio">
            Nenhum marco ainda. Gere um rascunho com IA acima, ou adicione o primeiro à mão.
        </div>
    <?php endif; ?>
    <button type="button" class="btn btn-outline btn-sm" style="margin-top:.6rem;" onclick="ltNovoMarco()">+ Adicionar marco</button>
</div>

<!-- ── O que precisamos de você ───────────────────────────────── -->
<div class="lt-sec">
    <h3>O que precisamos de você</h3>
    <p class="hint">
        <?php if ($_docsPen): ?>
            Esta pasta tem <strong><?= count($_docsPen) ?></strong> documento(s) pendente(s):
            <?= e(implode(' · ', array_slice($_docsPen, 0, 5))) ?><?= count($_docsPen) > 5 ? ' …' : '' ?>
        <?php else: ?>
            Esta pasta não tem documentos pendentes registrados.
        <?php endif; ?>
    </p>
    <label class="lt-chk" style="margin-bottom:.6rem;">
        <input type="checkbox" id="ltPedAuto" name="pedidos_auto" <?= (int)$_tl['pedidos_auto'] ? 'checked' : '' ?>>
        Puxar automaticamente os documentos pendentes da pasta
    </label>
    <div class="lt-campo">
        <label for="ltPedidos">Ou escreva a lista à mão (um item por linha)</label>
        <textarea id="ltPedidos" name="pedidos" placeholder="Deixe em branco pra usar os documentos pendentes da pasta."><?= e($_tl['pedidos']) ?></textarea>
    </div>
</div>

<!-- ── O que vem pela frente ──────────────────────────────────── -->
<div class="lt-sec">
    <h3>O que vem pela frente</h3>
    <p class="hint">Um passo por linha. Sem prometer resultado nem prazo de vitória.</p>
    <div class="lt-campo">
        <textarea id="ltPassos" name="proximos_passos" style="min-height:100px;"><?= e($_tl['proximos_passos']) ?></textarea>
    </div>
</div>

<!-- ── Vídeo / áudio ──────────────────────────────────────────── -->
<div class="lt-sec">
    <h3>Vídeo ou áudio seu (opcional)</h3>
    <p class="hint">Link de YouTube, Drive ou arquivo direto. Aparece logo abaixo da abertura, como um recado pessoal.</p>
    <div class="lt-grid">
        <div class="lt-campo">
            <label for="ltMidiaUrl">Link (https://)</label>
            <input type="url" id="ltMidiaUrl" name="midia_url" value="<?= e($_tl['midia_url']) ?>" placeholder="https://...">
        </div>
        <div class="lt-campo">
            <label for="ltMidiaTipo">Tipo</label>
            <select id="ltMidiaTipo" name="midia_tipo">
                <option value="video" <?= $_tl['midia_tipo'] === 'video' ? 'selected' : '' ?>>Vídeo</option>
                <option value="audio" <?= $_tl['midia_tipo'] === 'audio' ? 'selected' : '' ?>>Áudio</option>
            </select>
        </div>
        <div class="lt-campo">
            <label for="ltMidiaTit">Legenda</label>
            <input type="text" id="ltMidiaTit" name="midia_titulo" maxlength="200" value="<?= e($_tl['midia_titulo']) ?>" placeholder="Ex: Um recado sobre a audiência">
        </div>
    </div>
</div>

<!-- ── Fecho ──────────────────────────────────────────────────── -->
<div class="lt-sec">
    <h3>Encerramento</h3>
    <p class="hint">Último parágrafo da página. A assinatura "Equipe Ferreira &amp; Sá Advocacia" já aparece no rodapé.</p>
    <div class="lt-campo">
        <textarea id="ltFecho" name="fecho"><?= e($_tl['fecho']) ?></textarea>
    </div>
</div>

<!-- ── Trava ──────────────────────────────────────────────────── -->
<div class="lt-sec">
    <h3>Quem consegue abrir</h3>
    <p class="hint">Com trava por CPF, mesmo que o link seja reencaminhado só quem tem o CPF certo abre. Recomendado para processos em segredo de justiça.</p>
    <div class="lt-grid">
        <div class="lt-campo">
            <label for="ltGate">Trava</label>
            <select id="ltGate" name="gate" onchange="ltToggleGate()">
                <option value="cpf"    <?= $_tl['gate'] === 'cpf'    ? 'selected' : '' ?>>Pedir CPF para abrir</option>
                <option value="aberto" <?= $_tl['gate'] === 'aberto' ? 'selected' : '' ?>>Link aberto, sem senha</option>
            </select>
        </div>
        <div class="lt-campo" id="ltCampoCpf">
            <label for="ltGateCpf">CPF que destrava</label>
            <input type="text" id="ltGateCpf" name="gate_cpf" maxlength="14" inputmode="numeric"
                   value="<?= e($_tl['gate_cpf']) ?>" placeholder="000.000.000-00">
        </div>
        <div class="lt-campo" id="ltCampoLabel">
            <label for="ltGateLabel">Como pedir o CPF na tela de entrada</label>
            <input type="text" id="ltGateLabel" name="gate_label" maxlength="120"
                   value="<?= e($_tl['gate_label']) ?>" placeholder="do cliente cadastrado no processo">
        </div>
    </div>
    <p style="margin:.6rem 0 0;font-size:.72rem;color:#6b7280;">
        A frase fica assim: "Informe o CPF <em>&lt;seu texto&gt;</em> para abrir." Ex: <em>da representante legal</em>.
        <strong>Nunca escreva o nome de ninguém aqui</strong> — esse texto aparece antes de a pessoa se identificar.
        Se quem acompanha o processo é um representante legal, troque também o CPF ao lado.
    </p>
</div>

<div style="position:sticky;bottom:0;background:linear-gradient(transparent,#f8fafc 30%);padding:1rem 0;display:flex;gap:.6rem;align-items:center;">
    <button type="button" class="btn btn-primary" onclick="ltSalvarConfig(true)">Salvar alterações</button>
    <span id="ltStatusSalvo" style="font-size:.76rem;color:#6b7280;"></span>
</div>

</form>
</div>

<!-- ── Modal de envio no WhatsApp ─────────────────────────────── -->
<div class="lt-modal-bg" id="ltWaModal">
    <div class="lt-modal">
        <h3>📱 Enviar linha do tempo no WhatsApp</h3>
        <p style="margin:0 0 .7rem;font-size:.78rem;color:#6b7280;">
            Para <strong id="ltWaCliente"></strong> · <span id="ltWaFone"></span> · canal 24 (Operacional).
            Revise o texto antes de enviar.
        </p>
        <textarea id="ltWaMsg"></textarea>
        <div style="display:flex;gap:.5rem;margin-top:.8rem;justify-content:flex-end;">
            <button type="button" class="btn btn-outline btn-sm" onclick="ltWaFechar()">Cancelar</button>
            <button type="button" class="btn btn-primary btn-sm" id="ltWaBtn" onclick="ltWaEnviar()">Enviar agora</button>
        </div>
    </div>
</div>

<!-- Template de marco novo (clonado pelo JS) -->
<template id="ltTplMarco">
<?php $_m = array('id' => 0, 'data_evento' => '', 'data_label' => '', 'titulo' => '', 'texto' => '',
                  'nota' => '', 'tipo' => 'outro', 'destaque' => 0, 'visivel' => 1,
                  'gerado_ia' => 0, 'editado_manual' => 1);
      require __DIR__ . '/_linha_tempo_marco.php'; ?>
</template>

<script>
(function(){
    var API   = <?= json_encode(module_url('operacional', 'linha_tempo_api.php')) ?>;
    var CASE  = <?= (int)$caseId ?>;
    var CSRF  = <?= json_encode(generate_csrf_token()) ?>;
    var CSRFN = <?= json_encode(CSRF_TOKEN_NAME) ?>;
    var publicado = <?= (int)$_tl['publicado'] ? 'true' : 'false' ?>;

    // ── Chamada padrão: trata 401 de sessão expirada ──────────────
    function post(dados) {
        var fd = new FormData();
        fd.append(CSRFN, CSRF);
        fd.append('case_id', CASE);
        for (var k in dados) {
            if (!dados.hasOwnProperty(k)) continue;
            if (Array.isArray(dados[k])) {
                for (var i = 0; i < dados[k].length; i++) fd.append(k + '[]', dados[k][i]);
            } else {
                fd.append(k, dados[k]);
            }
        }
        return fetch(API, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function(r){
            if (r.status === 401 || r.status === 403) {
                if (window.fsaMostrarSessaoExpirada) window.fsaMostrarSessaoExpirada();
                throw new Error('Sessão expirada — faça login de novo.');
            }
            return r.json().catch(function(){ throw new Error('O servidor respondeu algo que não é JSON.'); });
        }).then(function(j){
            if (!j || !j.ok) throw new Error((j && j.erro) ? j.erro : 'Não consegui salvar.');
            return j;
        });
    }

    function ok(m)  { if (window.FsaFeedback) FsaFeedback.ok(m);   else alert(m); }
    function err(m) { if (window.FsaFeedback) FsaFeedback.erro(m); else alert(m); }

    // ── Cabeçalho / painel / blocos ───────────────────────────────
    var CAMPOS = ['titulo','lede','painel_ok','painel_atencao','painel_acao',
                  'pedidos','proximos_passos','fecho','midia_url','midia_tipo',
                  'midia_titulo','gate','gate_cpf','gate_label'];

    window.ltSalvarConfig = function(avisar) {
        var f = document.getElementById('ltForm');
        var d = {action: 'salvar_config'};
        CAMPOS.forEach(function(n){
            var el = f.querySelector('[name="' + n + '"]');
            if (el) d[n] = el.value;
        });
        d.pedidos_auto = document.getElementById('ltPedAuto').checked ? 1 : 0;

        var st = document.getElementById('ltStatusSalvo');
        st.textContent = 'salvando…';
        return post(d).then(function(){
            st.textContent = 'salvo às ' + new Date().toLocaleTimeString('pt-BR').slice(0,5);
            if (avisar) ok('Alterações salvas.');
        }).catch(function(e){
            st.textContent = '';
            err(e.message);
            throw e;
        });
    };

    // Salva sozinho ao sair do campo — 3 gatilhos redundantes (change/blur/Enter),
    // mesma defesa adotada depois do bug intermitente do Presença.
    document.querySelectorAll('#ltForm [name]').forEach(function(el){
        if (el.type === 'hidden') return;
        var salvar = function(){ window.ltSalvarConfig(false); };
        el.addEventListener('change', salvar);
        el.addEventListener('blur', salvar);
        el.addEventListener('keydown', function(ev){
            if (ev.key === 'Enter' && el.tagName !== 'TEXTAREA') { ev.preventDefault(); salvar(); }
        });
    });

    // Máscara de CPF
    var cpfEl = document.getElementById('ltGateCpf');
    if (cpfEl) cpfEl.addEventListener('input', function(){
        var d = cpfEl.value.replace(/\D/g,'').slice(0,11), s = d;
        if (d.length > 9)      s = d.slice(0,3)+'.'+d.slice(3,6)+'.'+d.slice(6,9)+'-'+d.slice(9);
        else if (d.length > 6) s = d.slice(0,3)+'.'+d.slice(3,6)+'.'+d.slice(6);
        else if (d.length > 3) s = d.slice(0,3)+'.'+d.slice(3);
        cpfEl.value = s;
    });

    window.ltToggleGate = function() {
        var aberto = document.getElementById('ltGate').value === 'aberto';
        document.getElementById('ltCampoCpf').style.display   = aberto ? 'none' : '';
        document.getElementById('ltCampoLabel').style.display = aberto ? 'none' : '';
    };
    window.ltToggleGate();

    // ── Marcos ────────────────────────────────────────────────────
    function dadosMarco(box) {
        return {
            action:      'salvar_marco',
            id:          box.dataset.id || 0,
            titulo:      box.querySelector('[data-f=titulo]').value,
            texto:       box.querySelector('[data-f=texto]').value,
            nota:        box.querySelector('[data-f=nota]').value,
            data_evento: box.querySelector('[data-f=data_evento]').value,
            data_label:  box.querySelector('[data-f=data_label]').value,
            tipo:        box.querySelector('[data-f=tipo]').value,
            destaque:    box.querySelector('[data-f=destaque]').checked ? 1 : 0,
            visivel:     box.querySelector('[data-f=visivel]').checked ? 1 : 0
        };
    }

    window.ltSalvarMarco = function(btnOuBox, silencioso) {
        var box = btnOuBox.closest ? btnOuBox.closest('.lt-marco') : btnOuBox;
        var d = dadosMarco(box);
        if (!d.titulo.trim()) {
            if (!silencioso) err('O marco precisa de um título.');
            return;
        }
        // Trava de reentrância: sem isso, dois gatilhos seguidos num marco novo
        // (ainda sem id) criariam dois registros.
        if (box.dataset.salvando === '1') return;
        box.dataset.salvando = '1';
        box.dataset.dirty = '0';

        return post(d).then(function(j){
            box.dataset.id = j.id;
            box.classList.toggle('oculto', !d.visivel);
            box.classList.toggle('destaque', !!d.destaque);
            var tag = box.querySelector('.ia-tag');
            if (tag) { tag.textContent = 'editado à mão'; tag.classList.add('man-tag'); }
            if (!silencioso) ok('Marco salvo.');
        }).catch(function(e){
            box.dataset.dirty = '1';   // continua sujo pra não perder a edição
            err(e.message);
        }).then(function(){
            box.dataset.salvando = '0';
        });
    };

    window.ltExcluirMarco = function(btn) {
        var box = btn.closest('.lt-marco');
        var titulo = box.querySelector('[data-f=titulo]').value || 'este marco';
        if (!confirm('Excluir "' + titulo + '"? Não dá pra desfazer.')) return;
        var id = parseInt(box.dataset.id || '0', 10);
        if (!id) { box.remove(); return; }
        post({action: 'excluir_marco', id: id}).then(function(){
            box.remove();
            ok('Marco excluído.');
        }).catch(function(e){ err(e.message); });
    };

    window.ltMarcoMudou = function(el) {
        var box = el.closest('.lt-marco');
        box.dataset.dirty = '1';
    };

    // Salva sozinho ao sair de um marco sujo — ninguém perde texto por ter
    // rolado a página sem clicar em Salvar (mesma defesa do editor acima).
    document.addEventListener('focusout', function(ev){
        var box = ev.target.closest ? ev.target.closest('.lt-marco') : null;
        if (!box || box.dataset.dirty !== '1') return;
        // Só salva quando o foco realmente saiu do marco inteiro
        setTimeout(function(){
            if (box.contains(document.activeElement)) return;
            if (box.dataset.dirty !== '1') return;
            if (!box.querySelector('[data-f=titulo]').value.trim()) return;
            window.ltSalvarMarco(box, true);
        }, 60);
    }, true);

    // Enter salva o marco (menos no textarea, onde quebra linha)
    document.addEventListener('keydown', function(ev){
        if (ev.key !== 'Enter' || ev.target.tagName === 'TEXTAREA') return;
        var box = ev.target.closest ? ev.target.closest('.lt-marco') : null;
        if (!box) return;
        ev.preventDefault();
        window.ltSalvarMarco(box);
    });

    window.ltNovoMarco = function() {
        var tpl = document.getElementById('ltTplMarco');
        var novo = tpl.content.firstElementChild.cloneNode(true);
        novo.dataset.id = '0';
        document.getElementById('ltMarcos').appendChild(novo);
        var vazio = document.getElementById('ltVazio');
        if (vazio) vazio.remove();
        novo.querySelector('[data-f=titulo]').focus();
        novo.scrollIntoView({behavior:'smooth', block:'center'});
    };

    // Arrastar pra reordenar (HTML5 drag nativo — sem biblioteca)
    var arrastando = null;
    var lista = document.getElementById('ltMarcos');
    lista.addEventListener('dragstart', function(e){
        var box = e.target.closest('.lt-marco');
        if (!box) return;
        arrastando = box;
        box.style.opacity = '.4';
    });
    lista.addEventListener('dragend', function(){
        if (arrastando) arrastando.style.opacity = '';
        arrastando = null;
        ltReordenar();
    });
    lista.addEventListener('dragover', function(e){
        e.preventDefault();
        if (!arrastando) return;
        var alvo = e.target.closest('.lt-marco');
        if (!alvo || alvo === arrastando) return;
        var r = alvo.getBoundingClientRect();
        var depois = (e.clientY - r.top) > r.height / 2;
        lista.insertBefore(arrastando, depois ? alvo.nextSibling : alvo);
    });

    function ltReordenar() {
        var ids = [];
        lista.querySelectorAll('.lt-marco').forEach(function(b){
            var id = parseInt(b.dataset.id || '0', 10);
            if (id) ids.push(id);
        });
        if (ids.length < 2) return;
        post({action: 'reordenar', ids: ids}).catch(function(e){ err(e.message); });
    }

    // ── IA ────────────────────────────────────────────────────────
    window.ltGerarIa = function() {
        var btn = document.getElementById('ltBtnIa');
        if (!confirm('Gerar rascunho com IA?\n\nOs marcos que você editou à mão e os textos que já preencheu ficam intactos.')) return;
        btn.disabled = true;
        btn.textContent = '✨ Lendo a pasta e escrevendo…';
        post({action: 'gerar_ia'}).then(function(j){
            ok(j.msg);
            location.reload();
        }).catch(function(e){
            btn.disabled = false;
            btn.textContent = '✨ Gerar rascunho com IA';
            err(e.message);
        });
    };

    // ── Publicação ────────────────────────────────────────────────
    window.ltPublicar = function() {
        var btn = document.getElementById('ltBtnPublicar');
        var acao = publicado ? 'despublicar' : 'publicar';
        if (acao === 'despublicar' && !confirm('Despublicar? O link vai parar de abrir pro cliente na hora.')) return;

        // Garante que o que está na tela foi gravado antes de publicar
        window.ltSalvarConfig(false).then(function(){
            btn.disabled = true;
            return post({action: acao});
        }).then(function(j){
            publicado = (acao === 'publicar');
            btn.disabled = false;
            btn.textContent = publicado ? 'Despublicar' : 'Publicar para o cliente';
            var b = document.getElementById('ltBadge');
            b.textContent = publicado ? '● Publicado' : '○ Rascunho';
            b.className = 'lt-badge ' + (publicado ? 'pub' : 'rasc');
            ok(j.msg);
        }).catch(function(e){
            btn.disabled = false;
            err(e.message);
        });
    };

    window.ltRegerar = function() {
        if (!confirm('Gerar um link novo?\n\nO link atual para de funcionar imediatamente — quem já recebeu não consegue mais abrir.')) return;
        post({action: 'regerar_token'}).then(function(j){
            document.getElementById('ltLink').value = j.url;
            ok(j.msg);
        }).catch(function(e){ err(e.message); });
    };

    window.ltCopiar = function() {
        var el = document.getElementById('ltLink');
        el.select();
        try { document.execCommand('copy'); ok('Link copiado.'); }
        catch (e) { err('Não consegui copiar — selecione e copie à mão.'); }
    };

    // ── WhatsApp ──────────────────────────────────────────────────
    var waFone = '';
    window.ltEnviarWa = function() {
        post({action: 'preview_whatsapp'}).then(function(j){
            waFone = j.telefone;
            document.getElementById('ltWaCliente').textContent = j.cliente;
            document.getElementById('ltWaFone').textContent    = j.telefone;
            document.getElementById('ltWaMsg').value           = j.mensagem;
            document.getElementById('ltWaModal').classList.add('on');
        }).catch(function(e){ err(e.message); });
    };
    window.ltWaFechar = function() { document.getElementById('ltWaModal').classList.remove('on'); };
    window.ltWaEnviar = function() {
        var btn = document.getElementById('ltWaBtn');
        btn.disabled = true;
        btn.textContent = 'Enviando…';
        post({action: 'enviar_whatsapp', mensagem: document.getElementById('ltWaMsg').value, telefone: waFone})
            .then(function(j){
                ltWaFechar();
                ok(j.msg);
            })
            .catch(function(e){ err(e.message); })
            .then(function(){ btn.disabled = false; btn.textContent = 'Enviar agora'; });
    };
})();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
