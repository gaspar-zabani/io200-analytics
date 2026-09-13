(() => {
    const root = document.querySelector('[data-photo-search]');
    if (!root) return;
    const input = root.querySelector('input');
    const list = root.querySelector('[role="listbox"]');
    const status = root.querySelector('[role="status"]');
    const inspector = root.querySelector('[data-photo-inspector]');
    let timer, controller, generation = 0, active = -1, results = [];
    let prepareQuery = false;
    const clear = root.querySelector('[data-photo-search-clear]');
    const syncClear = () => { clear.hidden = !input.value && !root.dataset.selectedPhotoId; };
    const prepareSearch = () => {
        if (!prepareQuery || !root.dataset.selectedPhotoId) return;
        input.value = '';
        prepareQuery = false;
        syncClear();
    };
    input.addEventListener('focus', prepareSearch);
    // Selection can leave the input focused; the next click still starts a fresh query.
    input.addEventListener('click', prepareSearch);
    const remember = id => {
        const update = url => {
            if (id) url.searchParams.set('selected_photo', id);
            else url.searchParams.delete('selected_photo');
            return url;
        };
        history.replaceState(null, '', update(new URL(location.href)));
        document.querySelectorAll('.filters a.filter').forEach(link => {
            link.href = update(new URL(link.href)).href;
        });
    };
    const clearInspector = () => {
        inspectorInstance.clear();
        root.selectedPhoto = null;
        delete root.dataset.selectedPhotoId;
        remember(null);
        prepareQuery = false;
        syncClear();
    };
    const clearSelection = () => {
        cancel();
        close();
        clearInspector();
        input.value = '';
        syncClear();
        status.textContent = '';
        input.focus();
    };
    clear.addEventListener('click', clearSelection);
    const inspectorInstance = window.IOAPhotoInspector.create(inspector, {
        view: 'search',
        onLoad(photo) {
            root.selectedPhoto = photo;
            // Do not restore selected text if the user already prepared a new query.
            if (prepareQuery) input.value = photo.title || String(photo.id);
            status.textContent = '';
            syncClear();
        },
        onError() { status.textContent = 'Photo details unavailable.'; }
    });
    function loadInspector(id) {
        root.dataset.selectedPhotoId = id;
        remember(id);
        prepareQuery = true;
        syncClear();
        inspectorInstance.load(id);
    }
    const close = () => {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        active = -1;
    };
    const cancel = () => {
        clearTimeout(timer);
        controller?.abort();
        generation++;
    };
    const select = index => {
        const photo = results[index];
        if (!photo) return;
        cancel();
        root.selectedPhoto = photo;
        root.dataset.selectedPhotoId = photo.id;
        input.value = photo.title || String(photo.id);
        status.textContent = `Selected: ${input.value} · Photo ${photo.id}`;
        close();
        loadInspector(photo.id);
    };
    const highlight = index => {
        active = index;
        Array.from(list.children).forEach((item, i) => item.setAttribute('aria-selected', String(i === active)));
        input.setAttribute('aria-activedescendant', list.children[active].id);
        list.children[active].scrollIntoView({block: 'nearest'});
    };
    async function search(query, token) {
        controller = new AbortController();
        status.textContent = 'Searching…';
        try {
            const url = new URL(window.location.href);
            url.searchParams.delete('photo_inspector');
            url.searchParams.set('photo_search', query);
            const response = await fetch(url, {signal: controller.signal, headers: {Accept: 'application/json'}});
            if (!response.ok || response.redirected) throw new Error('Search failed');
            const payload = await response.json();
            if (!Array.isArray(payload.results)) throw new Error('Invalid results');
            if (token !== generation) return;
            results = payload.results.slice(0, 5);
            list.replaceChildren();
            results.forEach((photo, i) => {
                const item = document.createElement('li');
                item.id = `photo-search-result-${i}`;
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', 'false');
                const preview = document.createElement('span');
                preview.className = 'photo-search-preview';
                preview.textContent = '—';
                if (photo.image_url) {
                    const img = document.createElement('img');
                    img.src = photo.image_url;
                    img.alt = '';
                    img.addEventListener('error', () => img.remove());
                    preview.append(img);
                }
                const text = document.createElement('span');
                const title = document.createElement('strong');
                title.textContent = photo.title || `Photo ${photo.id}`;
                const id = document.createElement('small');
                id.textContent = `Photo ${photo.id}`;
                text.append(title, id);
                item.append(preview, text);
                item.addEventListener('mousedown', event => event.preventDefault());
                item.addEventListener('click', () => select(i));
                list.append(item);
            });
            list.hidden = results.length === 0;
            input.setAttribute('aria-expanded', String(results.length > 0));
            status.textContent = results.length ? `${results.length} results` : 'No matching photos with recorded IOA activity.';
        } catch (error) {
            if (token !== generation || error.name === 'AbortError') return;
            close();
            status.textContent = 'Search unavailable. Try typing again.';
        }
    }
    input.addEventListener('input', event => {
        cancel();
        close();
        results = [];
        clearInspector();
        status.textContent = '';
        const query = input.value.trim();
        if (query && !event.isComposing) {
            const token = generation;
            timer = setTimeout(() => search(query, token), 250);
        }
    });
    input.addEventListener('compositionend', () => input.dispatchEvent(new Event('input')));
    input.addEventListener('keydown', event => {
        if (event.isComposing) return;
        if (event.key === 'Escape') {
            cancel(); close(); status.textContent = '';
        } else if (!list.hidden && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
            event.preventDefault();
            highlight(active < 0 ? (event.key === 'ArrowDown' ? 0 : results.length - 1)
                : (active + (event.key === 'ArrowDown' ? 1 : results.length - 1)) % results.length);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (!list.hidden) select(active < 0 ? 0 : active);
        }
    });
    root.addEventListener('focusout', event => {
        if (!root.contains(event.relatedTarget)) { cancel(); close(); }
    });
    document.addEventListener('pointerdown', event => {
        if (!root.contains(event.target)) { cancel(); close(); }
    });
    const selected = new URL(location.href).searchParams.get('selected_photo');
    if (selected && /^[1-9]\d*$/.test(selected)) loadInspector(selected);
})();
