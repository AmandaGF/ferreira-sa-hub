<?php
/**
 * Newsletter — Criar/Editar Campanha (Wizard 3 passos)
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('formularios');

$pageTitle = 'Nova Campanha';
$pdo = db();
$campId = (int)($_GET['id'] ?? 0);
$camp = null;
if ($campId) {
    $stmt = $pdo->prepare("SELECT * FROM newsletter_campanhas WHERE id = ?");
    $stmt->execute(array($campId));
    $camp = $stmt->fetch();
    if ($camp) $pageTitle = 'Editar: ' . $camp['titulo'];
}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.wiz-steps{display:flex;gap:0;margin-bottom:1.5rem;border-bottom:2px solid var(--border)}
.wiz-step{padding:.6rem 1.2rem;font-size:.82rem;font-weight:600;color:var(--text-muted);cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px}
.wiz-step.ativo{color:var(--petrol-900);border-bottom-color:#B87333}
.wiz-panel{display:none}.wiz-panel.ativo{display:block}
.nl-fg{margin-bottom:1rem}.nl-fl{display:block;font-size:.72rem;font-weight:600;color:var(--text-muted);margin-bottom:.2rem;text-transform:uppercase;letter-spacing:.3px}
.nl-fi{width:100%;padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:.85rem;background:var(--bg-card);color:var(--text)}
.nl-fi:focus{border-color:#B87333;outline:none}
.nl-fr{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.nl-tpl-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:.6rem}
.nl-tpl-btn{padding:.8rem;border:1.5px solid var(--border);border-radius:10px;background:none;cursor:pointer;text-align:center;transition:all .2s;font-size:.78rem}
.nl-tpl-btn:hover{border-color:#B87333}.nl-tpl-btn.sel{background:#052228;color:#fff;border-color:#052228}
.nl-tpl-emoji{font-size:1.4rem;display:block;margin-bottom:.3rem}
.nl-preview{border:1.5px solid var(--border);border-radius:10px;min-height:300px;overflow:auto;background:#fff;padding:0}
.nl-preview iframe{width:100%;height:400px;border:none}
.nl-dest-info{padding:.8rem;background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:8px;margin-top:.5rem;font-size:.82rem}
.nl-dest-lista{max-height:200px;overflow-y:auto;font-size:.78rem;margin-top:.5rem}
.nl-actions{display:flex;gap:.5rem;justify-content:space-between;margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--border)}
</style>

<a href="<?= module_url('newsletter') ?>" class="btn btn-outline btn-sm" style="margin-bottom:1rem;">&larr; Voltar</a>

<!-- Steps -->
<div class="wiz-steps">
    <div class="wiz-step ativo" onclick="irPasso(1)">1. Configurar</div>
    <div class="wiz-step" onclick="irPasso(2)">2. Segmentar</div>
    <div class="wiz-step" onclick="irPasso(3)">3. Enviar</div>
</div>

<input type="hidden" id="campId" value="<?= $campId ?>">

<!-- PASSO 1: Configurar -->
<div class="wiz-panel ativo" id="passo1">
    <div class="nl-fr">
        <div class="nl-fg"><label class="nl-fl">Titulo interno</label><input type="text" class="nl-fi" id="nlTitulo" value="<?= e($camp ? $camp['titulo'] : '') ?>" placeholder="Ex: Newsletter Abril 2026"></div>
        <div class="nl-fg"><label class="nl-fl">Assunto do e-mail</label><input type="text" class="nl-fi" id="nlAssunto" value="<?= e($camp ? $camp['assunto'] : '') ?>" placeholder="Ex: Novidades jurídicas - Abril"></div>
    </div>

    <div class="nl-fg">
        <label class="nl-fl">Template</label>
        <div class="nl-tpl-grid" style="grid-template-columns:repeat(3,1fr);">
            <button type="button" class="nl-tpl-btn <?= (!$camp || $camp['template_tipo']==='informativo') ? 'sel' : '' ?>" data-tpl="informativo" onclick="selTemplate(this)"><span class="nl-tpl-emoji">📰</span>Informativo</button>
            <button type="button" class="nl-tpl-btn <?= ($camp && $camp['template_tipo']==='felicitacoes') ? 'sel' : '' ?>" data-tpl="felicitacoes" onclick="selTemplate(this)"><span class="nl-tpl-emoji">🎉</span>Felicitacoes</button>
            <button type="button" class="nl-tpl-btn <?= ($camp && $camp['template_tipo']==='novidades') ? 'sel' : '' ?>" data-tpl="novidades" onclick="selTemplate(this)"><span class="nl-tpl-emoji">📢</span>Novidades</button>
            <button type="button" class="nl-tpl-btn <?= ($camp && $camp['template_tipo']==='pesquisa') ? 'sel' : '' ?>" data-tpl="pesquisa" onclick="selTemplate(this)"><span class="nl-tpl-emoji">📋</span>Pesquisa</button>
            <button type="button" class="nl-tpl-btn <?= ($camp && $camp['template_tipo']==='natal') ? 'sel' : '' ?>" data-tpl="natal" onclick="selTemplate(this)"><span class="nl-tpl-emoji">🎄</span>Natal</button>
            <button type="button" class="nl-tpl-btn <?= ($camp && $camp['template_tipo']==='ano_novo') ? 'sel' : '' ?>" data-tpl="ano_novo" onclick="selTemplate(this)"><span class="nl-tpl-emoji">🎆</span>Ano Novo</button>
            <button type="button" class="nl-tpl-btn <?= ($camp && $camp['template_tipo']==='pascoa') ? 'sel' : '' ?>" data-tpl="pascoa" onclick="selTemplate(this)"><span class="nl-tpl-emoji">🐣</span>Pascoa</button>
            <button type="button" class="nl-tpl-btn <?= ($camp && $camp['template_tipo']==='dia_maes') ? 'sel' : '' ?>" data-tpl="dia_maes" onclick="selTemplate(this)"><span class="nl-tpl-emoji">💐</span>Dia das Maes</button>
            <button type="button" class="nl-tpl-btn <?= ($camp && $camp['template_tipo']==='dia_pais') ? 'sel' : '' ?>" data-tpl="dia_pais" onclick="selTemplate(this)"><span class="nl-tpl-emoji">👔</span>Dia dos Pais</button>
        </div>
    </div>

    <div class="nl-fr">
        <div class="nl-fg">
            <label class="nl-fl">Conteudo do e-mail (HTML)</label>
            <div style="display:flex;gap:.5rem;margin-bottom:.4rem;">
                <label style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;background:#052228;color:#fff;border-radius:6px;font-size:.72rem;font-weight:600;cursor:pointer;">
                    <input type="file" id="nlUploadImg" accept="image/*" style="display:none;" onchange="uploadImagem(this)"> Inserir imagem
                </label>
                <span id="nlUploadStatus" style="font-size:.7rem;color:var(--text-muted);display:flex;align-items:center;"></span>
            </div>
            <textarea class="nl-fi" id="nlConteudo" rows="12" style="font-family:monospace;font-size:.78rem;" placeholder="Escreva o conteúdo aqui..."><?= e($camp ? $camp['conteudo_html'] : '') ?></textarea>
            <p style="font-size:.65rem;color:var(--text-muted);margin-top:.2rem;">Tamanho ideal para banner: 600x250px (Canva: dimensao personalizada). Max 2MB.</p>
        </div>
        <div class="nl-fg">
            <label class="nl-fl">Preview</label>
            <div class="nl-preview"><iframe id="nlPreviewFrame"></iframe></div>
        </div>
    </div>

    <div class="nl-actions">
        <div></div>
        <div style="display:flex;gap:.5rem;">
            <button onclick="salvarRascunho()" class="btn btn-outline btn-sm">Salvar rascunho</button>
            <button onclick="irPasso(2)" class="btn btn-primary btn-sm">Proximo: Segmentar &rarr;</button>
        </div>
    </div>
</div>

<!-- PASSO 2: Segmentar -->
<div class="wiz-panel" id="passo2">
    <div class="nl-fr">
        <div class="nl-fg">
            <label class="nl-fl">Segmento</label>
            <select class="nl-fi" id="nlSegmento" onchange="mudouSegmento()">
                <option value="todos">Todos os clientes com e-mail</option>
                <option value="tipo_acao" <?= ($camp && $camp['segmento']==='tipo_acao') ? 'selected' : '' ?>>Por tipo de acao</option>
                <option value="status_processo" <?= ($camp && $camp['segmento']==='status_processo') ? 'selected' : '' ?>>Por status do processo</option>
                <option value="aniversariantes" <?= ($camp && $camp['segmento']==='aniversariantes') ? 'selected' : '' ?>>Aniversariantes do mes</option>
            </select>
        </div>
        <div class="nl-fg" id="nlFiltroBox" style="display:none;">
            <label class="nl-fl" id="nlFiltroLabel">Filtro</label>
            <select class="nl-fi" id="nlFiltro" onchange="contarDest()"></select>
        </div>
    </div>

    <div id="nlDestInfo" class="nl-dest-info" style="display:none;">
        <strong id="nlDestCount">0</strong> destinatarios encontrados
        <button onclick="verLista()" class="btn btn-outline btn-sm" style="font-size:.72rem;margin-left:.5rem;">Ver lista</button>
        <div id="nlDestLista" class="nl-dest-lista" style="display:none;"></div>
    </div>

    <div class="nl-actions">
        <button onclick="irPasso(1)" class="btn btn-outline btn-sm">&larr; Voltar</button>
        <button onclick="irPasso(3)" class="btn btn-primary btn-sm">Proximo: Enviar &rarr;</button>
    </div>
</div>

<!-- PASSO 3: Enviar -->
<div class="wiz-panel" id="passo3">
    <div class="card" style="padding:1.5rem;text-align:center;">
        <h3 style="margin:0 0 .5rem;">Pronto para enviar?</h3>
        <p style="color:var(--text-muted);font-size:.85rem;" id="nlResumo">Salve a campanha primeiro.</p>

        <div class="nl-fr" style="max-width:400px;margin:1rem auto;">
            <div class="nl-fg">
                <label class="nl-fl">Agendar para</label>
                <input type="datetime-local" class="nl-fi" id="nlAgendar">
            </div>
            <div class="nl-fg" style="display:flex;align-items:end;">
                <label style="font-size:.75rem;color:var(--text-muted);">Ou enviar agora</label>
            </div>
        </div>

        <div style="display:flex;gap:.5rem;justify-content:center;margin-top:1rem;">
            <button onclick="enviarTeste()" class="btn btn-outline btn-sm">Enviar teste (para mim)</button>
            <button onclick="enviarCampanha(false)" class="btn btn-primary btn-sm" style="background:#059669;">Enviar agora</button>
            <button onclick="enviarCampanha(true)" class="btn btn-primary btn-sm" style="background:#d97706;">Agendar</button>
        </div>
        <div id="nlEnvioMsg" style="display:none;margin-top:1rem;padding:.5rem;border-radius:6px;font-size:.82rem;"></div>
    </div>

    <div class="nl-actions">
        <button onclick="irPasso(2)" class="btn btn-outline btn-sm">&larr; Voltar</button>
        <div></div>
    </div>
</div>

<script>
var API = '<?= module_url("newsletter", "api.php") ?>';
var CSRF = '<?= generate_csrf_token() ?>';
var tplSel = '<?= $camp ? e($camp['template_tipo']) : 'informativo' ?>';

// Templates HTML padrão
var TEMPLATES = {
    informativo: '<div style="background:#052228;padding:24px;text-align:center;">'
        + '<img src="https://ferreiraesa.com.br/conecta/assets/img/logo.png" style="max-width:250px;" alt="Ferreira e Sa">'
        + '</div>'
        + '<div style="padding:24px 32px;font-family:Calibri,sans-serif;color:#1a1a1a;line-height:1.7;">'
        + '<h2 style="color:#052228;border-bottom:3px solid #B87333;padding-bottom:8px;">Informativo Juridico</h2>'
        + '<p>Ola, [nome]!</p>'
        + '<p>Escreva aqui o conteudo do informativo...</p>'
        + '<p style="margin-top:24px;">Atenciosamente,<br><strong style="color:#052228;">Equipe Ferreira &amp; Sa Advocacia</strong></p>'
        + '</div>',
    felicitacoes: '<div style="background:linear-gradient(135deg,#052228,#0d3640);padding:32px;text-align:center;color:#fff;">'
        + '<div style="font-size:48px;margin-bottom:8px;">🎉</div>'
        + '<h1 style="margin:0;font-family:Calibri;color:#D7AB90;">Parabens!</h1>'
        + '</div>'
        + '<div style="padding:24px 32px;font-family:Calibri,sans-serif;color:#1a1a1a;line-height:1.7;text-align:center;">'
        + '<p style="font-size:18px;">Ola, <strong>[nome]</strong>!</p>'
        + '<p>Desejamos um dia repleto de alegrias e realizacoes!</p>'
        + '<p style="margin-top:24px;color:#B87333;font-weight:600;">Equipe Ferreira &amp; Sa Advocacia</p>'
        + '</div>',
    novidades: '<div style="background:#052228;padding:24px;text-align:center;">'
        + '<img src="https://ferreiraesa.com.br/conecta/assets/img/logo.png" style="max-width:250px;" alt="Ferreira e Sa">'
        + '</div>'
        + '<div style="padding:24px 32px;font-family:Calibri,sans-serif;color:#1a1a1a;line-height:1.7;">'
        + '<h2 style="color:#052228;">Novidades do Escritorio</h2>'
        + '<div style="background:#f8f8f6;border-left:4px solid #B87333;padding:16px;margin:16px 0;border-radius:0 8px 8px 0;">'
        + '<h3 style="margin:0 0 8px;color:#052228;">Titulo da noticia</h3>'
        + '<p style="margin:0;">Escreva aqui a novidade juridica...</p>'
        + '</div>'
        + '<div style="text-align:center;margin:24px 0;">'
        + '<a href="https://wa.me/5524992050096" style="background:#B87333;color:#fff;padding:12px 32px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;">Fale conosco</a>'
        + '</div></div>',
    pesquisa: '<div style="background:#052228;padding:24px;text-align:center;">'
        + '<img src="https://ferreiraesa.com.br/conecta/assets/img/logo.png" style="max-width:250px;" alt="Ferreira e Sa">'
        + '</div>'
        + '<div style="padding:24px 32px;font-family:Calibri,sans-serif;color:#1a1a1a;line-height:1.7;">'
        + '<h2 style="color:#052228;">Queremos ouvir voce!</h2>'
        + '<p>Ola, [nome]! Sua opiniao e muito importante para nos.</p>'
        + '<p>Responda nossa pesquisa rapida (menos de 2 minutos):</p>'
        + '<div style="text-align:center;margin:24px 0;">'
        + '<a href="LINK_DO_FORMULARIO" style="background:#052228;color:#fff;padding:14px 40px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;">Responder Pesquisa</a>'
        + '</div>'
        + '<p style="font-size:13px;color:#888;">Suas respostas sao confidenciais.</p></div>',

    natal: '<div style="background:linear-gradient(135deg,#0a3d2e,#052228);padding:40px 24px;text-align:center;color:#fff;">'
        + '<div style="font-size:56px;margin-bottom:8px;">🎄</div>'
        + '<h1 style="margin:0;font-family:Calibri;color:#D7AB90;font-size:28px;">Feliz Natal!</h1>'
        + '</div>'
        + '<div style="padding:32px;font-family:Calibri,sans-serif;color:#1a1a1a;line-height:1.8;text-align:center;">'
        + '<p style="font-size:16px;">Ola, <strong>[nome]</strong>!</p>'
        + '<p>Que este Natal traga paz, saude e muitas bencaos para voce e sua familia.</p>'
        + '<p>Agradecemos pela confianca depositada em nosso trabalho ao longo deste ano.</p>'
        + '<div style="margin:24px 0;padding:16px;background:#f8f5f0;border-radius:10px;border-left:4px solid #B87333;">'
        + '<p style="margin:0;font-style:italic;color:#052228;">Que o espirito natalino ilumine seus caminhos e renove suas esperancas.</p>'
        + '</div>'
        + '<p style="color:#B87333;font-weight:700;font-size:15px;">Equipe Ferreira &amp; Sa Advocacia</p>'
        + '<p style="font-size:12px;color:#94a3b8;">Estaremos em recesso de 23/12 a 02/01. Retorno em 03/01.</p>'
        + '</div>',

    ano_novo: '<div style="background:linear-gradient(135deg,#052228,#1a3a7a);padding:40px 24px;text-align:center;color:#fff;">'
        + '<div style="font-size:56px;margin-bottom:8px;">🎆</div>'
        + '<h1 style="margin:0;font-family:Calibri;color:#D7AB90;font-size:28px;">Feliz Ano Novo!</h1>'
        + '</div>'
        + '<div style="padding:32px;font-family:Calibri,sans-serif;color:#1a1a1a;line-height:1.8;text-align:center;">'
        + '<p style="font-size:16px;">Ola, <strong>[nome]</strong>!</p>'
        + '<p>Desejamos um novo ano repleto de conquistas, saude e prosperidade!</p>'
        + '<p>Agradecemos por fazer parte da nossa historia. Seguimos juntos nessa caminhada.</p>'
        + '<div style="margin:24px 0;padding:16px;background:#f0f4ff;border-radius:10px;border-left:4px solid #1a3a7a;">'
        + '<p style="margin:0;font-style:italic;color:#052228;">Que 2027 seja o ano das grandes realizacoes!</p>'
        + '</div>'
        + '<p style="color:#B87333;font-weight:700;font-size:15px;">Equipe Ferreira &amp; Sa Advocacia</p>'
        + '</div>',

    pascoa: '<div style="background:linear-gradient(135deg,#f5e6d3,#fff);padding:40px 24px;text-align:center;">'
        + '<div style="font-size:56px;margin-bottom:8px;">🐣</div>'
        + '<h1 style="margin:0;font-family:Calibri;color:#052228;font-size:28px;">Feliz Pascoa!</h1>'
        + '</div>'
        + '<div style="padding:32px;font-family:Calibri,sans-serif;color:#1a1a1a;line-height:1.8;text-align:center;">'
        + '<p style="font-size:16px;">Ola, <strong>[nome]</strong>!</p>'
        + '<p>Que esta Pascoa renove suas esperancas e traga momentos de uniao com quem voce ama.</p>'
        + '<div style="margin:24px 0;padding:16px;background:#fef9f0;border-radius:10px;border-left:4px solid #B87333;">'
        + '<p style="margin:0;font-style:italic;color:#052228;">A Pascoa nos lembra que sempre ha renascimento apos os desafios.</p>'
        + '</div>'
        + '<p style="color:#B87333;font-weight:700;font-size:15px;">Equipe Ferreira &amp; Sa Advocacia</p>'
        + '</div>',

    dia_maes: '<div style="background:linear-gradient(135deg,#fce4ec,#fff);padding:40px 24px;text-align:center;">'
        + '<div style="font-size:56px;margin-bottom:8px;">💐</div>'
        + '<h1 style="margin:0;font-family:Calibri;color:#052228;font-size:28px;">Feliz Dia das Maes!</h1>'
        + '</div>'
        + '<div style="padding:32px;font-family:Calibri,sans-serif;color:#1a1a1a;line-height:1.8;text-align:center;">'
        + '<p style="font-size:16px;">Ola, <strong>[nome]</strong>!</p>'
        + '<p>Neste dia tao especial, queremos homenagear todas as maes que confiam em nosso trabalho.</p>'
        + '<p>Voces sao a forca que move o mundo. Obrigado por nos permitir cuidar do que e mais importante para voces.</p>'
        + '<div style="margin:24px 0;padding:16px;background:#fdf2f8;border-radius:10px;border-left:4px solid #ec4899;">'
        + '<p style="margin:0;font-style:italic;color:#052228;">Mae: amor que protege, cuida e transforma.</p>'
        + '</div>'
        + '<p style="color:#B87333;font-weight:700;font-size:15px;">Equipe Ferreira &amp; Sa Advocacia</p>'
        + '</div>',

    dia_pais: '<div style="background:linear-gradient(135deg,#e3f2fd,#fff);padding:40px 24px;text-align:center;">'
        + '<div style="font-size:56px;margin-bottom:8px;">👔</div>'
        + '<h1 style="margin:0;font-family:Calibri;color:#052228;font-size:28px;">Feliz Dia dos Pais!</h1>'
        + '</div>'
        + '<div style="padding:32px;font-family:Calibri,sans-serif;color:#1a1a1a;line-height:1.8;text-align:center;">'
        + '<p style="font-size:16px;">Ola, <strong>[nome]</strong>!</p>'
        + '<p>Hoje celebramos os pais que lutam todos os dias pelo melhor para seus filhos.</p>'
        + '<p>Sabemos o quanto voce se dedica. E uma honra poder ajuda-lo nessa jornada.</p>'
        + '<div style="margin:24px 0;padding:16px;background:#eff6ff;border-radius:10px;border-left:4px solid #052228;">'
        + '<p style="margin:0;font-style:italic;color:#052228;">Pai: presenca que faz toda a diferenca.</p>'
        + '</div>'
        + '<p style="color:#B87333;font-weight:700;font-size:15px;">Equipe Ferreira &amp; Sa Advocacia</p>'
        + '</div>'
};

// Init
if (!document.getElementById('nlConteudo').value && TEMPLATES[tplSel]) {
    document.getElementById('nlConteudo').value = TEMPLATES[tplSel];
}
atualizarPreview();
mudouSegmento();

function uploadImagem(input) {
    if (!input.files || !input.files[0]) return;
    var file = input.files[0];
    if (file.size > 2 * 1024 * 1024) { alert('Imagem muito grande. Maximo 2MB.'); return; }
    var status = document.getElementById('nlUploadStatus');
    status.textContent = 'Enviando...';
    var fd = new FormData();
    fd.append('action', 'upload_imagem');
    fd.append('csrf_token', CSRF);
    fd.append('imagem', file);
    var x = new XMLHttpRequest(); x.open('POST', API);
    x.onload = function() {
        try {
            var r = JSON.parse(x.responseText);
            if (r.csrf) CSRF = r.csrf;
            if (r.ok) {
                var tag = '<div style="text-align:center;"><img src="' + r.url + '" style="width:100%;max-width:600px;" alt=""></div>\n';
                var ta = document.getElementById('nlConteudo');
                var pos = ta.selectionStart || 0;
                ta.value = ta.value.substring(0, pos) + tag + ta.value.substring(pos);
                atualizarPreview();
                status.innerHTML = '<span style="color:#059669;">Inserida!</span>';
                setTimeout(function(){status.textContent=''},3000);
            } else {
                status.innerHTML = '<span style="color:#dc2626;">' + (r.error || 'Erro') + '</span>';
            }
        } catch(e) { status.innerHTML = '<span style="color:#dc2626;">Erro ao enviar</span>'; }
        input.value = '';
    };
    x.send(fd);
}

function selTemplate(btn) {
    document.querySelectorAll('.nl-tpl-btn').forEach(function(b){b.classList.remove('sel')});
    btn.classList.add('sel');
    tplSel = btn.getAttribute('data-tpl');
    document.getElementById('nlConteudo').value = TEMPLATES[tplSel] || '';
    atualizarPreview();
}

function atualizarPreview() {
    var html = document.getElementById('nlConteudo').value;
    var frame = document.getElementById('nlPreviewFrame');
    var doc = frame.contentDocument || frame.contentWindow.document;
    var rodape = '<div style="background:#052228;color:#fff;padding:16px;text-align:center;font-size:11px;font-family:Calibri;">'
        + '<p style="margin:4px 0;">Ferreira &amp; Sa Advocacia Especializada</p>'
        + '<p style="margin:4px 0;opacity:.6;">Rua Dr. Aldrovando de Oliveira, 140, Ano Bom, Barra Mansa/RJ</p>'
        + '<p style="margin:4px 0;"><a href="#" style="color:#D7AB90;">Cancelar inscricao</a></p></div>';
    doc.open();
    doc.write('<div style="max-width:600px;margin:0 auto;background:#fff;font-family:Calibri,sans-serif;">' + html + rodape + '</div>');
    doc.close();
}
document.getElementById('nlConteudo').addEventListener('input', atualizarPreview);

function irPasso(n) {
    document.querySelectorAll('.wiz-step').forEach(function(s,i){s.classList.toggle('ativo',i===n-1)});
    document.querySelectorAll('.wiz-panel').forEach(function(p,i){p.classList.toggle('ativo',i===n-1)});
    if (n === 2) contarDest();
    if (n === 3) atualizarResumo();
}

function mudouSegmento() {
    var seg = document.getElementById('nlSegmento').value;
    var box = document.getElementById('nlFiltroBox');
    var sel = document.getElementById('nlFiltro');
    var lbl = document.getElementById('nlFiltroLabel');
    if (seg === 'tipo_acao') {
        box.style.display = '';
        lbl.textContent = 'Tipo de acao';
        var x = new XMLHttpRequest(); x.open('GET', API + '?action=tipos_acao');
        x.onload = function() {
            var tipos = JSON.parse(x.responseText);
            sel.innerHTML = tipos.map(function(t){return '<option value="'+t+'">'+t+'</option>'}).join('');
            contarDest();
        }; x.send();
    } else if (seg === 'status_processo') {
        box.style.display = '';
        lbl.textContent = 'Status';
        sel.innerHTML = '<option value="em_andamento">Em Andamento</option><option value="em_elaboracao">Em Elaboracao</option><option value="aguardando_docs">Aguardando Docs</option><option value="distribuido">Distribuido</option>';
        contarDest();
    } else {
        box.style.display = 'none';
        contarDest();
    }
}

function contarDest() {
    var seg = document.getElementById('nlSegmento').value;
    var filtro = document.getElementById('nlFiltro').value || '';
    var x = new XMLHttpRequest();
    x.open('GET', API + '?action=contar_destinatarios&segmento=' + seg + '&filtro=' + encodeURIComponent(filtro));
    x.onload = function() {
        try {
            var r = JSON.parse(x.responseText);
            document.getElementById('nlDestCount').textContent = r.total;
            document.getElementById('nlDestInfo').style.display = 'block';
            document.getElementById('nlDestLista').style.display = 'none';
        } catch(e) {}
    }; x.send();
}

function verLista() {
    var seg = document.getElementById('nlSegmento').value;
    var filtro = document.getElementById('nlFiltro').value || '';
    var x = new XMLHttpRequest();
    x.open('GET', API + '?action=listar_destinatarios&segmento=' + seg + '&filtro=' + encodeURIComponent(filtro));
    x.onload = function() {
        try {
            var r = JSON.parse(x.responseText);
            var html = r.map(function(d){return '<div style="padding:2px 0;border-bottom:1px solid #f3f4f6;">' + d.name + ' — <span style="color:#94a3b8;">' + d.email + '</span></div>'}).join('');
            var el = document.getElementById('nlDestLista');
            el.innerHTML = html || 'Nenhum';
            el.style.display = 'block';
        } catch(e) {}
    }; x.send();
}

function salvarRascunho() {
    var titulo = document.getElementById('nlTitulo').value.trim();
    var assunto = document.getElementById('nlAssunto').value.trim();
    if (!titulo) { alert('Preencha o titulo.'); return; }
    if (!assunto) { alert('Preencha o assunto.'); return; }
    var fd = new FormData();
    fd.append('action', 'salvar');
    fd.append('csrf_token', CSRF);
    fd.append('id', document.getElementById('campId').value);
    fd.append('titulo', titulo);
    fd.append('assunto', assunto);
    fd.append('template_tipo', tplSel);
    fd.append('conteudo_html', document.getElementById('nlConteudo').value);
    fd.append('segmento', document.getElementById('nlSegmento').value);
    fd.append('segmento_filtro', document.getElementById('nlFiltro').value || '');
    var x = new XMLHttpRequest(); x.open('POST', API);
    x.onload = function() {
        try { var r = JSON.parse(x.responseText); if (r.csrf) CSRF = r.csrf;
            if (r.ok) { document.getElementById('campId').value = r.id; alert('Rascunho salvo!'); }
            else alert(r.error || 'Erro');
        } catch(e) { alert('Erro ao salvar'); }
    }; x.send(fd);
}

function atualizarResumo() {
    var titulo = document.getElementById('nlTitulo').value || '(sem titulo)';
    var assunto = document.getElementById('nlAssunto').value || '(sem assunto)';
    var dest = document.getElementById('nlDestCount').textContent || '?';
    document.getElementById('nlResumo').innerHTML = '<strong>' + titulo + '</strong><br>Assunto: ' + assunto + '<br>Destinatarios: <strong>' + dest + '</strong>';
}

function enviarTeste() {
    var id = document.getElementById('campId').value;
    if (!id || id === '0') { alert('Salve a campanha primeiro (Passo 1 > Salvar rascunho).'); return; }
    var fd = new FormData();
    fd.append('action', 'enviar_teste'); fd.append('csrf_token', CSRF); fd.append('id', id);
    var x = new XMLHttpRequest(); x.open('POST', API);
    x.onload = function() {
        try { var r = JSON.parse(x.responseText); if (r.csrf) CSRF = r.csrf;
            var m = document.getElementById('nlEnvioMsg');
            m.textContent = r.ok ? 'Teste enviado para ' + r.enviado_para + '!' : (r.error || 'Erro');
            m.style.background = r.ok ? '#ecfdf5' : '#fef2f2'; m.style.color = r.ok ? '#059669' : '#dc2626';
            m.style.display = 'block';
        } catch(e) { alert('Erro'); }
    }; x.send(fd);
}

function enviarCampanha(agendar) {
    var id = document.getElementById('campId').value;
    if (!id || id === '0') { alert('Salve a campanha primeiro.'); return; }
    var agendaPara = '';
    if (agendar) {
        agendaPara = document.getElementById('nlAgendar').value;
        if (!agendaPara) { alert('Selecione data e hora para agendar.'); return; }
        agendaPara = agendaPara.replace('T', ' ');
    }
    var msg = agendar ? 'Agendar envio para ' + agendaPara + '?' : 'Enviar campanha agora para todos os destinatarios?';
    if (!confirm(msg)) return;
    var fd = new FormData();
    fd.append('action', 'enviar'); fd.append('csrf_token', CSRF); fd.append('id', id);
    if (agendaPara) fd.append('agendar', agendaPara);
    var x = new XMLHttpRequest(); x.open('POST', API);
    x.onload = function() {
        try { var r = JSON.parse(x.responseText); if (r.csrf) CSRF = r.csrf;
            var m = document.getElementById('nlEnvioMsg');
            if (r.ok) {
                m.textContent = (r.status === 'agendado' ? 'Campanha agendada!' : 'Campanha enviada!') + ' — ' + r.destinatarios + ' destinatarios';
                m.style.background = '#ecfdf5'; m.style.color = '#059669';
                setTimeout(function(){ location.href = '<?= module_url("newsletter") ?>'; }, 2000);
            } else {
                m.textContent = r.error || 'Erro ao enviar';
                m.style.background = '#fef2f2'; m.style.color = '#dc2626';
            }
            m.style.display = 'block';
        } catch(e) { alert('Erro ao enviar'); }
    }; x.send(fd);
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
