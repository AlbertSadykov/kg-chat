<?php
use App\Blog\Content;
?>
<?php if (!$blogReady): ?>
    <div class="panel">
        <h2>Включить блог</h2>
        <p class="muted">Обновление добавит три таблицы с вашим префиксом. Существующие данные чата не меняются.</p>
        <form method="post"><?= csrfField() ?><button name="action" value="install">Включить блог</button></form>
    </div>
<?php else: ?>
    <div class="blog-admin-heading">
        <a class="button" href="<?= e(url('admin/blog')) ?>?new=1">＋ Добавить запись</a>
        <a class="button secondary" href="<?= e(url('blog')) ?>" target="_blank" rel="noopener">Открыть блог ↗</a>
    </div>
    <?php if ($blogShowEditor): ?>
        <?php
        $record = $blogEdit ?? [];
        // После ошибки формы показываем введённый текст, но нормализуем форматирование.
        $rawContent = (string) ($record['content_json'] ?? '{"ops":[{"insert":"\n"}]}');
        try {
            $clean = Content::normalize($rawContent);
            $editorDelta = Content::editorDelta(json_encode($clean['delta']));
        } catch (\InvalidArgumentException $exception) {
            $editorDelta = ['ops' => [['insert' => "\n"]]];
        }
        $editorConfig = ['upload' => url('admin/blog'), 'csrf' => $_SESSION['csrf'], 'delta' => $editorDelta];
        ?>
        <form method="post" class="panel blog-edit-form" id="blog-edit-form" data-editor="<?= e(json_encode($editorConfig, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($record['id'] ?? 0) ?>">
            <input type="hidden" name="revision" value="<?= (int) ($record['revision'] ?? 0) ?>">
            <input type="hidden" name="content_json" id="blog-content" value="<?= e($rawContent) ?>">
            <input type="hidden" name="cover" id="blog-cover-value" value="<?= e($record['cover'] ?? '') ?>">
            <h2><?= empty($record['id']) ? 'Новая запись' : 'Редактирование записи' ?></h2>
            <p class="muted small">Сначала сохраните черновик или выберите публикацию. Картинки — JPG, PNG, WebP, GIF до 5 МБ. Текст — до 100 000 символов.</p>
            <label>Заголовок<input name="title" id="blog-title" maxlength="180" required value="<?= e($record['title'] ?? '') ?>"></label>
            <div class="form-grid">
                <label>URL записи<input name="slug" maxlength="160" pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="Автоматически из заголовка" value="<?= e($record['slug'] ?? '') ?>"><span class="small">Латинские буквы, цифры и дефисы.</span></label>
                <label>Язык статьи<select name="language"><option value="ru" <?= ($record['language'] ?? 'ru') === 'ru' ? 'selected' : '' ?>>Русский</option><option value="ky" <?= ($record['language'] ?? '') === 'ky' ? 'selected' : '' ?>>Кыргызский</option></select></label>
                <label>Статус<select name="status"><option value="draft" <?= ($record['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Черновик</option><option value="published" <?= ($record['status'] ?? '') === 'published' ? 'selected' : '' ?>>Опубликовано</option></select></label>
            </div>
            <label>Краткое описание<textarea name="excerpt" maxlength="600" rows="3" placeholder="Если оставить пустым, сформируется из текста."><?= e($record['excerpt'] ?? '') ?></textarea></label>
            <div class="blog-cover-control">
                <span class="muted small">Обложка записи</span>
                <img id="blog-cover-preview" class="blog-cover-preview" <?= empty($record['cover']) ? 'hidden' : '' ?> src="<?= empty($record['cover']) ? e(url('assets/logo.svg')) : e(url('blog/image/' . $record['cover'])) ?>" alt="Предпросмотр обложки">
                <div class="button-row"><button type="button" class="secondary" id="blog-cover-upload">Загрузить обложку</button><button type="button" class="text-button" id="blog-cover-remove" <?= empty($record['cover']) ? 'hidden' : '' ?>>Убрать обложку</button></div>
                <label>Описание картинки (alt)<input name="cover_alt" maxlength="180" value="<?= e($record['cover_alt'] ?? '') ?>" placeholder="Коротко опишите изображение"></label>
            </div>
            <p class="muted small" id="blog-upload-status" role="status" aria-live="polite"></p>
            <label id="blog-editor-label">Текст записи</label>
            <div class="blog-editor-box">
                <div id="blog-toolbar">
                    <span class="ql-formats"><select class="ql-header" aria-label="Стиль текста"><option selected>Текст</option><option value="2">Заголовок 2</option><option value="3">Заголовок 3</option></select></span>
                    <span class="ql-formats"><button type="button" class="ql-bold" aria-label="Жирный" title="Жирный"></button><button type="button" class="ql-italic" aria-label="Курсив" title="Курсив"></button><button type="button" class="ql-underline" aria-label="Подчёркнутый" title="Подчёркнутый"></button><button type="button" class="ql-strike" aria-label="Зачёркнутый" title="Зачёркнутый"></button></span>
                    <span class="ql-formats"><button type="button" class="ql-list" value="ordered" aria-label="Нумерованный список" title="Нумерованный список"></button><button type="button" class="ql-list" value="bullet" aria-label="Маркированный список" title="Маркированный список"></button><button type="button" class="ql-blockquote" aria-label="Цитата" title="Цитата"></button></span>
                    <span class="ql-formats"><select class="ql-align" aria-label="Выравнивание"><option selected></option><option value="center"></option><option value="right"></option><option value="justify"></option></select></span>
                    <span class="ql-formats"><button type="button" class="ql-link" aria-label="Ссылка" title="Ссылка"></button><button type="button" class="ql-image" aria-label="Загрузить картинку" title="Загрузить картинку"></button><button type="button" class="ql-clean" aria-label="Очистить форматирование" title="Очистить форматирование"></button></span>
                    <span class="ql-formats"><button type="button" id="blog-undo" title="Отменить" aria-label="Отменить">↶</button><button type="button" id="blog-redo" title="Повторить" aria-label="Повторить">↷</button></span>
                </div>
                <div id="blog-editor" aria-labelledby="blog-editor-label"></div>
            </div>
            <noscript><p class="notice danger">Для визуального редактора нужен JavaScript.</p></noscript>
            <p class="muted small" id="blog-word-count"></p>
            <details class="blog-seo"><summary>SEO-настройки</summary><label>SEO-заголовок<input name="meta_title" maxlength="180" value="<?= e($record['meta_title'] ?? '') ?>"></label><label>SEO-описание<textarea name="meta_description" maxlength="320" rows="3"><?= e($record['meta_description'] ?? '') ?></textarea></label></details>
            <div class="button-row"><button type="submit" id="blog-save" disabled>Сохранить</button><a class="button secondary" href="<?= e(url('admin/blog')) ?>">К списку</a><?php if (($record['status'] ?? '') === 'published' && !empty($record['slug'])): ?><a class="button secondary" href="<?= e(url('blog/' . $record['slug'])) ?>?lang=<?= e($record['language'] ?? 'ru') ?>" target="_blank" rel="noopener">Посмотреть ↗</a><?php endif; ?></div>
        </form>
        <?php if (!empty($record['id'])): ?>
            <form method="post" class="blog-delete-form" data-blog-delete><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $record['id'] ?>"><input type="hidden" name="revision" value="<?= (int) $record['revision'] ?>"><button class="text-button danger-text">Удалить запись</button></form>
        <?php endif; ?>
    <?php endif; ?>
    <form class="panel" method="get"><label>Поиск записей<input name="q" maxlength="80" value="<?= e($query) ?>"></label><button class="secondary">Найти</button></form>
    <div class="panel table-wrap"><table><thead><tr><th>Заголовок</th><th>Язык</th><th>Статус</th><th>Изменено</th><th>Действия</th></tr></thead><tbody>
        <?php foreach ($rows as $row): ?>
            <tr><td><?= e($row['title']) ?></td><td><?= ($row['language'] ?? 'ru') === 'ky' ? 'Кыргызский' : 'Русский' ?></td><td><span class="pill"><?= $row['status'] === 'published' ? 'Опубликовано' : 'Черновик' ?></span></td><td><?= e(\App\Models\Blog::date($row['updated_at'])) ?></td><td><a href="<?= e(url('admin/blog')) ?>?id=<?= (int) $row['id'] ?>">Редактировать</a><?php if ($row['status'] === 'published'): ?><br><a href="<?= e(url('blog/' . $row['slug'])) ?>?lang=<?= e($row['language'] ?? 'ru') ?>" target="_blank" rel="noopener">Открыть ↗</a><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5">Записей пока нет.</td></tr><?php endif; ?>
    </tbody></table></div>
    <nav class="pagination"><?php if ($pageNumber > 1): ?><a href="?page=<?= $pageNumber - 1 ?>&amp;q=<?= e(rawurlencode($query)) ?>">← Назад</a><?php endif; ?><span>Страница <?= (int) $pageNumber ?></span><?php if ($pageNumber * 20 < $blogTotal): ?><a href="?page=<?= $pageNumber + 1 ?>&amp;q=<?= e(rawurlencode($query)) ?>">Далее →</a><?php endif; ?></nav>
    <form method="post" class="panel"><?= csrfField() ?><input type="hidden" name="action" value="cleanup_media"><h3>Неиспользуемые картинки</h3><p class="muted small">Удаляются только картинки без связи с записью, загруженные больше суток назад. Картинки черновиков сохраняются.</p><button class="secondary">Очистить неиспользуемые картинки</button></form>
<?php endif; ?>
<script src="<?= e(url('assets/vendor/quill/quill.js')) ?>" defer></script>
<script src="<?= e(url('assets/blog-admin.js')) ?>" defer></script>
