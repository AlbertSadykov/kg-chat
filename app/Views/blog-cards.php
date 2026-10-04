<?php
// $posts передаётся шаблоном главной или страницы блога.
use App\Models\Blog;
?>
<div class="blog-grid">
<?php foreach ($posts as $item): ?>
    <article class="blog-card">
        <div class="blog-card-content">
            <time datetime="<?= e(str_replace(' ', 'T', $item['published_at']) . 'Z') ?>"><?= e(Blog::date($item['published_at'])) ?></time>
            <h3><a href="<?= e(url('blog/' . $item['slug'])) ?>"><?= e($item['title']) ?></a></h3>
            <p><?= e($item['excerpt']) ?></p>
            <a class="blog-read" href="<?= e(url('blog/' . $item['slug'])) ?>"><?= lang() === 'ky' ? 'Окуу' : 'Читать' ?> <span aria-hidden="true">↗</span></a>
        </div>
    </article>
<?php endforeach; ?>
</div>
