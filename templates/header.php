<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#052228">
    <link rel="manifest" href="<?= url('manifest.json') ?>">
    <link rel="icon" type="image/png" href="<?= url('assets/img/logo.png') ?>">
    <link rel="apple-touch-icon" href="<?= url('assets/img/logo.png') ?>">
    <title><?= e($pageTitle ?? 'Painel') ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= url('assets/css/conecta.css') ?>">
    <?php if (!empty($extraCss)): ?>
        <style><?= $extraCss ?></style>
    <?php endif; ?>
</head>
<body>
