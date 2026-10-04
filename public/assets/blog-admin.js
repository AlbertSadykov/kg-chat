'use strict';
(() => {
    document.querySelectorAll('[data-blog-delete]').forEach(form => {
        form.addEventListener('submit', event => {
            if (!confirm('Удалить запись? Восстановить её можно будет только из резервной копии.')) event.preventDefault();
        });
    });
    const form = document.getElementById('blog-edit-form');
    if (!form) return;
    const $ = id => document.getElementById(id);
    const cfg = JSON.parse(form.dataset.editor);
    let dirty = false, uploading = false, submitting = false;
    const status = (text, error = false) => {
        $('blog-upload-status').textContent = text;
        $('blog-upload-status').classList.toggle('danger-text', error);
    };
    if (!window.Quill) {
        status('Редактор не загрузился. Проверьте файлы assets/vendor/quill/.', true);
        return;
    }
    const quill = new Quill('#blog-editor', {
        theme: 'snow',
        placeholder: 'Напишите текст статьи…',
        formats: ['header', 'bold', 'italic', 'underline', 'strike', 'list', 'blockquote', 'align', 'link', 'image'],
        modules: {
            toolbar: {container: '#blog-toolbar'},
            history: {delay: 500, maxStack: 100, userOnly: true}
        }
    });
    quill.setContents(cfg.delta, 'silent');
    quill.history.clear();
    quill.root.setAttribute('aria-labelledby', 'blog-editor-label');
    quill.root.setAttribute('aria-multiline', 'true');
    quill.root.setAttribute('role', 'textbox');
    $('blog-save').disabled = false;
    const updateCounter = () => { $('blog-word-count').textContent = `${Math.max(0, quill.getLength() - 1).toLocaleString('ru-RU')} / 100 000 символов`; };
    updateCounter();
    quill.on('text-change', () => { dirty = true; updateCounter(); });
    form.addEventListener('input', () => { dirty = true; });
    form.addEventListener('change', () => { dirty = true; });
    $('blog-undo').addEventListener('click', () => quill.history.undo());
    $('blog-redo').addEventListener('click', () => quill.history.redo());
    const upload = async file => {
        if (uploading) throw new Error('Дождитесь завершения текущей загрузки.');
        if (!file || !['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type) || file.size > 5 * 1024 * 1024) {
            throw new Error('Выберите JPG, PNG, WebP или GIF до 5 МБ.');
        }
        uploading = true; $('blog-save').disabled = true;
        status('Загрузка картинки…');
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 60000);
        try {
            const data = new FormData();
            data.append('_csrf', cfg.csrf); data.append('action', 'upload'); data.append('image', file);
            const response = await fetch(cfg.upload, {method: 'POST', credentials: 'same-origin', headers: {'X-CSRF-Token': cfg.csrf}, body: data, signal: controller.signal});
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || 'Не удалось загрузить картинку. Обновите страницу и проверьте вход в админку.');
            status('Картинка загружена. Сохраните запись, чтобы применить изменения.');
            return result;
        } catch (error) {
            throw new Error(error.name === 'AbortError' ? 'Истекло время загрузки. Попробуйте картинку поменьше.' : error.message);
        } finally { clearTimeout(timeout); uploading = false; $('blog-save').disabled = false; }
    };
    const pickImage = onFile => {
        const input = document.createElement('input');
        input.type = 'file'; input.accept = 'image/jpeg,image/png,image/webp,image/gif';
        input.addEventListener('change', async () => {
            if (!input.files[0]) return;
            try { await onFile(input.files[0]); }
            catch (error) { status(error.message, true); }
        }, {once: true});
        input.click();
    };
    $('blog-cover-upload').addEventListener('click', () => pickImage(async file => {
        const result = await upload(file);
        $('blog-cover-value').value = result.filename;
        $('blog-cover-preview').src = result.url;
        $('blog-cover-preview').hidden = false;
        $('blog-cover-remove').hidden = false;
        dirty = true;
    }));
    $('blog-cover-remove').addEventListener('click', () => {
        $('blog-cover-value').value = '';
        $('blog-cover-preview').hidden = true;
        $('blog-cover-remove').hidden = true;
        dirty = true;
    });
    quill.getModule('toolbar').addHandler('image', () => {
        const range = quill.getSelection(true) || {index: quill.getLength() - 1};
        pickImage(async file => {
            const result = await upload(file);
            const index = Math.min(range.index, quill.getLength() - 1);
            quill.insertEmbed(index, 'image', result.url, 'user');
            quill.setSelection(index + 1, 0, 'silent');
        });
    });
    quill.getModule('toolbar').addHandler('link', function (value) {
        if (!value) { quill.format('link', false, 'user'); return; }
        const range = quill.getSelection(true);
        const href = prompt('Введите ссылку с https://', 'https://');
        if (!href) return;
        try {
            const parsed = new URL(href.trim());
            if (!['http:', 'https:'].includes(parsed.protocol)) throw new Error();
            if (range && range.length > 0) quill.formatText(range.index, range.length, 'link', parsed.href, 'user');
            else if (range) {
                quill.insertText(range.index, parsed.href, {link: parsed.href}, 'user');
                quill.setSelection(range.index + parsed.href.length, 0, 'silent');
            }
        } catch (_) { status('Нужна ссылка с https:// или http://.', true); }
    });
    // Вставленные из буфера/перетаскиваемые картинки также проходят серверную проверку.
    const handleFiles = event => {
        const transfer = event.clipboardData || event.dataTransfer;
        if (!transfer || !transfer.files || !transfer.files.length) return;
        event.preventDefault(); event.stopPropagation();
        if (transfer.files.length !== 1) { status('Добавляйте картинки по одной.', true); return; }
        const file = transfer.files[0], range = quill.getSelection() || {index: quill.getLength() - 1};
        upload(file).then(result => quill.insertEmbed(Math.min(range.index, quill.getLength() - 1), 'image', result.url, 'user')).catch(error => status(error.message, true));
    };
    quill.root.addEventListener('paste', handleFiles, true);
    quill.root.addEventListener('drop', handleFiles, true);
    quill.root.addEventListener('dragover', event => {
        if (event.dataTransfer && Array.from(event.dataTransfer.types).includes('Files')) event.preventDefault();
    });
    // Удаляем внешние и data: картинки из вставленного HTML.
    const Delta = Quill.import('delta');
    quill.clipboard.addMatcher('IMG', () => new Delta());
    form.addEventListener('submit', event => {
        if (uploading || submitting) { event.preventDefault(); return; }
        const text = quill.getText().trim();
        if (!text || text.length > 100000) {
            event.preventDefault(); status('Введите текст статьи, максимум 100 000 символов.', true); return;
        }
        const delta = JSON.stringify(quill.getContents());
        if (new TextEncoder().encode(delta).length > 262144) {
            event.preventDefault(); status('Слишком большой текст или слишком много форматирования.', true); return;
        }
        $('blog-content').value = delta;
        submitting = true; dirty = false; $('blog-save').disabled = true;
    });
    window.addEventListener('beforeunload', event => {
        if (!dirty || submitting) return;
        event.preventDefault(); event.returnValue = '';
    });
})();
