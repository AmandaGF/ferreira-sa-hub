<?php
/**
 * Central VIP F&S — Central de Atendimento & FAQ
 * Design premium com identidade Ferreira & Sá
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo = sv_db();
$user = salavip_current_user();

// Buscar FAQs ativas
$faqs = $pdo->query("SELECT * FROM salavip_faq WHERE ativo = 1 ORDER BY area ASC, ordem ASC")->fetchAll();

// Agrupar por área
$porArea = array();
foreach ($faqs as $f) {
    $area = $f['area'] ?: 'geral';
    if (!isset($porArea[$area])) $porArea[$area] = array();
    $porArea[$area][] = $f;
}

$areaLabels = array(
    'familia' => array('label' => 'Família', 'icon' => "\xF0\x9F\x91\xA8\xE2\x80\x8D\xF0\x9F\x91\xA9\xE2\x80\x8D\xF0\x9F\x91\xA7", 'cor' => '#d97706'),
    'consumidor' => array('label' => 'Consumidor', 'icon' => "\xF0\x9F\x9B\xA1\xEF\xB8\x8F", 'cor' => '#059669'),
    'civel' => array('label' => 'Cível', 'icon' => "\xF0\x9F\x93\x8B", 'cor' => '#6366f1'),
    'previdenciario' => array('label' => 'Previdenciário', 'icon' => "\xF0\x9F\x8F\x9B\xEF\xB8\x8F", 'cor' => '#0ea5e9'),
    'imobiliario' => array('label' => 'Imobiliário', 'icon' => "\xF0\x9F\x8F\xA0", 'cor' => '#B87333'),
);

$pageTitle = 'Central de Atendimento & FAQ';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $pageTitle ?> — Ferreira &amp; Sá</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Outfit',sans-serif; background:#F8F6F2; color:#1A1A1A; line-height:1.6; }
h1,h2,h3,h4 { font-family:'Cormorant Garamond',serif; }

.faq-header { background:linear-gradient(135deg,#052228 0%,#0a3a42 100%); color:#fff; padding:2.5rem 1.5rem; text-align:center; }
.faq-header img { max-width:200px; margin-bottom:1rem; }
.faq-header h1 { font-size:2rem; font-weight:700; margin-bottom:.5rem; }
.faq-header p { color:rgba(255,255,255,.7); font-size:.95rem; }

.faq-search { max-width:500px; margin:1.25rem auto 0; position:relative; }
.faq-search input { width:100%; padding:.75rem 1rem .75rem 2.5rem; border:2px solid rgba(184,115,51,.3); border-radius:12px; font-size:.95rem; font-family:'Outfit',sans-serif; background:rgba(255,255,255,.1); color:#fff; outline:none; }
.faq-search input::placeholder { color:rgba(255,255,255,.5); }
.faq-search input:focus { border-color:#B87333; background:rgba(255,255,255,.15); }
.faq-search::before { content:"\1F50D"; position:absolute; left:.85rem; top:50%; transform:translateY(-50%); font-size:1rem; }

.faq-container { max-width:1100px; margin:0 auto; padding:2rem 1.5rem; }

.faq-back { display:inline-flex; align-items:center; gap:6px; color:#B87333; font-weight:600; text-decoration:none; font-size:.88rem; margin-bottom:1.5rem; }
.faq-back:hover { color:#052228; }

/* Info Cards */
.info-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:1rem; margin-bottom:2.5rem; }
.info-card { background:#fff; border-radius:16px; padding:1.5rem; border:1px solid rgba(184,115,51,.15); box-shadow:0 2px 12px rgba(0,0,0,.04); transition:all .2s; }
.info-card:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(0,0,0,.08); }
.info-card h3 { font-size:1.1rem; color:#052228; margin-bottom:.75rem; display:flex; align-items:center; gap:.5rem; }
.info-card p, .info-card a { font-size:.85rem; color:#4a5568; line-height:1.7; }
.info-card a { color:#B87333; text-decoration:none; font-weight:500; }
.info-card a:hover { text-decoration:underline; }

/* Calculadora Banner */
.calc-banner { background:linear-gradient(135deg,#052228,#0d3640); border-radius:16px; padding:2rem; margin-bottom:2.5rem; display:flex; align-items:center; gap:1.5rem; flex-wrap:wrap; }
.calc-banner .calc-icon { font-size:3rem; flex-shrink:0; }
.calc-banner .calc-text { flex:1; min-width:200px; }
.calc-banner h3 { color:#fff; font-size:1.3rem; margin-bottom:.3rem; }
.calc-banner p { color:rgba(255,255,255,.7); font-size:.88rem; }
.calc-banner .calc-btn { display:inline-flex; align-items:center; gap:6px; background:#B87333; color:#fff; padding:.7rem 1.5rem; border-radius:10px; text-decoration:none; font-weight:600; font-size:.9rem; transition:all .2s; flex-shrink:0; }
.calc-banner .calc-btn:hover { background:#9a5f2a; transform:scale(1.03); }

/* Pills */
.faq-pills { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1.5rem; }
.faq-pill { padding:.5rem 1rem; border-radius:999px; font-size:.82rem; font-weight:600; cursor:pointer; border:2px solid rgba(184,115,51,.2); background:#fff; color:#4a5568; transition:all .2s; user-select:none; }
.faq-pill:hover { border-color:#B87333; color:#B87333; }
.faq-pill.active { background:#052228; color:#fff; border-color:#052228; }

/* Accordion */
.faq-section { margin-bottom:1.5rem; }
.faq-section-title { font-size:1.2rem; color:#052228; margin-bottom:.75rem; display:flex; align-items:center; gap:.5rem; padding-bottom:.5rem; border-bottom:2px solid rgba(184,115,51,.15); }
.faq-item { background:#fff; border:1px solid rgba(184,115,51,.1); border-radius:12px; margin-bottom:.5rem; overflow:hidden; transition:all .2s; }
.faq-item:hover { box-shadow:0 2px 12px rgba(0,0,0,.06); }
.faq-item.hidden { display:none; }
.faq-q { display:flex; align-items:center; padding:1rem 1.25rem; cursor:pointer; gap:.75rem; user-select:none; }
.faq-q .faq-icon { width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:700; color:#fff; flex-shrink:0; transition:transform .3s; }
.faq-q .faq-text { flex:1; font-weight:600; color:#052228; font-size:.95rem; }
.faq-q .faq-badge { background:#fef3c7; color:#d97706; padding:2px 8px; border-radius:6px; font-size:.65rem; font-weight:700; white-space:nowrap; }
.faq-q .faq-chevron { font-size:1.2rem; color:#B87333; transition:transform .3s; flex-shrink:0; }
.faq-item.open .faq-chevron { transform:rotate(180deg); }
.faq-a { max-height:0; overflow:hidden; transition:max-height .4s ease; }
.faq-a-inner { padding:0 1.25rem 1.25rem 3.5rem; color:#4a5568; font-size:.9rem; line-height:1.8; }
.faq-a-inner mark { background:#fef9c3; padding:1px 3px; border-radius:3px; }

.faq-noresult { display:none; text-align:center; padding:2rem; color:#6b7280; }
.faq-noresult a { color:#25D366; font-weight:600; }

.faq-count { font-size:.78rem; color:#6b7280; margin-bottom:1rem; }

/* CTA */
.faq-cta { background:#fff; border:2px solid rgba(184,115,51,.2); border-radius:16px; padding:2rem; text-align:center; margin-top:2rem; }
.faq-cta h3 { font-size:1.3rem; color:#052228; margin-bottom:1rem; }
.faq-cta-btns { display:flex; gap:.75rem; justify-content:center; flex-wrap:wrap; }
.faq-cta-btn { display:inline-flex; align-items:center; gap:6px; padding:.65rem 1.25rem; border-radius:10px; text-decoration:none; font-weight:600; font-size:.88rem; transition:all .2s; }
.faq-cta-btn.wa { background:#25D366; color:#fff; }
.faq-cta-btn.email { background:#052228; color:#fff; }
.faq-cta-btn.agenda { border:2px solid #B87333; color:#B87333; background:#fff; }
.faq-cta-btn:hover { transform:translateY(-1px); opacity:.9; }

/* Footer */
.faq-footer { text-align:center; padding:2rem 1.5rem; color:#6b7280; font-size:.75rem; border-top:1px solid rgba(184,115,51,.15); margin-top:2rem; }
.faq-footer strong { color:#052228; }

/* PIX copy */
.pix-btn { display:inline-flex; align-items:center; gap:4px; background:#052228; color:#fff; padding:4px 12px; border-radius:6px; font-size:.75rem; font-weight:600; cursor:pointer; border:none; transition:all .2s; margin-top:.5rem; }
.pix-btn:hover { background:#B87333; }
.pix-btn.copied { background:#059669; }

@media (max-width:768px) {
    .faq-header h1 { font-size:1.5rem; }
    .info-grid { grid-template-columns:1fr; }
    .calc-banner { flex-direction:column; text-align:center; }
}
</style>
</head>
<body>

<!-- Header -->
<div class="faq-header">
    <img src="<?= sv_url('assets/img/logo-branco.png') ?>" alt="Ferreira &amp; Sá" onerror="this.outerHTML='<h2 style=color:#B87333;font-family:Cormorant Garamond,serif;font-size:1.8rem>FERREIRA &amp; SÁ</h2><p style=font-size:.75rem;color:rgba(255,255,255,.5);letter-spacing:3px>ADVOCACIA ESPECIALIZADA</p>'">
    <h1>Central de Atendimento &amp; FAQ</h1>
    <p>Encontre respostas rápidas ou fale com nossa equipe</p>
    <div class="faq-search">
        <input type="text" id="faqBusca" placeholder="Buscar pergunta ou assunto..." oninput="buscarFaq(this.value)">
    </div>
</div>

<div class="faq-container">

<a href="<?= sv_url('pages/dashboard.php') ?>" class="faq-back">&larr; Voltar ao Painel</a>

<!-- Informações do Escritório -->
<div class="info-grid">
    <div class="info-card">
        <h3><span style="font-size:1.3rem;">&#128205;</span> Endereços</h3>
        <p><strong>Sede:</strong> Rua Dr. Aldrovando de Oliveira, 138 — Ano Bom, Barra Mansa/RJ</p>
        <p><strong>Filial SP:</strong> Av. Paulista, 1636, Sala 1105/543 — Paulista Corporate, São Paulo/SP</p>
        <p><strong>Urgência RJ:</strong> Av. das Américas, 4200, Bl. 01, Sala 305 — Barra da Tijuca, Rio de Janeiro/RJ</p>
        <p><strong>Agendamento VR:</strong> Rua 535, nº 325 — N. Sra. das Graças, Volta Redonda/RJ</p>
    </div>
    <div class="info-card">
        <h3><span style="font-size:1.3rem;">&#128336;</span> Horários</h3>
        <p><strong>Presencial:</strong> Segunda a Sexta, 10h às 18h</p>
        <p><strong>Remoto:</strong> 24h / 7 dias</p>
        <p><strong>Urgências:</strong> fins de semana e feriados (plantão remoto)</p>
    </div>
    <div class="info-card">
        <h3><span style="font-size:1.3rem;">&#128222;</span> Contatos</h3>
        <p>(24) 9.9205-0096</p>
        <p>(24) 9.8142-9356</p>
        <p>(11) 2110-5438</p>
        <p>(21) 9.9862-6615</p>
        <p><a href="mailto:contato@ferreiraesa.com.br">contato@ferreiraesa.com.br</a></p>
        <p><a href="https://www.ferreiraesa.com.br" target="_blank">www.ferreiraesa.com.br</a></p>
        <a href="https://wa.me/5524992050096" target="_blank" class="faq-cta-btn wa" style="margin-top:.5rem;font-size:.78rem;padding:5px 12px;">&#128172; WhatsApp</a>
    </div>
    <div class="info-card">
        <h3><span style="font-size:1.3rem;">&#128179;</span> Dados Bancários</h3>
        <p><strong>Banco:</strong> Cora SCD (403)</p>
        <p><strong>Ag:</strong> 0001 | <strong>CC:</strong> 5224012-7</p>
        <p><strong>CNPJ:</strong> 51.294.223/0001-40</p>
        <p><strong>Nome:</strong> Ferreira e Sá Advocacia</p>
        <p><strong>PIX (CNPJ):</strong> 51.294.223/0001-40</p>
        <button class="pix-btn" onclick="copiarPix(this)">&#128203; Copiar PIX</button>
    </div>
</div>

<!-- Calculadora Banner -->
<div class="calc-banner">
    <div class="calc-icon">&#129518;</div>
    <div class="calc-text">
        <h3>Calcule o valor estimado da pensão alimentícia</h3>
        <p>Use nossa calculadora gratuita para ter uma estimativa baseada no trinômio necessidade × possibilidade × proporcionalidade.</p>
    </div>
    <a href="https://ferreiraesa.com.br/calculadora" target="_blank" class="calc-btn">&#9889; Acessar Calculadora</a>
</div>

<!-- FAQ por Área -->
<h2 style="font-size:1.5rem;color:#052228;margin-bottom:1rem;">Perguntas Frequentes</h2>

<div class="faq-pills">
    <span class="faq-pill active" onclick="filtrarArea('todos', this)">&#9878;&#65039; Todos</span>
    <?php foreach ($areaLabels as $key => $a): if (isset($porArea[$key])): ?>
    <span class="faq-pill" onclick="filtrarArea('<?= $key ?>', this)" data-area="<?= $key ?>"><?= $a['icon'] ?> <?= $a['label'] ?></span>
    <?php endif; endforeach; ?>
</div>

<div class="faq-count" id="faqCount"><?= count($faqs) ?> perguntas disponíveis</div>

<?php foreach ($areaLabels as $areaKey => $areaInfo):
    if (!isset($porArea[$areaKey])) continue;
?>
<div class="faq-section" data-section="<?= $areaKey ?>">
    <div class="faq-section-title">
        <span style="font-size:1.2rem;"><?= $areaInfo['icon'] ?></span>
        <?= $areaInfo['label'] ?>
        <span style="font-size:.72rem;color:#6b7280;font-family:Outfit,sans-serif;font-weight:400;margin-left:auto;"><?= count($porArea[$areaKey]) ?> perguntas</span>
    </div>
    <?php foreach ($porArea[$areaKey] as $i => $faq): ?>
    <div class="faq-item" data-area="<?= $areaKey ?>" data-q="<?= htmlspecialchars(mb_strtolower($faq['pergunta'], 'UTF-8'), ENT_QUOTES) ?>" data-a="<?= htmlspecialchars(mb_strtolower($faq['resposta'], 'UTF-8'), ENT_QUOTES) ?>">
        <div class="faq-q" onclick="toggleFaq(this.parentNode)">
            <div class="faq-icon" style="background:<?= $areaInfo['cor'] ?>;">?</div>
            <span class="faq-text"><?= sv_e($faq['pergunta']) ?></span>
            <?php if ($faq['destaque']): ?><span class="faq-badge">&#11088; Mais perguntado</span><?php endif; ?>
            <span class="faq-chevron">&#9660;</span>
        </div>
        <div class="faq-a">
            <div class="faq-a-inner" id="faqResp<?= $faq['id'] ?>"><?= nl2br(sv_e($faq['resposta'])) ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>

<div class="faq-noresult" id="faqNoResult">
    Não encontramos respostas para "<strong id="faqTermoBuscado"></strong>".<br>
    <a href="https://wa.me/5524992050096?text=Ol%C3%A1!%20Tenho%20uma%20d%C3%BAvida%20que%20n%C3%A3o%20encontrei%20no%20FAQ." target="_blank">&#128172; Fale com nossa equipe pelo WhatsApp</a>
</div>

<!-- CTA -->
<div class="faq-cta">
    <h3>Não encontrou sua resposta?</h3>
    <div class="faq-cta-btns">
        <a href="https://wa.me/5524992050096" target="_blank" class="faq-cta-btn wa">&#128172; WhatsApp</a>
        <a href="mailto:contato@ferreiraesa.com.br" class="faq-cta-btn email">&#128231; E-mail</a>
        <a href="https://wa.me/5524992050096?text=Ol%C3%A1!%20Gostaria%20de%20agendar%20uma%20consulta." target="_blank" class="faq-cta-btn agenda">&#128197; Agendar consulta</a>
    </div>
</div>

<!-- Footer -->
<div class="faq-footer">
    <p>&copy; 2026 <strong>Ferreira &amp; Sá Advocacia Especializada</strong></p>
    <p>CNPJ: 51.294.223/0001-40 | OAB/RJ n. 005.987/2023</p>
</div>

</div><!-- /.faq-container -->

<script>
function toggleFaq(item) {
    var isOpen = item.classList.contains('open');
    // Fechar todos
    document.querySelectorAll('.faq-item.open').forEach(function(el) {
        el.classList.remove('open');
        el.querySelector('.faq-a').style.maxHeight = '0';
    });
    // Abrir o clicado (se não estava aberto)
    if (!isOpen) {
        item.classList.add('open');
        var a = item.querySelector('.faq-a');
        a.style.maxHeight = a.scrollHeight + 'px';
    }
}

function filtrarArea(area, btn) {
    // Limpar busca
    document.getElementById('faqBusca').value = '';
    limparHighlights();

    // Ativar pill
    document.querySelectorAll('.faq-pill').forEach(function(p) { p.classList.remove('active'); });
    btn.classList.add('active');

    var sections = document.querySelectorAll('.faq-section');
    var items = document.querySelectorAll('.faq-item');
    var count = 0;

    if (area === 'todos') {
        sections.forEach(function(s) { s.style.display = ''; });
        items.forEach(function(i) { i.classList.remove('hidden'); count++; });
    } else {
        sections.forEach(function(s) {
            s.style.display = s.getAttribute('data-section') === area ? '' : 'none';
        });
        items.forEach(function(i) {
            if (i.getAttribute('data-area') === area) {
                i.classList.remove('hidden');
                count++;
            } else {
                i.classList.add('hidden');
            }
        });
    }

    document.getElementById('faqCount').textContent = count + ' pergunta' + (count !== 1 ? 's' : '') + ' disponíve' + (count !== 1 ? 'is' : 'l');
    document.getElementById('faqNoResult').style.display = 'none';
}

function buscarFaq(termo) {
    var t = termo.toLowerCase().trim();

    // Limpar filtro de área
    document.querySelectorAll('.faq-pill').forEach(function(p) { p.classList.remove('active'); });
    document.querySelector('.faq-pill').classList.add('active');
    document.querySelectorAll('.faq-section').forEach(function(s) { s.style.display = ''; });

    var items = document.querySelectorAll('.faq-item');
    var count = 0;

    if (!t) {
        items.forEach(function(i) { i.classList.remove('hidden'); count++; });
        limparHighlights();
        document.getElementById('faqNoResult').style.display = 'none';
        document.getElementById('faqCount').textContent = items.length + ' perguntas disponíveis';
        return;
    }

    items.forEach(function(i) {
        var q = i.getAttribute('data-q');
        var a = i.getAttribute('data-a');
        if (q.indexOf(t) !== -1 || a.indexOf(t) !== -1) {
            i.classList.remove('hidden');
            count++;
            // Highlight
            highlightTermo(i, termo);
        } else {
            i.classList.add('hidden');
        }
    });

    // Ocultar seções vazias
    document.querySelectorAll('.faq-section').forEach(function(s) {
        var visivel = s.querySelectorAll('.faq-item:not(.hidden)').length;
        s.style.display = visivel ? '' : 'none';
    });

    document.getElementById('faqCount').textContent = count + ' resultado' + (count !== 1 ? 's' : '');
    if (count === 0) {
        document.getElementById('faqTermoBuscado').textContent = termo;
        document.getElementById('faqNoResult').style.display = 'block';
    } else {
        document.getElementById('faqNoResult').style.display = 'none';
    }
}

function highlightTermo(item, termo) {
    var respEl = item.querySelector('.faq-a-inner');
    var textoOriginal = respEl.getAttribute('data-original');
    if (!textoOriginal) {
        textoOriginal = respEl.innerHTML;
        respEl.setAttribute('data-original', textoOriginal);
    }
    var regex = new RegExp('(' + termo.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
    respEl.innerHTML = textoOriginal.replace(regex, '<mark>$1</mark>');
}

function limparHighlights() {
    document.querySelectorAll('.faq-a-inner[data-original]').forEach(function(el) {
        el.innerHTML = el.getAttribute('data-original');
    });
}

function copiarPix(btn) {
    var pix = '51.294.223/0001-40';
    if (navigator.clipboard) {
        navigator.clipboard.writeText(pix).then(function() {
            btn.innerHTML = '&#9989; Copiado!';
            btn.classList.add('copied');
            setTimeout(function() { btn.innerHTML = '&#128203; Copiar PIX'; btn.classList.remove('copied'); }, 2000);
        });
    } else {
        var t = document.createElement('textarea');
        t.value = pix; document.body.appendChild(t); t.select(); document.execCommand('copy'); document.body.removeChild(t);
        btn.innerHTML = '&#9989; Copiado!';
        btn.classList.add('copied');
        setTimeout(function() { btn.innerHTML = '&#128203; Copiar PIX'; btn.classList.remove('copied'); }, 2000);
    }
}
</script>
</body>
</html>
