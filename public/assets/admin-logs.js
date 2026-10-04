(() => {
    const consoleRoot = document.getElementById('admin-log-console');
    const output = document.getElementById('log-output');
    const levelFilter = document.getElementById('log-level');
    const searchInput = document.getElementById('log-search');
    if (!consoleRoot || !output || !levelFilter || !searchInput) return;

    const endpoint = consoleRoot.dataset.feed;
    const emptyMessage = consoleRoot.dataset.empty || 'No events yet.';
    const unavailableMessage = consoleRoot.dataset.unavailable || 'Log unavailable';
    const dateFormatter = new Intl.DateTimeFormat(undefined, {
        timeZone: consoleRoot.dataset.timezone || 'Asia/Bishkek',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false
    });
    let cursor = 0;
    let timer = null;
    let pending = false;
    let generation = 0;
    let activeController = null;

    function makeEntry(record) {
        const entry = document.createElement('article');
        const isRequest = record.action === 'request_success' || record.action === 'request_error';
        const isError = record.action === 'request_error';
        entry.className = `log-entry ${isError ? 'is-error' : isRequest ? 'is-success' : 'is-audit'}`;

        const timestamp = document.createElement('time');
        const date = new Date(`${String(record.created_at || '').replace(' ', 'T')}Z`);
        if (!Number.isNaN(date.getTime())) {
            timestamp.dateTime = date.toISOString();
            timestamp.textContent = dateFormatter.format(date);
        } else {
            timestamp.textContent = String(record.created_at || '');
        }
        entry.append(timestamp);

        const badge = document.createElement('span');
        badge.className = 'log-level';
        badge.textContent = isError ? 'ERR' : isRequest ? 'OK' : 'AUDIT';
        entry.append(badge);

        let detail = null;
        if (isRequest) {
            try {
                detail = JSON.parse(record.detail || '{}');
            } catch {
                detail = null;
            }
        }
        const description = document.createElement('span');
        description.className = 'log-description';
        if (detail && typeof detail === 'object') {
            const method = String(detail.method || 'GET').toUpperCase();
            const path = String(detail.path || '/');
            const status = Number(detail.status || 0);
            description.textContent = `${method} ${path}  ${status}`;
            if (detail.error && typeof detail.error === 'object') {
                const error = detail.error;
                const extra = [error.type, error.source, error.reference].filter(Boolean).join(' · ');
                if (extra) description.textContent += `  —  ${extra}`;
            }
        } else {
            description.textContent = `${String(record.action || 'event')}  ${String(record.detail || '')}`;
        }
        if (record.login) description.textContent += `  [${String(record.login)}]`;
        entry.append(description);
        return entry;
    }

    function showMessage(message, isError = false) {
        const node = document.createElement('p');
        node.className = `log-empty${isError ? ' is-error' : ''}`;
        node.textContent = message;
        output.replaceChildren(node);
    }

    async function poll(reset = false) {
        if (reset) {
            generation += 1;
            if (activeController) activeController.abort();
            activeController = null;
            pending = false;
            cursor = 0;
            output.replaceChildren();
        }
        if (pending || document.hidden) return;
        pending = true;
        const currentGeneration = generation;
        activeController = new AbortController();
        const params = new URLSearchParams({
            feed: '1',
            after: String(cursor),
            level: levelFilter.value,
            q: searchInput.value.trim()
        });
        try {
            const response = await fetch(`${endpoint}?${params.toString()}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: activeController.signal
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();
            if (currentGeneration !== generation) return;
            if (!Array.isArray(data.rows)) throw new Error('Invalid log feed response');
            const rows = data.rows.slice().sort((a, b) => Number(a.id) - Number(b.id));
            const placeholder = output.querySelector('.log-empty');
            if (rows.length && placeholder) placeholder.remove();
            for (const record of rows) {
                output.append(makeEntry(record));
                cursor = Math.max(cursor, Number(record.id) || 0);
            }
            if (!output.childElementCount) showMessage(emptyMessage);
            while (output.childElementCount > 500) output.firstElementChild.remove();
            if (output.childElementCount && rows.length) output.scrollTop = output.scrollHeight;
        } catch (error) {
            if (currentGeneration === generation && error.name !== 'AbortError' && (!output.childElementCount || reset)) {
                showMessage(`${unavailableMessage}: ${error.message}`, true);
            }
        } finally {
            if (currentGeneration === generation) {
                pending = false;
                activeController = null;
            }
        }
    }

    function restart() {
        if (timer) window.clearInterval(timer);
        poll(true);
        timer = window.setInterval(() => poll(), 2000);
    }

    levelFilter.addEventListener('change', restart);
    let searchDelay = null;
    searchInput.addEventListener('input', () => {
        window.clearTimeout(searchDelay);
        searchDelay = window.setTimeout(restart, 250);
    });
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) poll(true);
    });
    restart();
})();
