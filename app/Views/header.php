<!doctype html>
<html lang="<?= e(lang()) ?>">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#0f1115">
    <meta name="description" content="<?= e($description ?? setting('seo_description')) ?>">
    <title><?= e($title ?? setting('site_name')) ?></title>
    <link rel="icon" href="<?= e(url('assets/logo.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>">
    <?php if (isset($section) && $section === 'blog'): ?>
        <link rel="stylesheet" href="<?= e(url('assets/vendor/quill/quill.snow.css')) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= e(url('assets/blog.css')) ?>">
    <?php if (!empty($canonical)): ?>
        <link rel="canonical" href="<?= e($canonical) ?>">
    <?php endif; ?>
    <script src="<?= e(url('assets/theme.js')) ?>" defer></script>

    <?php require ROOT . '/app/Views/chat-presence-head.php'; ?>
</head>

<body>
    <header class="topbar wrap"><a class="brand" href="<?= e(url()) ?>"><img src="<?= e(url('assets/logo.svg')) ?>"
                alt="" width="38" height="38"><span><?= e(setting('site_name')) ?></span><span class="beta-label">Бета
                версия 1.0</span></a>
        <nav class="topnav" aria-label="<?= e(t('settings')) ?>"><a class="language-flag" href="?lang=ru" lang="ru"
                aria-label="Русский" title="Русский" <?= lang() === 'ru' ? 'aria-current="page"' : '' ?>><img
                    src="<?= e(url('assets/flags/ru.png')) ?>" alt="" width="24" height="16"></a><a
                class="language-flag" href="?lang=ky" lang="ky" aria-label="Кыргызча" title="Кыргызча" <?= lang() === 'ky' ? 'aria-current="page"' : '' ?>><img src="<?= e(url('assets/flags/kg.png')) ?>" alt="" width="24"
                    height="16"></a><button type="button" class="icon-button" id="theme"
                aria-label="<?= e(t('theme')) ?>"><svg width="21" height="21" aria-hidden="true">
                    <use href="<?= e(url('assets/icons.svg')) ?>#moon"></use>
                </svg></button></nav>
    </header>