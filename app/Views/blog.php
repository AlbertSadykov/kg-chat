<?php
$title = 'Блог · ' . setting('site_name');
$description = lang() === 'ky' ? 'Баарлашуу, таанышуу жана интернеттеги коопсуздук жөнүндө макалалар.' : 'Статьи об общении, знакомствах и безопасности в интернете.';
require ROOT . '/app/Views/header.php';
?>
<main class="wrap blog-index">
    <a class="blog-back" href="<?= e(url()) ?>">← <?= lang() === 'ky' ? 'Башкы бет' : 'На главную' ?></a>
    <div class="blog-section-heading">
        <div><span class="blog-eyebrow"><?= lang() === 'ky' ? 'Кызыктуу жана пайдалуу' : 'Интересное и полезное' ?></span><h1>Блог</h1></div>
    </div>
    <?php if ($posts): ?>
        <?php require ROOT . '/app/Views/blog-cards.php'; ?>
    <?php else: ?>
        <div class="panel"><p class="muted"><?= lang() === 'ky' ? 'Макалалар жакында чыгат.' : 'Статьи скоро появятся.' ?></p></div>
    <?php endif; ?>
    <?php if ($pageNumber > 1 || $hasNext): ?>
        <nav class="pagination" aria-label="<?= e(t('page')) ?>">
            <?php if ($pageNumber > 1): ?><a href="<?= e(url('blog')) ?>?page=<?= $pageNumber - 1 ?>">← <?= e(t('previous')) ?></a><?php endif; ?>
            <span><?= (int) $pageNumber ?></span>
            <?php if ($hasNext): ?><a href="<?= e(url('blog')) ?>?page=<?= $pageNumber + 1 ?>"><?= e(t('next')) ?> →</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</main>
<?php require ROOT . '/app/Views/footer.php'; ?>
