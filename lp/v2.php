<?php
/**
 * MOCKUP — Novo site institucional Ferreira & Sá Advocacia (v2)
 * Standalone, sem login. Preview: /conecta/lp/v2.php
 * Copy persuasivo + placeholders marcados (fotos/depoimentos/números a validar).
 */
$ano = date('Y');
$wpp = '5524992050096';
$wppMsg = rawurlencode('Olá! Vim pelo site e gostaria de conversar com um advogado.');
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
<link rel="icon" type="image/png" href="../assets/img/logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --petrol:#052228; --petrol-2:#0c3540; --petrol-3:#15505c;
  --rose:#d7ab90; --rose-2:#c4936f; --gold:#caa46a;
  --cream:#f7f4ef; --paper:#fffdfb; --ink:#1c1c1c; --muted:#6f7370;
  --line:rgba(5,34,40,.08);
  --serif:'Cormorant Garamond',Georgia,serif;
  --sans:'Inter',system-ui,sans-serif;
}
html{scroll-behavior:smooth}
body{font-family:var(--sans);color:var(--ink);background:var(--paper);line-height:1.7;-webkit-font-smoothing:antialiased}
a{text-decoration:none;color:inherit}
img{max-width:100%;display:block}
section{position:relative}
.wrap{max-width:1180px;margin:0 auto;padding:0 1.5rem}
.eyebrow{font-size:.72rem;letter-spacing:.22em;text-transform:uppercase;font-weight:600;color:var(--rose-2)}
.h-serif{font-family:var(--serif);font-weight:600;line-height:1.15;letter-spacing:.005em}
.reveal{opacity:0;transform:translateY(26px);transition:opacity .9s ease,transform .9s ease}
.reveal.in{opacity:1;transform:none}

/* NAV */
.nav{position:fixed;inset:0 0 auto 0;z-index:100;display:flex;align-items:center;justify-content:space-between;
  padding:1.1rem 2rem;transition:background .35s,padding .35s,box-shadow .35s}
.nav.solid{background:rgba(5,34,40,.97);backdrop-filter:blur(12px);padding:.7rem 2rem;box-shadow:0 10px 40px rgba(0,0,0,.18)}
.nav-logo{display:flex;align-items:center;gap:.7rem;color:#fff;font-family:var(--serif);font-size:1.15rem;font-weight:700;letter-spacing:.06em}
.nav-logo img{height:38px;width:auto;filter:brightness(0) invert(1);opacity:.95}
.nav-links{display:flex;align-items:center;gap:2rem}
.nav-links a{color:rgba(255,255,255,.78);font-size:.82rem;font-weight:500;letter-spacing:.03em;transition:color .2s}
.nav-links a:hover{color:var(--rose)}
.nav-cta{border:1px solid rgba(215,171,144,.55);color:var(--rose)!important;padding:.55rem 1.4rem;border-radius:2px;
  font-weight:600!important;transition:all .25s}
.nav-cta:hover{background:var(--rose);color:var(--petrol)!important}
.burger{display:none;background:none;border:0;color:#fff;font-size:1.5rem;cursor:pointer}

/* HERO */
.hero{min-height:100vh;display:flex;align-items:center;
  background:radial-gradient(140% 120% at 80% 0%,var(--petrol-3) 0%,var(--petrol-2) 38%,var(--petrol) 70%);
  color:#fff;overflow:hidden;padding:7rem 0 5rem}
.hero::before{content:"";position:absolute;inset:0;
  background:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='60' height='60'%3E%3Cpath d='M0 0h60v60H0z' fill='none'/%3E%3Cpath d='M30 0v60M0 30h60' stroke='%23ffffff' stroke-opacity='.025'/%3E%3C/svg%3E");
  pointer-events:none}
.hero-orbs{position:absolute;inset:0;z-index:1;overflow:hidden;pointer-events:none}
.hero-orbs span{position:absolute;border-radius:50%;filter:blur(60px);opacity:.55;
  background:radial-gradient(circle,rgba(215,171,144,.5),transparent 65%);will-change:transform}
.hero-orbs span:nth-child(1){width:560px;height:560px;top:-160px;right:-120px;animation:drift1 22s ease-in-out infinite alternate}
.hero-orbs span:nth-child(2){width:460px;height:460px;bottom:-180px;left:-120px;
  background:radial-gradient(circle,rgba(21,80,92,.6),transparent 65%);animation:drift2 28s ease-in-out infinite alternate}
.hero-orbs span:nth-child(3){width:340px;height:340px;top:38%;left:46%;opacity:.35;
  background:radial-gradient(circle,rgba(202,164,106,.45),transparent 65%);animation:drift3 19s ease-in-out infinite alternate}
@keyframes drift1{0%{transform:translate(0,0) scale(1)}100%{transform:translate(-90px,70px) scale(1.18)}}
@keyframes drift2{0%{transform:translate(0,0) scale(1)}100%{transform:translate(110px,-60px) scale(1.12)}}
@keyframes drift3{0%{transform:translate(0,0) scale(1)}50%{transform:translate(-60px,-40px) scale(1.1)}100%{transform:translate(50px,50px) scale(.95)}}
@media(prefers-reduced-motion:reduce){.hero-orbs span{animation:none}}
.hero-inner{position:relative;z-index:2;max-width:760px}
.hero .eyebrow{margin-bottom:1.4rem;display:inline-flex;align-items:center;gap:.7rem}
.hero .eyebrow::before{content:"";width:38px;height:1px;background:var(--rose)}
.hero h1{font-family:var(--serif);font-size:clamp(2.5rem,5.4vw,4.2rem);font-weight:600;line-height:1.08;margin-bottom:1.5rem}
.hero h1 em{font-style:italic;color:var(--rose)}
.hero p.lead{font-size:1.12rem;color:rgba(255,255,255,.74);max-width:560px;margin-bottom:2.4rem}
.hero-btns{display:flex;gap:1rem;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:.6rem;padding:1rem 2.1rem;border-radius:2px;font-weight:600;
  font-size:.92rem;font-family:inherit;cursor:pointer;border:1px solid transparent;transition:all .28s;letter-spacing:.02em}
.btn-gold{background:var(--rose);color:var(--petrol)}
.btn-gold:hover{background:#fff;transform:translateY(-2px);box-shadow:0 16px 38px rgba(215,171,144,.32)}
.btn-ghost{border-color:rgba(255,255,255,.28);color:#fff}
.btn-ghost:hover{border-color:var(--rose);color:var(--rose)}
.hero-trust{position:relative;z-index:2;display:flex;flex-wrap:wrap;gap:2.6rem;margin-top:4rem;
  padding-top:2.2rem;border-top:1px solid rgba(255,255,255,.12)}
.hero-trust div .t-num{font-family:var(--serif);font-size:2rem;font-weight:700;color:var(--rose)}
.hero-trust div .t-lbl{font-size:.74rem;letter-spacing:.13em;text-transform:uppercase;color:rgba(255,255,255,.55)}

/* SECTION SHELL */
.sec{padding:6.5rem 0}
.sec-head{max-width:640px;margin-bottom:3.5rem}
.sec-head.center{margin-left:auto;margin-right:auto;text-align:center}
.sec-head .eyebrow{display:inline-flex;align-items:center;gap:.7rem;margin-bottom:1.1rem}
.sec-head .eyebrow::before{content:"";width:34px;height:1px;background:var(--rose-2)}
.sec-head.center .eyebrow::after{content:"";width:34px;height:1px;background:var(--rose-2)}
.sec-head h2{font-family:var(--serif);font-size:clamp(2rem,3.6vw,2.9rem);font-weight:600;color:var(--petrol);line-height:1.15;margin-bottom:1rem}
.sec-head p{color:var(--muted);font-size:1.02rem}

/* INTRO / SOBRE */
.about{background:var(--cream)}
.about-grid{display:grid;grid-template-columns:1fr 1fr;gap:4.5rem;align-items:center}
.about-vis{position:relative;aspect-ratio:4/5;border-radius:3px;overflow:hidden;
  background:linear-gradient(150deg,var(--petrol),var(--petrol-3));display:flex;align-items:flex-end;
  box-shadow:0 30px 70px rgba(5,34,40,.22)}
.about-vis img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:38% center}
.about-vis::after{content:"";position:absolute;inset:0;background:linear-gradient(to top,rgba(5,34,40,.55),transparent 45%)}
.about-vis .badge{position:absolute;left:1.6rem;bottom:1.6rem;z-index:2;background:rgba(255,255,255,.94);color:var(--petrol);
  padding:1rem 1.4rem;border-radius:2px;font-size:.78rem;font-weight:600;letter-spacing:.05em}
.about-vis .badge span{display:block;font-family:var(--serif);font-size:1.5rem;font-weight:700;color:var(--rose-2)}
.about-txt h2{font-family:var(--serif);font-size:clamp(1.9rem,3.4vw,2.7rem);font-weight:600;color:var(--petrol);
  line-height:1.18;margin:.9rem 0 1.4rem}
.about-txt p{color:var(--muted);margin-bottom:1.1rem}
.about-sign{margin-top:1.8rem;font-family:var(--serif);font-size:1.3rem;color:var(--petrol);font-weight:600}
.about-sign small{display:block;font-family:var(--sans);font-size:.74rem;letter-spacing:.12em;
  text-transform:uppercase;color:var(--rose-2);margin-top:.2rem}

/* ÁREAS */
.areas-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:var(--line);
  border:1px solid var(--line);border-radius:3px;overflow:hidden}
.area{background:var(--paper);padding:2.6rem 2rem;transition:background .3s}
.area:hover{background:var(--cream)}
.area .ico{width:46px;height:46px;color:var(--rose-2);margin-bottom:1.3rem}
.area h3{font-family:var(--serif);font-size:1.4rem;font-weight:600;color:var(--petrol);margin-bottom:.5rem}
.area p{font-size:.9rem;color:var(--muted);line-height:1.65}
.area .more{display:inline-block;margin-top:1rem;font-size:.78rem;font-weight:600;letter-spacing:.06em;
  color:var(--rose-2);text-transform:uppercase}

/* PROCESSO */
.proc{background:var(--petrol);color:#fff}
.proc .sec-head h2{color:#fff}
.proc .sec-head p{color:rgba(255,255,255,.6)}
.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:2.5rem;margin-top:1rem}
.step{position:relative;padding-top:2.4rem}
.step .n{font-family:var(--serif);font-size:3.4rem;font-weight:700;color:rgba(215,171,144,.28);position:absolute;top:-1rem;left:0}
.step h4{font-family:var(--serif);font-size:1.3rem;font-weight:600;margin-bottom:.5rem;position:relative}
.step p{font-size:.9rem;color:rgba(255,255,255,.62)}

/* DIFERENCIAIS */
.dif-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:2.5rem}
.dif{text-align:center;padding:1rem}
.dif .num{font-family:var(--serif);font-size:3rem;font-weight:700;color:var(--petrol);line-height:1}
.dif .num small{font-size:1.3rem;color:var(--rose-2)}
.dif h4{font-size:.92rem;font-weight:700;color:var(--petrol);margin:.7rem 0 .4rem;letter-spacing:.02em}
.dif p{font-size:.82rem;color:var(--muted)}

/* ONDE ATUAMOS */
.map-sec{background:var(--petrol);color:#fff;overflow:hidden}
.map-sec::after{content:"";position:absolute;left:-10%;top:20%;width:480px;height:480px;border-radius:50%;
  background:radial-gradient(circle,rgba(215,171,144,.12),transparent 65%);pointer-events:none}
.map-grid{display:grid;grid-template-columns:1.05fr 1fr;gap:4.5rem;align-items:center;position:relative;z-index:2}
.map-vis{background:var(--cream);border-radius:4px;padding:2.4rem;box-shadow:0 34px 80px rgba(0,0,0,.34)}
.map-vis img{width:100%;height:auto;display:block;filter:drop-shadow(0 8px 18px rgba(0,0,0,.12))}
.map-txt .eyebrow{display:inline-flex;align-items:center;gap:.7rem;color:var(--rose);margin-bottom:1.1rem}
.map-txt .eyebrow::before{content:"";width:34px;height:1px;background:var(--rose)}
.map-txt h2{font-family:var(--serif);font-size:clamp(1.9rem,3.4vw,2.7rem);font-weight:600;line-height:1.16;margin-bottom:1.1rem}
.map-txt h2 em{font-style:italic;color:var(--rose)}
.map-txt .sub{color:rgba(255,255,255,.66);margin-bottom:1.8rem}
.cov{display:flex;gap:1.1rem;padding:1.15rem 0;border-bottom:1px solid rgba(255,255,255,.1)}
.cov:last-child{border-bottom:0}
.cov svg{width:26px;height:26px;color:var(--rose);flex-shrink:0;margin-top:2px}
.cov b{display:block;font-size:.98rem;color:#fff;font-weight:600}
.cov span{font-size:.86rem;color:rgba(255,255,255,.6)}

/* EQUIPE */
.team{background:var(--cream)}
.team-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:2rem;max-width:880px;margin:0 auto}
.tc{background:var(--paper);border:1px solid var(--line);border-radius:3px;padding:2.6rem;display:flex;gap:1.6rem;align-items:flex-start}
.tc .av{flex-shrink:0;width:108px;height:108px;border-radius:50%;overflow:hidden;
  background:linear-gradient(150deg,var(--petrol),var(--petrol-3));border:2px solid var(--rose);position:relative}
.tc .av img{width:100%;height:100%;object-fit:cover;object-position:center top}
.tc h3{font-family:var(--serif);font-size:1.35rem;font-weight:600;color:var(--petrol)}
.tc .oab{font-size:.76rem;font-weight:600;letter-spacing:.06em;color:var(--rose-2);text-transform:uppercase;margin:.2rem 0 .7rem}
.tc p{font-size:.88rem;color:var(--muted);line-height:1.6}

/* DEPOIMENTOS */
.quotes-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem}
.q{background:var(--paper);border:1px solid var(--line);border-radius:3px;padding:2.2rem}
.q .mark{font-family:var(--serif);font-size:3rem;color:var(--rose);line-height:.6;margin-bottom:.6rem}
.q p{font-size:.92rem;color:var(--ink);font-style:italic;margin-bottom:1.3rem}
.q .who{font-size:.8rem;font-weight:600;color:var(--petrol)}
.q .who small{display:block;font-weight:400;color:var(--muted);font-style:normal}
.ph-tag{display:inline-block;margin-top:.5rem;font-size:.62rem;letter-spacing:.1em;text-transform:uppercase;
  color:var(--rose-2);border:1px dashed var(--rose);padding:.15rem .5rem;border-radius:2px}

/* FAQ */
.faq{max-width:780px;margin:0 auto}
.fitem{border-bottom:1px solid var(--line)}
.fq{width:100%;text-align:left;background:none;border:0;padding:1.5rem 0;font-family:var(--sans);
  font-size:1.02rem;font-weight:600;color:var(--petrol);cursor:pointer;display:flex;justify-content:space-between;gap:1rem}
.fq span.ic{color:var(--rose-2);transition:transform .3s;flex-shrink:0}
.fitem.open .fq span.ic{transform:rotate(45deg)}
.fa{max-height:0;overflow:hidden;transition:max-height .35s ease}
.fa p{padding:0 0 1.5rem;color:var(--muted);font-size:.94rem}

/* CTA FINAL */
.cta{background:radial-gradient(120% 130% at 20% 0%,var(--petrol-3),var(--petrol) 60%);color:#fff;text-align:center}
.cta h2{font-family:var(--serif);font-size:clamp(2rem,4vw,3.2rem);font-weight:600;line-height:1.12;margin-bottom:1.1rem}
.cta h2 em{font-style:italic;color:var(--rose)}
.cta p{color:rgba(255,255,255,.7);max-width:520px;margin:0 auto 2.4rem}

/* FOOTER */
.foot{background:#031417;color:rgba(255,255,255,.55);padding:4.5rem 0 2.5rem;font-size:.86rem}
.foot-grid{display:grid;grid-template-columns:1.4fr 1fr 1fr;gap:3rem;margin-bottom:3rem}
.foot h5{color:#fff;font-family:var(--serif);font-size:1.05rem;font-weight:600;margin-bottom:1.1rem;letter-spacing:.04em}
.foot a{color:rgba(255,255,255,.6);transition:color .2s}
.foot a:hover{color:var(--rose)}
.foot p{margin-bottom:.55rem;line-height:1.6}
.foot-logo{font-family:var(--serif);font-size:1.4rem;color:#fff;font-weight:700;letter-spacing:.06em;margin-bottom:1rem}
.foot-bottom{border-top:1px solid rgba(255,255,255,.08);padding-top:2rem;text-align:center;
  font-size:.74rem;color:rgba(255,255,255,.38);line-height:1.8}

/* WPP FLOAT */
.wpp{position:fixed;right:1.6rem;bottom:1.6rem;z-index:200;width:58px;height:58px;border-radius:50%;
  background:#25D366;display:flex;align-items:center;justify-content:center;
  box-shadow:0 10px 30px rgba(37,211,102,.4);transition:transform .3s}
.wpp:hover{transform:scale(1.08)}

/* RESPONSIVO */
@media(max-width:960px){
  .areas-grid{grid-template-columns:repeat(2,1fr)}
  .dif-grid{grid-template-columns:repeat(2,1fr);gap:2.5rem 1.5rem}
  .quotes-grid{grid-template-columns:1fr}
  .about-grid{grid-template-columns:1fr;gap:2.5rem}
  .map-grid{grid-template-columns:1fr;gap:2.8rem}
  .map-vis{max-width:440px;margin:0 auto}
  .steps{grid-template-columns:1fr;gap:2rem}
  .team-grid{grid-template-columns:1fr}
  .foot-grid{grid-template-columns:1fr;gap:2rem}
}
@media(max-width:680px){
  .nav-links{display:none}
  .burger{display:block}
  .sec{padding:4.5rem 0}
  .areas-grid{grid-template-columns:1fr}
  .dif-grid{grid-template-columns:1fr}
  .tc{flex-direction:column;align-items:center;text-align:center}
  .hero-trust{gap:1.8rem}
}
</style>
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
    <a href="https://wa.me/<?= $wpp ?>?text=<?= $wppMsg ?>" target="_blank" rel="noopener" class="nav-cta">Agendar Consulta</a>
  </div>
  <button class="burger" onclick="document.querySelector('.nav-links').style.cssText='display:flex;position:absolute;top:100%;right:0;left:0;flex-direction:column;background:var(--petrol);padding:1.5rem 2rem;gap:1.2rem'">☰</button>
</nav>

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
      <div class="area">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M12 21s-7-4.3-7-10a4 4 0 017-2.6A4 4 0 0119 11c0 5.7-7 10-7 10z"/></svg>
        <h3>Direito de Família</h3>
        <p>Divórcio, guarda, pensão alimentícia, união estável e medidas protetivas — conduzidos com técnica e acolhimento.</p>
        <span class="more">Saiba mais →</span>
      </div>
      <div class="area">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M4 20V9l8-5 8 5v11M9 20v-6h6v6"/></svg>
        <h3>Sucessões &amp; Inventário</h3>
        <p>Inventário judicial e extrajudicial, testamento, partilha e planejamento sucessório sem desgaste familiar.</p>
        <span class="more">Saiba mais →</span>
      </div>
      <div class="area">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M3 21h18M5 21V8l7-5 7 5v13M10 21v-6h4v6"/></svg>
        <h3>Direito Imobiliário</h3>
        <p>Compra e venda, contratos, regularização, distrato, usucapião e disputas sobre imóveis.</p>
        <span class="more">Saiba mais →</span>
      </div>
      <div class="area">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M6 2h9l4 4v16H6z"/><path d="M9 9h7M9 13h7M9 17h5"/></svg>
        <h3>Direito do Consumidor</h3>
        <p>Cobranças indevidas, negativação, produtos e serviços defeituosos e indenização por danos.</p>
        <span class="more">Saiba mais →</span>
      </div>
      <div class="area">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M12 3v18M5 7h14M7 7l-3 7a4 4 0 008 0L9 7M17 7l-3 7a4 4 0 008 0l-3-7"/></svg>
        <h3>Responsabilidade Civil</h3>
        <p>Reparação por danos morais e materiais, acidentes e indenizações com estratégia voltada ao resultado.</p>
        <span class="more">Saiba mais →</span>
      </div>
      <div class="area">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M8 3h8l3 3v15H5V6z"/><path d="M8 11h8M8 15h8"/><circle cx="12" cy="7.5" r="1.2"/></svg>
        <h3>Contratos &amp; Cível</h3>
        <p>Elaboração e revisão de contratos, cobranças, ações cíveis e prevenção de litígios.</p>
        <span class="more">Saiba mais →</span>
      </div>
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
      <p class="sub">Sede em Resende–RJ, presença consolidada no Sul Fluminense e atendimento 100% digital para clientes em todo o Brasil. Vamos até você, onde quer que esteja.</p>
      <div class="cov">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 21s-7-4.3-7-10a7 7 0 1114 0c0 5.7-7 10-7 10z"/><circle cx="12" cy="11" r="2.5"/></svg>
        <div><b>Sede física — Resende / RJ</b><span>Atendimento presencial em ambiente dedicado.</span></div>
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

<!-- DEPOIMENTOS -->
<section class="sec">
  <div class="wrap">
    <div class="sec-head center reveal">
      <div class="eyebrow">Quem confiou</div>
      <h2>Histórias que terminaram bem</h2>
      <p>Depoimentos reais de clientes <span class="ph-tag">substituir por depoimentos verdadeiros</span></p>
    </div>
    <div class="quotes-grid reveal">
      <div class="q">
        <div class="mark">&ldquo;</div>
        <p>Cheguei perdida e com medo. Saí com meu divórcio resolvido e a sensação de que alguém realmente cuidou de mim do início ao fim.</p>
        <div class="who">M. C. <small>Divórcio — Volta Redonda</small></div>
      </div>
      <div class="q">
        <div class="mark">&ldquo;</div>
        <p>Explicaram tudo numa linguagem que eu entendia. Nunca fiquei sem resposta. Recomendo de olhos fechados.</p>
        <div class="who">R. S. <small>Guarda — Resende</small></div>
      </div>
      <div class="q">
        <div class="mark">&ldquo;</div>
        <p>Profissionalismo do começo ao fim. Resolveram o inventário da minha família sem brigas e sem dor de cabeça.</p>
        <div class="who">J. P. <small>Inventário — Barra Mansa</small></div>
      </div>
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

<!-- CTA FINAL -->
<section class="sec cta" id="contato">
  <div class="wrap reveal">
    <div class="eyebrow" style="color:var(--rose);justify-content:center;display:inline-flex;gap:.7rem">Vamos conversar</div>
    <h2>O primeiro passo é<br>uma <em>conversa</em>.</h2>
    <p>Conte sua situação. Sem compromisso, sem juridiquês. A gente te diz com clareza o que dá pra fazer.</p>
    <a href="https://wa.me/<?= $wpp ?>?text=<?= $wppMsg ?>" target="_blank" rel="noopener" class="btn btn-gold" style="font-size:1rem">Falar agora pelo WhatsApp</a>
  </div>
</section>

<!-- FOOTER -->
<footer class="foot">
  <div class="wrap">
    <div class="foot-grid">
      <div>
        <div class="foot-logo">FERREIRA &amp; SÁ</div>
        <p style="color:rgba(255,255,255,.5);max-width:300px">Advocacia full service — Família, Sucessões, Imobiliário e Consumidor. Técnica, transparência e acolhimento em cada causa.</p>
      </div>
      <div>
        <h5>Contato</h5>
        <p><a href="https://wa.me/<?= $wpp ?>">WhatsApp · (24) 99205-0096</a></p>
        <p><a href="https://wa.me/551121105438">WhatsApp · (11) 2110-5438</a></p>
        <p><a href="mailto:contato@ferreiraesa.com.br">contato@ferreiraesa.com.br</a></p>
      </div>
      <div>
        <h5>Atuação</h5>
        <p>Resende · Volta Redonda</p>
        <p>Barra Mansa · Rio de Janeiro</p>
        <p>São Paulo · Todo o Brasil (online)</p>
      </div>
    </div>
    <div class="foot-bottom">
      &copy; <?= $ano ?> Ferreira &amp; Sá Sociedade de Advogados — CNPJ 51.294.223/0001-40 — OAB/RJ 5.987/2023<br>
      Este site tem caráter meramente informativo, em conformidade com o Código de Ética e Disciplina da OAB.
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
