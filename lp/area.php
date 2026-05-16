<?php
/**
 * LP por área de atuação — captura lead. /conecta/lp/area.php?a=<slug>
 * Usa lp/area.css (estética quente, independente da home). Form → /publico/lead_site.php.
 */
$wpp = '5524992050096';
$wppMsg = rawurlencode('Olá! Vim pelo site e gostaria de conversar com um advogado.');
$ano = date('Y');

$AREAS = array(
  'familia' => array(
    'nome' => 'Direito de Família',
    'h1'   => 'Direito de Família com técnica e <em>acolhimento</em>',
    'intro'=> 'Divórcio, guarda, pensão, união estável e medidas protetivas conduzidos por quem entende que ali existe uma história — não um número.',
    'desc' => 'Advogada de Direito de Família em Barra Mansa e região: divórcio, guarda, pensão alimentícia, união estável e medidas protetivas. Atendimento humanizado em todo o Brasil.',
    'p1'   => 'Questões de família chegam num momento delicado da vida. Nosso papel é trazer clareza, proteger seus direitos e os de quem você ama, e conduzir o processo com a serenidade que a situação exige — sem juridiquês e sem você no escuro.',
    'p2'   => 'Atuamos tanto no acordo extrajudicial (mais rápido e menos desgastante) quanto na disputa judicial quando ela é inevitável, sempre com estratégia definida e transparência total sobre prazos e custos.',
    'itens'=> array('Divórcio consensual ou litigioso','Guarda, convivência e visitação','Pensão alimentícia (fixação, revisão e execução)','União estável: reconhecimento e dissolução','Partilha de bens','Medidas protetivas e violência doméstica','Investigação e reconhecimento de paternidade','Alienação parental'),
  ),
  'sucessoes' => array(
    'nome' => 'Sucessões e Inventário',
    'h1'   => 'Inventário e Sucessões <em>sem desgaste familiar</em>',
    'intro'=> 'Inventário judicial e extrajudicial, testamento e planeamento sucessório conduzidos com técnica e sensibilidade.',
    'desc' => 'Advocacia em Sucessões e Inventário: inventário judicial e extrajudicial, partilha, testamento e planejamento sucessório. Barra Mansa e todo o Brasil.',
    'p1'   => 'Perder alguém já é difícil; resolver a herança não precisa virar uma segunda dor. Cuidamos de todo o trâmite com organização e respeito, buscando o caminho mais rápido e econômico — inclusive o inventário em cartório quando possível.',
    'p2'   => 'Também estruturamos planejamento sucessório em vida (testamento, doações, holding familiar) para proteger o patrimônio e evitar conflitos futuros entre herdeiros.',
    'itens'=> array('Inventário judicial e extrajudicial','Partilha de bens e sobrepartilha','Testamento e disposições de última vontade','Planejamento sucessório e doações','Arrolamento e alvará judicial','Cessão de direitos hereditários','Regularização de bens do espólio'),
  ),
  'imobiliario' => array(
    'nome' => 'Direito Imobiliário',
    'h1'   => 'Segurança jurídica no seu <em>imóvel</em>',
    'intro'=> 'Compra e venda, contratos, regularização e disputas sobre imóveis com a análise técnica que evita prejuízo.',
    'desc' => 'Advocacia em Direito Imobiliário: compra e venda, contratos, regularização, usucapião, distrato e disputas. Barra Mansa e região.',
    'p1'   => 'Imóvel costuma ser o maior patrimônio de uma família — e o erro mais caro é o que se descobre depois de assinar. Analisamos a documentação, redigimos e revisamos contratos e atuamos para que sua compra, venda ou regularização seja segura.',
    'p2'   => 'Quando o conflito já existe (distrato, atraso de obra, vício de construção, disputa de posse), entramos com a estratégia certa para proteger seu direito e seu dinheiro.',
    'itens'=> array('Análise e elaboração de contratos de compra e venda','Regularização de imóveis e escrituras','Usucapião','Distrato e rescisão contratual','Atraso de obra e vícios de construção','Ações possessórias e de despejo','Assessoria em financiamentos e incorporação'),
  ),
  'consumidor' => array(
    'nome' => 'Direito do Consumidor',
    'h1'   => 'Quando te lesam, a gente <em>vira o jogo</em>',
    'intro'=> 'Cobrança indevida, negativação, produto/serviço com defeito e indenização — com estratégia voltada ao resultado.',
    'desc' => 'Advocacia do Consumidor: cobrança indevida, negativação indevida, produtos e serviços defeituosos, indenização por danos. Atendimento em todo o Brasil.',
    'p1'   => 'Empresa grande conta com o cliente desistir. Nós contamos o contrário: reunimos as provas, calculamos o que é devido e buscamos a reparação — inclusive os danos morais quando há abuso.',
    'p2'   => 'Atuamos contra bancos, operadoras, companhias aéreas, planos de saúde, lojas e prestadores de serviço, com acompanhamento próximo e linguagem que você entende.',
    'itens'=> array('Cobrança e desconto indevidos','Negativação indevida (nome sujo)','Produto ou serviço com defeito','Problemas com bancos e financeiras','Planos de saúde: negativa de cobertura','Voos cancelados e extravio de bagagem','Indenização por danos morais e materiais'),
  ),
  'civel' => array(
    'nome' => 'Responsabilidade Civil',
    'h1'   => 'Reparação para quem <em>sofreu um dano</em>',
    'intro'=> 'Indenização por danos morais e materiais, acidentes e responsabilidade civil com foco no resultado.',
    'desc' => 'Advocacia em Responsabilidade Civil: indenização por danos morais e materiais, acidentes, responsabilidade contratual e extracontratual.',
    'p1'   => 'Todo dano injusto gera o direito de ser reparado. Avaliamos sua situação com honestidade — se há caso, montamos a prova e perseguimos a indenização justa; se não há, dizemos com clareza, sem te iludir.',
    'p2'   => 'Atuamos em acidentes, responsabilidade contratual e extracontratual, danos à imagem e demais conflitos cíveis que exigem reparação.',
    'itens'=> array('Indenização por danos morais','Indenização por danos materiais e lucros cessantes','Acidentes de trânsito','Responsabilidade contratual e extracontratual','Danos à imagem e à honra','Cobranças e ações de reparação'),
  ),
  'contratos' => array(
    'nome' => 'Contratos e Cível',
    'h1'   => 'Contrato bem feito <em>evita processo</em>',
    'intro'=> 'Elaboração e revisão de contratos, cobranças e ações cíveis com prevenção de litígios.',
    'desc' => 'Advocacia em Contratos e Cível: elaboração e revisão de contratos, cobranças, ações cíveis e prevenção de litígios.',
    'p1'   => 'O melhor processo é o que não acontece. Redigimos e revisamos contratos pensando no cenário em que algo dá errado — para que, se der, você esteja protegido.',
    'p2'   => 'Quando o descumprimento já ocorreu, atuamos na cobrança e nas ações cíveis necessárias para fazer valer o que foi acordado.',
    'itens'=> array('Elaboração e revisão de contratos','Contratos de prestação de serviço e parceria','Cobrança judicial e extrajudicial','Execução de títulos e dívidas','Ações cíveis em geral','Consultoria preventiva e pareceres'),
  ),
);

$slug = strtolower(trim($_GET['a'] ?? ''));
if (!isset($AREAS[$slug])) { header('Location: v2.php#areas'); exit; }
$A = $AREAS[$slug];

// Avaliações (badge de confiança) — cache compartilhado, falha silenciosa.
$grev = array('ok' => false);
try {
    require_once __DIR__ . '/../core/database.php';
    require_once __DIR__ . '/../core/google_reviews.php';
    $grev = google_reviews_get();
} catch (Throwable $e) { $grev = array('ok' => false); }
$grevRating = (!empty($grev['ok']) && !empty($grev['rating'])) ? number_format($grev['rating'], 1, ',', '') : null;
$grevTotal  = (!empty($grev['ok']) && !empty($grev['total'])) ? (int)$grev['total'] : null;

$e = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
$ld = array(
  '@context' => 'https://schema.org',
  '@type' => 'LegalService',
  'name' => 'Ferreira & Sá Advocacia — ' . $A['nome'],
  'description' => $A['desc'],
  'url' => 'https://ferreiraesa.com.br/conecta/lp/area.php?a=' . $slug,
  'telephone' => '+55-24-99205-0096',
  'areaServed' => array('Barra Mansa','Volta Redonda','Resende','Rio de Janeiro','São Paulo','Brasil'),
  'address' => array('@type'=>'PostalAddress','addressLocality'=>'Barra Mansa','addressRegion'=>'RJ','addressCountry'=>'BR'),
);
if ($grevRating && $grevTotal) $ld['aggregateRating'] = array('@type'=>'AggregateRating','ratingValue'=>number_format($grev['rating'],1,'.',''),'reviewCount'=>$grevTotal,'bestRating'=>'5','worstRating'=>'1');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $e($A['nome']) ?> — Ferreira &amp; Sá Advocacia | Barra Mansa</title>
<meta name="description" content="<?= $e($A['desc']) ?>">
<meta name="theme-color" content="#052228">
<meta property="og:title" content="<?= $e($A['nome']) ?> — Ferreira &amp; Sá Advocacia">
<meta property="og:description" content="<?= $e($A['desc']) ?>">
<meta property="og:type" content="website">
<meta property="og:image" content="https://ferreiraesa.com.br/conecta/assets/img/site/escritorio.jpg">
<link rel="canonical" href="https://ferreiraesa.com.br/conecta/lp/area.php?a=<?= $e($slug) ?>">
<link rel="icon" type="image/png" href="../assets/img/logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="area.css?v=2026051604">
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
</head>
<body>

<nav class="nav solid" id="nav">
  <a href="v2.php#topo" class="nav-logo" aria-label="Ferreira &amp; Sá Advocacia"><img src="../assets/img/logo.png" alt="Ferreira &amp; Sá Advocacia" onerror="this.parentNode.textContent='FERREIRA &amp; SÁ'"></a>
  <div class="nav-links">
    <a href="v2.php#sobre">O Escritório</a>
    <a href="v2.php#areas">Áreas</a>
    <a href="v2.php#equipe">Equipe</a>
    <a href="#form">Contato</a>
    <a href="/salavip/" target="_blank" rel="noopener" title="Acompanhe seu processo">🔒 Área do Cliente</a>
    <a href="https://wa.me/<?= $wpp ?>?text=<?= $wppMsg ?>" target="_blank" rel="noopener" class="nav-cta">Agendar Consulta</a>
  </div>
  <button class="burger" onclick="document.getElementById('mnav').classList.add('open')">☰</button>
</nav>
<div class="mnav" id="mnav">
  <button class="mclose" onclick="document.getElementById('mnav').classList.remove('open')">&times;</button>
  <a href="v2.php#sobre" onclick="document.getElementById('mnav').classList.remove('open')">O Escritório</a>
  <a href="v2.php#areas" onclick="document.getElementById('mnav').classList.remove('open')">Áreas</a>
  <a href="v2.php#equipe" onclick="document.getElementById('mnav').classList.remove('open')">Equipe</a>
  <a href="#form" onclick="document.getElementById('mnav').classList.remove('open')">Contato</a>
  <a href="/salavip/" target="_blank" rel="noopener">🔒 Área do Cliente</a>
  <a href="https://wa.me/<?= $wpp ?>?text=<?= $wppMsg ?>" target="_blank" rel="noopener">Agendar Consulta</a>
</div>

<header class="ahero" data-area="<?= $e($slug) ?>">
  <div class="hero-orbs"><span></span><span></span><span></span></div>
  <div class="wrap" style="position:relative;z-index:2;">
    <div class="crumbs"><a href="v2.php">Início</a> · <a href="v2.php#areas">Áreas</a> · <?= $e($A['nome']) ?></div>
    <div class="eyebrow"><?= $e($A['nome']) ?></div>
    <h1><?= $A['h1'] /* contém <em> intencional */ ?></h1>
    <p><?= $e($A['intro']) ?></p>
  </div>
</header>

<section class="sec">
  <div class="wrap acontent">
    <div class="aprose reveal">
      <p><?= $e($A['p1']) ?></p>
      <p><?= $e($A['p2']) ?></p>
      <h3>O que resolvemos em <?= $e($A['nome']) ?></h3>
      <ul class="svc-list">
        <?php foreach ($A['itens'] as $it): ?><li><?= $e($it) ?></li><?php endforeach; ?>
      </ul>
      <div class="afoto reveal"><img src="../assets/img/site/aperto-maos.jpg" alt="Atendimento próximo e de confiança — Ferreira &amp; Sá Advocacia" loading="lazy"></div>
      <h3>Como conduzimos</h3>
      <p>Primeiro a gente ouve. Depois apresenta um plano claro, com prazos realistas e honorários transparentes — você decide com toda a informação na mão. Durante o processo, acompanha cada andamento por um portal exclusivo e fala direto com quem cuida da sua causa, com retorno em até 24 horas úteis.</p>
      <?php if ($grevRating && $grevTotal): ?>
      <p style="margin-top:1.4rem;font-size:.9rem;color:var(--muted);">⭐ <strong style="color:var(--petrol);"><?= $e($grevRating) ?></strong> de 5,0 em <?= (int)$grevTotal ?> avaliações no Google · <a href="v2.php#contato" style="color:var(--rose-2);font-weight:600;">ver mais</a></p>
      <?php endif; ?>
    </div>

    <div class="aside-form" id="form">
      <div class="lead-form reveal">
        <h3>Fale com um advogado</h3>
        <div class="sub">Conte seu caso de <?= $e($A['nome']) ?>. Retornamos em até 24h úteis.</div>
        <form id="leadForm" autocomplete="on">
          <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
          <input type="hidden" name="ts" value="">
          <input type="hidden" name="area" value="<?= $e($slug) ?>">
          <input type="hidden" name="origem" value="site-area-<?= $e($slug) ?>">
          <input type="hidden" name="pagina" value="">
          <label for="lfNome">Nome completo *</label>
          <input id="lfNome" type="text" name="nome" required maxlength="120" placeholder="Seu nome">
          <label for="lfFone">WhatsApp (com DDD) *</label>
          <input id="lfFone" type="tel" name="telefone" required maxlength="20" placeholder="(24) 99999-9999">
          <label for="lfEmail">E-mail</label>
          <input id="lfEmail" type="email" name="email" maxlength="120" placeholder="seu@email.com (opcional)">
          <label for="lfMsg">Resuma seu caso</label>
          <textarea id="lfMsg" name="mensagem" maxlength="1200" placeholder="Opcional — quanto mais detalhes, melhor te orientamos."></textarea>
          <button type="submit" id="lfBtn">Quero ser contatado</button>
          <div class="lf-msg" id="lfFeedback"></div>
          <div class="lf-priv">Seus dados são tratados com sigilo e usados apenas para o seu atendimento.</div>
        </form>
      </div>
    </div>
  </div>
</section>

<section class="aband">
  <div class="wrap reveal">
    <h2>Histórias que <em>terminaram bem</em> começam com uma conversa.</h2>
    <p>Conte sua situação sem compromisso. A gente te diz com clareza o que dá pra fazer — e cuida do resto.</p>
    <a href="#form" class="bcta">Falar com um advogado</a>
  </div>
</section>

<footer class="foot">
  <div class="wrap">
    <div class="foot-grid">
      <div>
        <div class="foot-logo">FERREIRA &amp; SÁ</div>
        <p style="color:rgba(255,255,255,.5);max-width:300px">Advocacia full service — Família, Sucessões, Imobiliário e Consumidor. Técnica, transparência e acolhimento em cada causa.</p>
        <a href="/salavip/" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:.5rem;margin-top:1.2rem;border:1px solid rgba(215,171,144,.45);color:var(--rose);padding:.6rem 1.2rem;border-radius:100px;font-weight:600;font-size:.82rem;">🔒 Área do Cliente · acompanhe seu processo</a>
      </div>
      <div>
        <h5>Áreas</h5>
        <?php foreach ($AREAS as $sl => $ar): ?>
          <p><a href="area.php?a=<?= $sl ?>"><?= $e($ar['nome']) ?></a></p>
        <?php endforeach; ?>
      </div>
      <div>
        <h5>Contato</h5>
        <p><a href="https://wa.me/<?= $wpp ?>">WhatsApp · (24) 99205-0096</a></p>
        <p><a href="mailto:contato@ferreiraesa.com.br">contato@ferreiraesa.com.br</a></p>
        <p style="margin-top:.4rem;">Rua Dr. Aldrovando de Oliveira, 140<br>Ano Bom — Barra Mansa / RJ</p>
      </div>
    </div>
    <div class="foot-bottom">
      &copy; <?= $ano ?> Ferreira &amp; Sá Sociedade de Advogados — CNPJ 51.294.223/0001-40 — OAB/RJ 5.987/2023<br>
      Este site tem caráter meramente informativo, em conformidade com o Código de Ética e Disciplina da OAB.<br>
      <a href="privacidade.php">Política de Privacidade &amp; LGPD</a>
    </div>
  </div>
</footer>

<a href="https://wa.me/<?= $wpp ?>?text=<?= $wppMsg ?>" target="_blank" rel="noopener" class="wpp" aria-label="WhatsApp">
  <svg width="30" height="30" viewBox="0 0 24 24" fill="#fff"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
</a>

<script>
var nav=document.getElementById('nav');
addEventListener('scroll',function(){nav.classList.toggle('solid',scrollY>40)},{passive:true});
var io=new IntersectionObserver(function(es){es.forEach(function(x){if(x.isIntersecting){x.target.classList.add('in');io.unobserve(x.target)}})},{threshold:.12});
document.querySelectorAll('.reveal').forEach(function(el){io.observe(el)});
(function(){
  var f=document.getElementById('leadForm'); if(!f) return;
  f.ts.value=Date.now(); f.pagina.value=location.href.slice(0,300);
  f.addEventListener('submit',function(ev){
    ev.preventDefault();
    var b=document.getElementById('lfBtn'), fb=document.getElementById('lfFeedback');
    fb.className='lf-msg'; fb.textContent=''; b.disabled=true; var lab=b.textContent; b.textContent='Enviando…';
    fetch('/conecta/publico/lead_site.php',{method:'POST',body:new FormData(f)})
      .then(function(r){return r.json();})
      .then(function(j){
        if(j&&j.ok){ f.reset(); fb.className='lf-msg ok';
          fb.textContent='✓ Recebemos! Em breve entramos em contato. Protocolo: '+(j.protocol||'OK'); b.textContent='Enviado ✓'; }
        else { fb.className='lf-msg err'; fb.textContent=(j&&j.error)?j.error:'Não foi possível enviar. Tente pelo WhatsApp.'; b.disabled=false; b.textContent=lab; }
      })
      .catch(function(){ fb.className='lf-msg err'; fb.textContent='Falha de conexão. Fale pelo WhatsApp.'; b.disabled=false; b.textContent=lab; });
  });
})();
</script>
</body>
</html>
