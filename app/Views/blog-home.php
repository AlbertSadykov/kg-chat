<?php
$posts = \App\Models\Blog::latest(3);
if (!$posts) {
    return;
}
?>
<section class="blog-home wrap" aria-labelledby="blog-home-title">
    <div class="blog-section-heading">
        <div>
            <span class="blog-eyebrow"><?= lang() === 'ky' ? 'Кызыктуу жана пайдалуу' : 'Интересное и полезное' ?></span>
            <h2 id="blog-home-title">Блог</h2>
        </div>
        <a class="button secondary" href="<?= e(url('blog')) ?>"><?= lang() === 'ky' ? 'Бардык макалалар' : 'Все статьи' ?> <span aria-hidden="true">↗</span></a>
    </div>
    <?php require ROOT . '/app/Views/blog-cards.php'; ?>
</section>
