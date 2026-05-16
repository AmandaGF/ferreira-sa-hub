<?php
/**
 * MOCKUP — Novo site institucional Ferreira & Sá Advocacia (v2)
 * Standalone, sem login. Preview: /conecta/lp/v2.php
 * Copy persuasivo + placeholders marcados (fotos/depoimentos/números a validar).
 */
$ano = date('Y');
$wpp = '5524992050096';
$wppMsg = rawurlencode('Olá! Vim pelo site e gostaria de conversar com um advogado.');

// Avaliações reais do Google (Places API + cache). Falha silenciosa: sem
// chave/sem rede mantém o placeholder. Nunca derruba a página.
$grev = array('ok' => false);
try {
    require_once __DIR__ . '/../core/database.php';
    require_once __DIR__ . '/../core/google_reviews.php';
    $grev = google_reviews_get();
} catch (Throwable $e) { $grev = array('ok' => false); }
$grevOk = !empty($grev['ok']) && !empty($grev['reviews']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ferreira &amp; Sá Advocacia — Família, Sucessões, Imobiliário e Consumidor</title>
<meta name="description" content="Advocacia full service: Direito de Família, Sucessões, Imobiliário, Consumidor, Responsabilidade Civil e Cível. Atendimento humanizado e técnico em todo o Brasil.">
<meta name="theme-color" content="#052228">
<meta property="og:title" content="Ferreira &amp; Sá Advocacia">
<meta property="og:description" content="Advocacia com estratégia, técnica e acolhimento — Família, Sucessões, Imobiliário e Consumidor. Atendimento em todo o Brasil.">
<meta property="og:type" content="website">
<meta property="og:image" content="https://ferreiraesa.com.br/conecta/assets/img/site/escritorio.jpg">
<meta property="og:url" content="https://ferreiraesa.com.br/conecta/lp/v2.php">
<meta name="twitter:card" content="summary_large_image">
<link rel="canonical" href="https://ferreiraesa.com.br/conecta/lp/v2.php">
<link rel="icon" type="image/png" href="../assets/img/logo.png">
<?php
$ld = array(
  '@context' => 'https://schema.org',
  '@type'    => 'LegalService',
  'name'     => 'Ferreira & Sá Advocacia',
  'description' => 'Advocacia full service: Família, Sucessões, Imobiliário, Consumidor, Responsabilidade Civil e Cível.',
  'url'      => 'https://ferreiraesa.com.br',
  'telephone'=> '+55-24-99205-0096',
  'email'    => 'contato@ferreiraesa.com.br',
  'image'    => 'https://ferreiraesa.com.br/conecta/assets/img/site/escritorio.jpg',
  'priceRange' => '$$',
  'address'  => array(
    '@type' => 'PostalAddress',
    'streetAddress' => 'Rua Dr. Aldrovando de Oliveira, 140 — Ano Bom',
    'addressLocality' => 'Barra Mansa',
    'addressRegion' => 'RJ',
    'addressCountry' => 'BR',
  ),
  'areaServed' => array('Barra Mansa','Volta Redonda','Resende','Rio de Janeiro','São Paulo','Brasil'),
  'knowsAbout' => array('Direito de Família','Sucessões e Inventário','Direito Imobiliário','Direito do Consumidor','Responsabilidade Civil','Contratos'),
);
if (!empty($grev['url'])) $ld['sameAs'] = array($grev['url']);
if ($grevOk && !empty($grev['rating']) && !empty($grev['total'])) {
  $ld['aggregateRating'] = array(
    '@type' => 'AggregateRating',
    'ratingValue' => number_format((float)$grev['rating'], 1, '.', ''),
    'reviewCount' => (int)$grev['total'],
    'bestRating' => '5', 'worstRating' => '1',
  );
}
echo '<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="site.css?v=2026051602">
</head>
<body>

<nav class="nav" id="nav">
  <a href="#topo" class="nav-logo" aria-label="Ferreira &amp; Sá Advocacia"><img src="../assets/img/logo.png" alt="Ferreira &amp; Sá Advocacia" onerror="this.parentNode.textContent='FERREIRA &amp; SÁ'"></a>
  <div class="nav-links">
    <a href="#sobre">O Escritório</a>
    <a href="#areas">Áreas</a>
    <a href="#processo">Como Atuamos</a>
    <a href="#equipe">Equipe</a>
    <a href="#contato">Contato</a>
    <a href="/salavip/" target="_blank" rel="noopener" title="Acompanhe seu processo na Central do Cliente">🔒 Área do Cliente</a>
    <a href="https://wa.me/<?= $wpp ?>?text=<?= $wppMsg ?>" target="_blank" rel="noopener" class="nav-cta">Agendar Consulta</a>
  </div>
  <button class="burger" onclick="document.getElementById('mnav').classList.add('open')">☰</button>
</nav>
<div class="mnav" id="mnav">
  <button class="mclose" onclick="document.getElementById('mnav').classList.remove('open')">&times;</button>
  <a href="#sobre" onclick="document.getElementById('mnav').classList.remove('open')">O Escritório</a>
  <a href="#areas" onclick="document.getElementById('mnav').classList.remove('open')">Áreas</a>
  <a href="#processo" onclick="document.getElementById('mnav').classList.remove('open')">Como Atuamos</a>
  <a href="#equipe" onclick="document.getElementById('mnav').classList.remove('open')">Equipe</a>
  <a href="#contato" onclick="document.getElementById('mnav').classList.remove('open')">Contato</a>
  <a href="/salavip/" target="_blank" rel="noopener">🔒 Área do Cliente</a>
  <a href="https://wa.me/<?= $wpp ?>?text=<?= $wppMsg ?>" target="_blank" rel="noopener">Agendar Consulta</a>
</div>

<!-- HERO -->
<header class="hero" id="topo">
  <div class="hero-orbs"><span></span><span></span><span></span></div>
  <div class="wrap hero-inner">
    <div class="eyebrow">Advocacia Full Service · OAB/RJ 5.987/2023</div>
    <h1>Decisões difíceis<br>merecem advocacia<br><em>de verdade.</em></h1>
    <p class="lead">Família, Sucessões, Imobiliário, Consumidor e mais — conduzidos com estratégia técnica e o acolhimento que cada caso exige. Você não enfrenta isso sozinho.</p>
    <div class="hero-btns">
      <a href="https://wa.me/<?= $wpp ?>?text=<?= $wppMsg ?>" target="_blank" rel="noopener" class="btn btn-gold">Falar com um advogado</a>
      <a href="#areas" class="btn btn-ghost">Conheça nossas áreas</a>
    </div>
    <div class="hero-trust">
      <div><div class="t-num">+1.000</div><div class="t-lbl">Famílias atendidas</div></div>
      <div><div class="t-num">100%</div><div class="t-lbl">Atendimento digital</div></div>
      <div><div class="t-num">24h</div><div class="t-lbl">Retorno garantido</div></div>
      <div><div class="t-num">Brasil</div><div class="t-lbl">Atuação nacional</div></div>
    </div>
  </div>
</header>

<!-- SOBRE -->
<section class="sec about" id="sobre">
  <div class="wrap about-grid">
    <div class="about-vis reveal">
      <img src="../assets/img/site/escritorio.jpg" alt="Escritório Ferreira &amp; Sá Advocacia" loading="lazy">
      <div class="badge">Sociedade de advogados<br><span>OAB/RJ 5.987</span></div>
    </div>
    <div class="about-txt reveal">
      <div class="eyebrow">O Escritório</div>
      <h2>Técnica jurídica com o cuidado que o seu caso precisa.</h2>
      <p>O Ferreira &amp; Sá Advocacia nasceu da convicção de que um bom resultado jurídico não se constrói só com peças processuais — se constrói com escuta, estratégia e presença. Da causa de família ao contrato imobiliário, cada caso é conduzido por quem entende que ali existe uma história, não um número.</p>
      <p>Unimos rigor técnico, transparência total sobre o andamento do seu processo e um atendimento que não te deixa no escuro. Você acompanha cada passo, fala direto com quem cuida da sua causa e recebe retorno em até 24 horas.</p>
      <div class="about-sign">Amanda Guedes Ferreira &amp; Luiz Eduardo de Sá<small>Sócios-administradores</small></div>
    </div>
  </div>
</section>

<!-- ÁREAS -->
<section class="sec" id="areas">
  <div class="wrap">
    <div class="sec-head center reveal">
      <div class="eyebrow">Áreas de Atuação</div>
      <h2>Soluções jurídicas para cada momento</h2>
      <p>Atuação full service com a profundidade de quem é especialista — do acordo extrajudicial à disputa mais sensível.</p>
    </div>
    <div class="areas-grid reveal">
      <a class="area" href="area.php?a=familia">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M12 21s-7-4.3-7-10a4 4 0 017-2.6A4 4 0 0119 11c0 5.7-7 10-7 10z"/></svg>
        <h3>Direito de Família</h3>
        <p>Divórcio, guarda, pensão alimentícia, união estável e medidas protetivas — conduzidos com técnica e acolhimento.</p>
        <span class="more">Saiba mais →</span>
      </a>
      <a class="area" href="area.php?a=sucessoes">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M4 20V9l8-5 8 5v11M9 20v-6h6v6"/></svg>
        <h3>Sucessões &amp; Inventário</h3>
        <p>Inventário judicial e extrajudicial, testamento, partilha e planejamento sucessório sem desgaste familiar.</p>
        <span class="more">Saiba mais →</span>
      </a>
      <a class="area" href="area.php?a=imobiliario">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M3 21h18M5 21V8l7-5 7 5v13M10 21v-6h4v6"/></svg>
        <h3>Direito Imobiliário</h3>
        <p>Compra e venda, contratos, regularização, distrato, usucapião e disputas sobre imóveis.</p>
        <span class="more">Saiba mais →</span>
      </a>
      <a class="area" href="area.php?a=consumidor">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M6 2h9l4 4v16H6z"/><path d="M9 9h7M9 13h7M9 17h5"/></svg>
        <h3>Direito do Consumidor</h3>
        <p>Cobranças indevidas, negativação, produtos e serviços defeituosos e indenização por danos.</p>
        <span class="more">Saiba mais →</span>
      </a>
      <a class="area" href="area.php?a=civel">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M12 3v18M5 7h14M7 7l-3 7a4 4 0 008 0L9 7M17 7l-3 7a4 4 0 008 0l-3-7"/></svg>
        <h3>Responsabilidade Civil</h3>
        <p>Reparação por danos morais e materiais, acidentes e indenizações com estratégia voltada ao resultado.</p>
        <span class="more">Saiba mais →</span>
      </a>
      <a class="area" href="area.php?a=contratos">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M8 3h8l3 3v15H5V6z"/><path d="M8 11h8M8 15h8"/><circle cx="12" cy="7.5" r="1.2"/></svg>
        <h3>Contratos &amp; Cível</h3>
        <p>Elaboração e revisão de contratos, cobranças, ações cíveis e prevenção de litígios.</p>
        <span class="more">Saiba mais →</span>
      </a>
    </div>
  </div>
</section>

<!-- COMO ATUAMOS -->
<section class="sec proc" id="processo">
  <div class="wrap">
    <div class="sec-head reveal">
      <div class="eyebrow" style="color:var(--rose)">Como Atuamos</div>
      <h2>Sem juridiquês. Sem você no escuro.</h2>
      <p>Um processo claro, do primeiro contato à resolução da sua causa.</p>
    </div>
    <div class="steps reveal">
      <div class="step">
        <div class="n">01</div>
        <h4>Conversa inicial</h4>
        <p>Você nos conta sua situação pelo WhatsApp ou presencialmente. Ouvimos antes de qualquer coisa — e já indicamos o caminho jurídico mais seguro.</p>
      </div>
      <div class="step">
        <div class="n">02</div>
        <h4>Estratégia e proposta</h4>
        <p>Apresentamos um plano de ação claro, prazos realistas e honorários transparentes. Você decide com toda a informação na mão.</p>
      </div>
      <div class="step">
        <div class="n">03</div>
        <h4>Condução &amp; acompanhamento</h4>
        <p>Atuamos no seu caso e você acompanha cada andamento por um portal exclusivo, com retorno em até 24 horas sempre que precisar.</p>
      </div>
    </div>
  </div>
</section>

<!-- DIFERENCIAIS -->
<section class="sec">
  <div class="wrap">
    <div class="sec-head center reveal">
      <div class="eyebrow">Por que o Ferreira &amp; Sá</div>
      <h2>Confiança que se constrói no detalhe</h2>
    </div>
    <div class="dif-grid reveal">
      <div class="dif"><div class="num">+10<small>anos</small></div><h4>Experiência consolidada</h4><p>Atuação dedicada em Direito de Família e Sucessões.</p></div>
      <div class="dif"><div class="num">5<small>cidades</small></div><h4>Presença regional</h4><p>Resende, Volta Redonda, Barra Mansa, Rio de Janeiro e São Paulo.</p></div>
      <div class="dif"><div class="num">24<small>h</small></div><h4>Retorno garantido</h4><p>Toda consulta respondida em até 24 horas úteis.</p></div>
      <div class="dif"><div class="num">100<small>%</small></div><h4>Transparência</h4><p>Portal próprio para acompanhar seu processo em tempo real.</p></div>
    </div>
  </div>
</section>

<!-- ONDE ATUAMOS -->
<section class="sec map-sec" id="atuacao">
  <div class="wrap map-grid">
    <div class="map-vis reveal">
      <img src="../assets/img/site/mapa-brasil.png" alt="Mapa do Brasil — atuação Ferreira &amp; Sá Advocacia" loading="lazy">
    </div>
    <div class="map-txt reveal">
      <div class="eyebrow">Onde Atuamos</div>
      <h2>Para nós, não existe<br><em>distância física.</em></h2>
      <p class="sub">Sede em Barra Mansa–RJ, presença consolidada no Sul Fluminense e atendimento 100% digital para clientes em todo o Brasil. Vamos até você, onde quer que esteja.</p>
      <div class="cov">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 21s-7-4.3-7-10a7 7 0 1114 0c0 5.7-7 10-7 10z"/><circle cx="12" cy="11" r="2.5"/></svg>
        <div><b>Sede física — Barra Mansa / RJ</b><span>Atendimento presencial em ambiente dedicado.</span></div>
      </div>
      <div class="cov">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="9"/><path d="M3.5 9h17M3.5 15h17M12 3a14 14 0 000 18M12 3a14 14 0 010 18"/></svg>
        <div><b>Região Sul Fluminense &amp; RJ</b><span>Resende, Volta Redonda, Barra Mansa, Rio de Janeiro e região.</span></div>
      </div>
      <div class="cov">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 7h16M4 12h16M4 17h16"/><circle cx="8" cy="7" r="1.4" fill="currentColor"/><circle cx="15" cy="12" r="1.4" fill="currentColor"/><circle cx="10" cy="17" r="1.4" fill="currentColor"/></svg>
        <div><b>Todo o Brasil — 100% online</b><span>Consulta, assinatura e acompanhamento digitais, de qualquer cidade.</span></div>
      </div>
    </div>
  </div>
</section>

<!-- EQUIPE -->
<section class="sec team" id="equipe">
  <div class="wrap">
    <div class="sec-head center reveal">
      <div class="eyebrow">Quem cuida da sua causa</div>
      <h2>Advogados que assinam o que defendem</h2>
    </div>
    <div class="team-grid reveal">
      <div class="tc">
        <div class="av"><img src="../assets/img/site/amanda.jpg" alt="Amanda Guedes Ferreira"></div>
        <div>
          <h3>Amanda Guedes Ferreira</h3>
          <div class="oab">OAB/RJ 163.260 · Sócia-administradora</div>
          <p>Especialista em Direito de Família e Sucessões. Conduz pessoalmente as causas mais sensíveis com técnica apurada e o acolhimento que cada cliente merece.</p>
        </div>
      </div>
      <div class="tc">
        <div class="av"><img src="../assets/img/site/luiz.png" alt="Luiz Eduardo de Sá Silva Marcelino"></div>
        <div>
          <h3>Luiz Eduardo de Sá Silva Marcelino</h3>
          <div class="oab">OAB/RJ 248.755 · Sócio-administrador</div>
          <p>Atuação estratégica em demandas de família, responsabilidade civil e direito do consumidor. Visão prática e foco em resultado para o cliente.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- AVALIAÇÕES GOOGLE -->
<?php
// Link pro perfil no Google (abre a ficha/avaliações). Substituir pela URL
// curta do perfil quando tiver (ex.: g.page/...).
$googleUrl = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode('Ferreira e Sá Advocacia Especializada Barra Mansa');
$gLogo = '<svg class="grev-g" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.27-4.74 3.27-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0012 23z"/><path fill="#FBBC05" d="M5.84 14.1a6.6 6.6 0 010-4.2V7.06H2.18a11 11 0 000 9.88l3.66-2.84z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1A11 11 0 002.18 7.06l3.66 2.84C6.71 7.31 9.14 5.38 12 5.38z"/></svg>';
$googleUrl = $grevOk && !empty($grev['url']) ? $grev['url'] : $googleUrl;
function _grev_estrelas($n) { $n = max(0, min(5, (int)round($n))); return str_repeat('★', $n) . str_repeat('☆', 5 - $n); }
$grevRating = $grevOk && $grev['rating'] ? number_format($grev['rating'], 1, ',', '') : '5,0';
?>
<section class="sec">
  <div class="wrap">
    <div class="sec-head center reveal">
      <div class="eyebrow">Avaliações no Google</div>
      <h2>Quem confiou, recomenda</h2>
      <p>Avaliações reais de clientes na ficha do Google do escritório.</p>
    </div>
    <div class="grev-head reveal">
      <div class="grev-badge">
        <?= $gLogo ?>
        <span class="gscore"><?= $grevRating ?></span>
        <span>
          <span class="gstars"><?= _grev_estrelas($grevOk && $grev['rating'] ? $grev['rating'] : 5) ?></span><br>
          <span class="gmeta"><?= $grevOk && $grev['total'] ? ((int)$grev['total'] . ' avaliações no Google') : 'Avaliações verificadas no Google' ?></span>
        </span>
      </div>
      <?php if (!$grevOk): ?><span class="ph-tag">conecte a chave da Places API (set_google_reviews.php) pra puxar as reais</span><?php endif; ?>
    </div>
    <div class="grev-grid reveal">
      <?php if ($grevOk): ?>
        <?php foreach (array_slice($grev['reviews'], 0, 6) as $rv):
          $ini = mb_strtoupper(mb_substr(trim($rv['author']), 0, 1)); ?>
        <div class="grev">
          <div class="grev-top">
            <div class="grev-av"><?= htmlspecialchars($ini ?: 'C', ENT_QUOTES) ?></div>
            <div class="grev-id"><b><?= htmlspecialchars($rv['author'], ENT_QUOTES) ?></b><span class="gstars"><?= _grev_estrelas($rv['rating']) ?></span></div>
            <?= $gLogo ?>
          </div>
          <p><?= nl2br(htmlspecialchars(mb_strimwidth($rv['text'], 0, 320, '…'), ENT_QUOTES)) ?></p>
          <?php if (!empty($rv['relative'])): ?><div class="gdate"><?= htmlspecialchars($rv['relative'], ENT_QUOTES) ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <?php foreach (array('M','R','J') as $ini): ?>
        <div class="grev">
          <div class="grev-top">
            <div class="grev-av"><?= $ini ?></div>
            <div class="grev-id"><b>Cliente Google</b><span class="gstars">★★★★★</span></div>
            <?= $gLogo ?>
          </div>
          <p>As avaliações reais aparecem aqui automaticamente assim que a chave da Places API for cadastrada.</p>
          <div class="gdate">—</div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <div style="text-align:center;">
      <a href="<?= htmlspecialchars($googleUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener" class="grev-cta reveal">
        <?= $gLogo ?> Ver todas as avaliações no Google →
      </a>
    </div>
  </div>
</section>

<!-- FAQ -->
<section class="sec team">
  <div class="wrap">
    <div class="sec-head center reveal">
      <div class="eyebrow">Dúvidas Frequentes</div>
      <h2>O que você precisa saber</h2>
    </div>
    <div class="faq reveal">
      <div class="fitem">
        <button class="fq">A primeira conversa tem custo? <span class="ic">+</span></button>
        <div class="fa"><p>O primeiro contato para entender a sua situação e indicar o caminho jurídico é sem compromisso. Honorários só são definidos — de forma transparente — caso você decida seguir conosco.</p></div>
      </div>
      <div class="fitem">
        <button class="fq">Vocês atendem só presencialmente? <span class="ic">+</span></button>
        <div class="fa"><p>Não. Atendemos 100% online em todo o Brasil — consulta, assinatura de documentos e acompanhamento são digitais. Também recebemos presencialmente nas cidades onde temos escritório.</p></div>
      </div>
      <div class="fitem">
        <button class="fq">Como acompanho o andamento do meu processo? <span class="ic">+</span></button>
        <div class="fa"><p>Você recebe acesso a um portal exclusivo onde vê o andamento em tempo real, além de poder falar direto pelo WhatsApp com retorno em até 24 horas úteis.</p></div>
      </div>
      <div class="fitem">
        <button class="fq">Meu caso é delicado. Posso confiar no sigilo? <span class="ic">+</span></button>
        <div class="fa"><p>Sigilo absoluto é dever ético e prioridade do escritório. Causas de família são tratadas com a discrição máxima, inclusive em processos que correm em segredo de justiça.</p></div>
      </div>
    </div>
  </div>
</section>

<!-- CTA FINAL + FORM -->
<section class="sec cta" id="contato">
  <div class="wrap cta-grid">
    <div class="cta-txt reveal">
      <div class="eyebrow">Vamos conversar</div>
      <h2>O primeiro passo é<br>uma <em>conversa</em>.</h2>
      <p>Conte sua situação. Sem compromisso, sem juridiquês. Recebemos sua mensagem e retornamos em até 24 horas úteis — ou fale agora mesmo pelo WhatsApp.</p>
      <a href="https://wa.me/<?= $wpp ?>?text=<?= $wppMsg ?>" target="_blank" rel="noopener" class="cta-wpp">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M.057 24l1.687-6.163a11.867 11.867 0 01-1.587-5.945C.16 5.335 5.495 0 12.05 0a11.82 11.82 0 018.413 3.488 11.82 11.82 0 013.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 01-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 001.51 5.26l-.999 3.648 3.978-1.207z"/></svg>
        Falar agora pelo WhatsApp
      </a>
    </div>
    <div class="lead-form reveal">
      <h3>Solicite seu atendimento</h3>
      <div class="sub">Preencha e nossa equipe entra em contato.</div>
      <form id="leadForm" autocomplete="on">
        <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
        <input type="hidden" name="ts" value="">
        <input type="hidden" name="origem" value="site-home">
        <input type="hidden" name="pagina" value="">
        <label for="lfNome">Nome completo *</label>
        <input id="lfNome" type="text" name="nome" required maxlength="120" placeholder="Seu nome">
        <label for="lfFone">WhatsApp (com DDD) *</label>
        <input id="lfFone" type="tel" name="telefone" required maxlength="20" placeholder="(24) 99999-9999">
        <label for="lfEmail">E-mail</label>
        <input id="lfEmail" type="email" name="email" maxlength="120" placeholder="seu@email.com (opcional)">
        <label for="lfArea">Como podemos ajudar?</label>
        <select id="lfArea" name="area">
          <option value="">Selecione a área</option>
          <option value="familia">Direito de Família</option>
          <option value="sucessoes">Sucessões e Inventário</option>
          <option value="imobiliario">Direito Imobiliário</option>
          <option value="consumidor">Direito do Consumidor</option>
          <option value="civel">Responsabilidade Civil</option>
          <option value="contratos">Contratos e Cível</option>
        </select>
        <label for="lfMsg">Conte resumidamente seu caso</label>
        <textarea id="lfMsg" name="mensagem" maxlength="1200" placeholder="Opcional — quanto mais detalhes, melhor podemos te orientar."></textarea>
        <button type="submit" id="lfBtn">Enviar e ser contatado</button>
        <div class="lf-msg" id="lfFeedback"></div>
        <div class="lf-priv">Seus dados são tratados com sigilo e usados apenas para o seu atendimento.</div>
      </form>
    </div>
  </div>
</section>
<script>
(function(){
  var f=document.getElementById('leadForm'); if(!f) return;
  f.ts.value=Date.now(); f.pagina.value=location.href.slice(0,300);
  f.addEventListener('submit',function(e){
    e.preventDefault();
    var btn=document.getElementById('lfBtn'), fb=document.getElementById('lfFeedback');
    fb.className='lf-msg'; fb.textContent='';
    btn.disabled=true; var label=btn.textContent; btn.textContent='Enviando…';
    fetch('/conecta/publico/lead_site.php',{method:'POST',body:new FormData(f)})
      .then(function(r){return r.json();})
      .then(function(j){
        if(j&&j.ok){
          f.reset();
          fb.className='lf-msg ok';
          fb.textContent='✓ Recebemos! Em breve nossa equipe entra em contato. Protocolo: '+(j.protocol||'OK');
          btn.textContent='Enviado ✓';
        }else{
          fb.className='lf-msg err';
          fb.textContent=(j&&j.error)?j.error:'Não foi possível enviar. Tente pelo WhatsApp.';
          btn.disabled=false; btn.textContent=label;
        }
      })
      .catch(function(){
        fb.className='lf-msg err';
        fb.textContent='Falha de conexão. Fale com a gente pelo WhatsApp.';
        btn.disabled=false; btn.textContent=label;
      });
  });
})();
</script>

<!-- FOOTER -->
<footer class="foot">
  <div class="wrap">
    <div class="foot-grid">
      <div>
        <div class="foot-logo">FERREIRA &amp; SÁ</div>
        <p style="color:rgba(255,255,255,.5);max-width:300px">Advocacia full service — Família, Sucessões, Imobiliário e Consumidor. Técnica, transparência e acolhimento em cada causa.</p>
        <a href="/salavip/" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:.5rem;margin-top:1.2rem;border:1px solid rgba(215,171,144,.45);color:var(--rose);padding:.6rem 1.2rem;border-radius:100px;font-weight:600;font-size:.82rem;">🔒 Área do Cliente · acompanhe seu processo</a>
      </div>
      <div>
        <h5>Contato</h5>
        <p><a href="https://wa.me/<?= $wpp ?>">WhatsApp · (24) 99205-0096</a></p>
        <p><a href="https://wa.me/551121105438">WhatsApp · (11) 2110-5438</a></p>
        <p><a href="mailto:contato@ferreiraesa.com.br">contato@ferreiraesa.com.br</a></p>
        <p style="margin-top:.4rem;">Rua Dr. Aldrovando de Oliveira, 140<br>Ano Bom — Barra Mansa / RJ</p>
      </div>
      <div>
        <h5>Atuação</h5>
        <p>Barra Mansa · Volta Redonda</p>
        <p>Resende · Rio de Janeiro</p>
        <p>São Paulo · Todo o Brasil (online)</p>
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
// Nav sólida no scroll
var nav=document.getElementById('nav');
addEventListener('scroll',function(){nav.classList.toggle('solid',scrollY>40)},{passive:true});
// Reveal on scroll
var io=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){e.target.classList.add('in');io.unobserve(e.target)}})},{threshold:.12});
document.querySelectorAll('.reveal').forEach(function(el){io.observe(el)});
// FAQ
document.querySelectorAll('.fq').forEach(function(b){b.addEventListener('click',function(){
  var it=b.parentElement,fa=it.querySelector('.fa'),open=it.classList.contains('open');
  document.querySelectorAll('.fitem').forEach(function(x){x.classList.remove('open');x.querySelector('.fa').style.maxHeight=null});
  if(!open){it.classList.add('open');fa.style.maxHeight=fa.scrollHeight+'px'}
})});
</script>
</body>
</html>
