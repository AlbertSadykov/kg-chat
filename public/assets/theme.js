'use strict';
(() => {
    const storage = {
        get(key) { try { return localStorage.getItem(key); } catch (_) { return null; } },
        set(key, value) { try { localStorage.setItem(key, value); } catch (_) { /* Хранилище может быть отключено. */ } }
    };
    document.documentElement.dataset.theme = storage.get('kg-theme') === 'light' ? 'light' : 'dark';
    document.getElementById('theme')?.addEventListener('click', () => {
        const theme = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
        document.documentElement.dataset.theme = theme;
        storage.set('kg-theme', theme);
        document.dispatchEvent(new Event('themechange'));
    });
})();
