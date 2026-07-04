<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <script>var _appBase = '<?= rtrim(url(''), '/') ?>';</script>
    <meta name="theme-color" content="#052228">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="F&S Hub">
    <meta name="mobile-web-app-capable" content="yes">
    <link rel="manifest" href="<?= url('manifest.json') ?>">
    <link rel="icon" type="image/svg+xml" href="<?= url('assets/img/favicon.svg') ?>">
    <link rel="icon" type="image/png" href="<?= url('assets/img/logo.png') ?>">
    <link rel="apple-touch-icon" href="<?= url('assets/img/logo-sidebar.png') ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= url('assets/img/logo-sidebar.png') ?>">
    <title><?= e($pageTitle ?? 'Painel') ?> — F&amp;S Hub</title>
    <link rel="stylesheet" href="<?= url('assets/css/conecta.css') ?>">
    <?php if (!empty($extraCss)): ?>
        <style><?= $extraCss ?></style>
    <?php endif; ?>
</head>
<body>
<!-- Splash de abertura do app (PWA instalado) — só no cold-launch, 1×/sessão -->
<div id="fsaSplash" style="position:fixed;inset:0;z-index:999999;display:none;flex-direction:column;align-items:center;justify-content:center;gap:1.1rem;background:radial-gradient(circle at 50% 34%,#0d3d47,#041a20);color:#fff;">
    <img src="<?= url('assets/img/pwa-icon.svg') ?>" alt="" style="width:108px;height:108px;border-radius:26px;box-shadow:0 12px 34px rgba(0,0,0,.45);">
    <div style="font-family:Georgia,'Times New Roman',serif;font-size:1.45rem;font-weight:700;letter-spacing:.3px;">Ferreira &amp; Sá <span style="color:#c78a4e;">Hub</span></div>
    <div style="width:130px;height:4px;border-radius:2px;background:rgba(255,255,255,.15);overflow:hidden;">
        <div style="width:45%;height:100%;background:#c78a4e;border-radius:2px;animation:fsaSplashSlide 1.05s ease-in-out infinite;"></div>
    </div>
</div>
<style>@keyframes fsaSplashSlide{0%{transform:translateX(-100%)}100%{transform:translateX(289%)}}</style>
<script>
(function(){
    try{
        var s=document.getElementById('fsaSplash'); if(!s) return;
        var standalone=window.matchMedia('(display-mode: standalone)').matches||window.navigator.standalone===true;
        if(standalone && !sessionStorage.getItem('fsaSplashDone')){
            sessionStorage.setItem('fsaSplashDone','1');
            s.style.display='flex';
            var t0=Date.now(), fim=function(){ s.style.transition='opacity .35s'; s.style.opacity='0'; setTimeout(function(){ if(s&&s.parentNode) s.parentNode.removeChild(s); },360); };
            window.addEventListener('load',function(){ setTimeout(fim, Math.max(0,650-(Date.now()-t0))); });
            setTimeout(function(){ if(s&&s.parentNode) fim(); },4000); // trava de segurança
        } else { s.parentNode.removeChild(s); }
    }catch(e){ var el=document.getElementById('fsaSplash'); if(el&&el.parentNode) el.parentNode.removeChild(el); }
})();
</script>
