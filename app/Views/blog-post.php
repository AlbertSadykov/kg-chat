<?php
$title = ($post['meta_title'] ?: $post['title']) . ' · ' . setting('site_name');
$description = $post['meta_description'] ?: $post['excerpt'];
$canonical = rtrim(config()['url'], '/') . '/blog/' . $post['slug'];
require ROOT . '/app/Views/header.php';
?>
<main class="wrap blog-article-wrap">
    <a class="blog-back" href="<?= e(url('blog')) ?>">← <?= lang() === 'ky' ? 'Блогго кайтуу' : 'Все статьи' ?></a>
    <article class="blog-article">
        <header>
            <span class="blog-eyebrow">Блог · <time datetime="<?= e(str_replace(' ', 'T', $post['published_at']) . 'Z') ?>"><?= e(\App\Models\Blog::date($post['published_at'])) ?></time></span>
            <h1><?= e($post['title']) ?></h1>
            <p class="blog-lead"><?= e($post['excerpt']) ?></p>
        </header>
        <?php if ($post['cover']): ?>
            <img class="blog-cover" src="<?= e(url('blog/image/' . $post['cover'])) ?>" alt="<?= e($post['cover_alt'] ?: $post['title']) ?>" fetchpriority="high" decoding="async">
        <?php endif; ?>
        <div class="blog-body"><?= $bodyHtml ?></div>
        <aside class="panel blog-chat-cta"><h2><?= lang() === 'ky' ? 'Баарлашууну каалайсызбы?' : 'Хочется пообщаться?' ?></h2><p class="muted"><?= lang() === 'ky' ? 'Жаңы сүйлөшүүчү менен таанышыңыз. Чат 18 жаштан жогоркулар үчүн.' : 'Познакомьтесь с новым собеседником. Чат доступен с 18 лет.' ?></p><a class="button" href="<?= e(url()) ?>"><?= lang() === 'ky' ? 'Чатка өтүү' : 'Перейти в чат' ?> →</a></aside>
    </article>
</main>
<?php require ROOT . '/app/Views/footer.php'; ?>
