(() => {
    const element = (tag, text, className) => {
        const node = document.createElement(tag);
        if (text !== undefined) node.textContent = text;
        if (className) node.className = className;
        return node;
    };
    // Match the dashboard's server-wall-clock labels without browser timezone conversion.
    const formatTimestamp = value => {
        const day = value.slice(0, 10);
        const date = new Date(`${day}T00:00:00Z`);
        if (Number.isNaN(date.getTime())) return value;
        const today = document.body.dataset.inspectorToday;
        const yesterday = new Date(`${today}T00:00:00Z`);
        yesterday.setUTCDate(yesterday.getUTCDate() - 1);
        const label = day === today ? 'Today' : !Number.isNaN(yesterday.getTime()) && day === yesterday.toISOString().slice(0, 10)
            ? 'Yesterday' : date.toLocaleDateString('en', {month: 'short', day: 'numeric', timeZone: 'UTC'});
        return `${label}, ${value.slice(11, 16)}`;
    };
    const count = (value, singular, plural = `${singular}s`) => `${value.toLocaleString()} ${value === 1 ? singular : plural}`;
    const activityText = metrics => [
        metrics.views ? count(metrics.views, 'view') : null,
        metrics.direct_downloads ? count(metrics.direct_downloads, 'direct download') : null,
        metrics.selection_downloads ? count(metrics.selection_downloads, 'selection download') : null,
        metrics.basket_adds ? count(metrics.basket_adds, 'basket add') : null,
        metrics.basket_removes ? count(metrics.basket_removes, 'basket remove') : null
    ].filter(Boolean).join(' · ');
    const renderMetrics = (inspector, photo) => {
        const metrics = photo.metrics;
        const summary = element('dl');
        [
            ['Views', metrics.views.toLocaleString()],
            ['Downloads', `${metrics.direct_downloads.toLocaleString()} direct · ${metrics.selection_downloads.toLocaleString()} selection`],
            ['Basket', `${count(metrics.basket_adds, 'add')} · ${count(metrics.basket_removes, 'remove')}`]
        ].forEach(([label, value]) => {
            const group = element('div');
            group.append(element('dt', label), element('dd', value));
            summary.append(group);
        });
        inspector.append(summary);
    };
    const renderActivity = (inspector, photo) => {
        const albums = photo.activity.filter(context => context.album_id !== null);
        if (albums.length) inspector.append(element('h3', 'Activity by album'));
        albums.forEach(context => {
            const line = element('p', undefined, 'photo-inspector-context');
            line.dataset.albumId = context.album_id;
            line.append(element('strong', context.title), element('small', activityText(context.metrics)));
            inspector.append(line);
        });
        const other = photo.activity.find(context => context.album_id === null);
        if (other) {
            const line = element('p', undefined, 'photo-inspector-context');
            line.append(element('strong', other.title), element('small', activityText(other.metrics)));
            inspector.append(line);
        }
    };
    const renderIdentity = (inspector, photo, action) => {
        const identity = element('div', undefined, 'photo-inspector-identity');
        const preview = element('span', '—', 'photo-inspector-preview');
        if (photo.image_url) {
            const image = element('img');
            image.src = photo.image_url;
            image.alt = '';
            image.addEventListener('error', () => image.remove());
            preview.append(image);
        }
        const name = element('div');
        name.append(element('strong', photo.title || `Photo ${photo.id}`));
        if (photo.title) name.append(element('small', `Photo ${photo.id}`));
        identity.append(preview, name);
        if (action) identity.append(action());
        inspector.append(identity);
    };
    const renderInspector = (inspector, photo, action) => {
        inspector.replaceChildren();
        renderIdentity(inspector, photo, action);
        inspector.append(element('p', `Period · ${document.body.dataset.inspectorPeriod || 'Selected period'}`));
        renderMetrics(inspector, photo);
        inspector.append(element('p', photo.latest ? `Latest activity · ${formatTimestamp(photo.latest)}` : 'No activity in this period.'));
        if (photo.albums.length) {
            inspector.append(element('h3', 'Appears in'));
            const membership = element('p');
            photo.albums.forEach((album, index) => {
                if (index) membership.append(document.createTextNode(' · '));
                const title = element('span', album.title);
                title.dataset.albumId = album.id;
                membership.append(title);
            });
            inspector.append(membership);
        }
        renderActivity(inspector, photo);
    };
    const renderVisitPopover = (container, identity, visit) => {
        container.replaceChildren();
        renderIdentity(container, identity, null);
        container.append(element('h3', 'This visit'));
        if (!visit || visit.status !== 'available') {
            container.append(element('p', visit?.status === 'outside_period'
                ? 'This visit is outside the selected period.' : 'Visit context unavailable.'));
            return;
        }
        const m = visit.metrics;
        const metrics = element('div', undefined, 'visit-popover-metrics');
        const addMetric = (icon, text, label) => {
            if (!text) return;
            const group = element('span', undefined, 'visit-summary__metric');
            group.title = label;
            const svg = document.querySelector('[data-visit-popover-icons]')?.content.querySelector(`[data-icon="${icon}"]`);
            if (svg) group.append(svg.cloneNode(true));
            const value = element('span', text);
            value.setAttribute('aria-hidden', 'true');
            group.append(value, element('span', label, 'visit-summary__accessible'));
            metrics.append(group);
        };
        addMetric('views', m.views ? m.views.toLocaleString() : '', count(m.views, 'view'));
        addMetric('downloads', [m.direct_downloads ? `${m.direct_downloads.toLocaleString()} direct` : '',
            m.selection_downloads ? `${m.selection_downloads.toLocaleString()} selection` : ''].filter(Boolean).join(' · '),
            [m.direct_downloads ? count(m.direct_downloads, 'direct download') : '',
                m.selection_downloads ? count(m.selection_downloads, 'selection download') : ''].filter(Boolean).join(', '));
        addMetric('basket', [m.basket_adds ? `+${m.basket_adds.toLocaleString()}` : '',
            m.basket_removes ? `−${m.basket_removes.toLocaleString()}` : ''].filter(Boolean).join(' '),
            [m.basket_adds ? count(m.basket_adds, 'basket add') : '',
                m.basket_removes ? count(m.basket_removes, 'basket remove') : ''].filter(Boolean).join(', '));
        if (metrics.childElementCount) container.append(metrics);
        else container.append(element('p', 'No activity for this photo in this visit.'));
        const contexts = [...new Set(visit.activity.map(context => context.title))];
        if (contexts.length) container.append(element('p', contexts.join(' · '), 'visit-popover-context'));
        if (visit.first_activity) {
            const first = visit.first_activity, last = visit.latest_activity || first;
            // Format server wall-clock values without applying the browser timezone.
            const clock = value => value.slice(11, 16);
            const crossDay = first.slice(0, 10) !== last.slice(0, 10);
            const label = value => {
                if (!crossDay) return clock(value);
                const date = new Date(`${value.slice(0, 10)}T00:00:00Z`);
                return `${date.toLocaleDateString('en', {month: 'short', day: 'numeric', timeZone: 'UTC'})}, ${clock(value)}`;
            };
            const start = label(first), end = label(last);
            container.append(element('p', start === end ? start : `${start}–${end}`, 'visit-popover-time'));
        }
    };
    // Each mounted inspector owns its requests; controllers own selection/navigation.
    window.IOAPhotoInspector = {
        create(inspector, {action, onLoad, onError, view = 'full'} = {}) {
            let controller, generation = 0;
            const cancel = () => {
                controller?.abort();
                generation++;
                inspector.removeAttribute('aria-busy');
            };
            const appendAction = () => { if (action) inspector.append(action()); };
            async function load(photoId, visitId = null) {
                const id = String(photoId);
                cancel();
                controller = new AbortController();
                const token = generation;
                inspector.hidden = false;
                inspector.setAttribute('aria-busy', 'true');
                inspector.replaceChildren(element('p', 'Loading photo…'));
                appendAction();
                try {
                    const url = new URL(location.href);
                    url.searchParams.delete('photo_search');
                    url.searchParams.delete('visit');
                    if (visitId !== null) url.searchParams.set('visit', String(visitId));
                    url.searchParams.set('photo_inspector', id);
                    const response = await fetch(url, {signal: controller.signal, headers: {Accept: 'application/json'}});
                    if (!response.ok || response.redirected) throw new Error('Inspector unavailable');
                    const payload = await response.json();
                    if (token !== generation) return;
                    if (!payload.photo || payload.photo.id !== id) throw new Error('Invalid photo');
                    if (view === 'visit') {
                        const {id, title, image_url} = payload.photo;
                        renderVisitPopover(inspector, {id, title, image_url}, payload.this_visit);
                    } else {
                        renderInspector(inspector, payload.photo, action);
                    }
                    if (view === 'full' && visitId !== null && payload.this_visit) {
                        const visit = payload.this_visit;
                        const section = element('section', undefined, 'photo-inspector-visit');
                        section.append(element('h3', 'This visit'));
                        if (visit.status === 'available') {
                            renderMetrics(section, visit);
                            section.append(element('p', visit.first_activity
                                ? `First activity · ${formatTimestamp(visit.first_activity)}` : 'No activity for this photo in this visit.'));
                            if (visit.latest_activity) section.append(element('p', `Latest activity · ${formatTimestamp(visit.latest_activity)}`));
                            renderActivity(section, visit);
                        } else {
                            section.append(element('p', visit.status === 'outside_period'
                                ? 'This visit is outside the selected period.' : 'Visit context unavailable.'));
                        }
                        inspector.querySelector('.photo-inspector-identity').after(section, element('h3', 'Overall photo activity'));
                    }
                    onLoad?.(payload.photo);
                } catch (error) {
                    if (token !== generation || error.name === 'AbortError') return;
                    const retry = element('button', 'Retry');
                    retry.type = 'button';
                    retry.addEventListener('click', () => load(id, visitId));
                    inspector.replaceChildren(element('p', 'Photo details unavailable.'), retry);
                    appendAction();
                    onError?.(error);
                } finally {
                    if (token === generation) inspector.removeAttribute('aria-busy');
                }
            }
            return {load, cancel, clear() {
                cancel();
                inspector.hidden = true;
                inspector.replaceChildren();
            }};
        }
    };
})();
