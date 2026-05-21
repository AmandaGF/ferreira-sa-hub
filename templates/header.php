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
