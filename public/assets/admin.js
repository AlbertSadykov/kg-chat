'use strict';
(() => {
    const canvas = document.getElementById('chart');
    if (!canvas) return;
    const points = JSON.parse(canvas.dataset.points);
    const draw = () => {
        const width = Math.max(240, canvas.parentElement.clientWidth - 40);
        const dpr = window.devicePixelRatio || 1;
        canvas.width = width * dpr;
        canvas.height = 240 * dpr;
        canvas.style.width = '100%';
        canvas.style.height = '240px';
        const ctx = canvas.getContext('2d');
        ctx.scale(dpr, dpr);
        const css = getComputedStyle(document.documentElement);
        ctx.fillStyle = css.getPropertyValue('--muted');
        ctx.font = '12px system-ui';
        if (!points.length) { ctx.fillText('0', 20, 120); return; }
        const max = Math.max(1, ...points.map(p => Number(p.n)));
        const slot = (width - 36) / points.length;
        points.forEach((p, index) => {
            const height = Number(p.n) / max * 160;
            ctx.fillStyle = css.getPropertyValue('--accent');
            ctx.fillRect(24 + index * slot, 190 - height, Math.max(4, slot - 10), height);
            ctx.fillStyle = css.getPropertyValue('--muted');
            ctx.fillText(p.n, 24 + index * slot, 180 - height);
            if (index % Math.max(1, Math.ceil(points.length / 6)) === 0) ctx.fillText(p.day.slice(5), 24 + index * slot, 215);
        });
    };
    window.addEventListener('resize', draw);
    document.addEventListener('themechange', draw);
    draw();
})();
