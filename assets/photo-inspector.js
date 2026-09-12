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
    const inspectorIcon = name => document.querySelector('[data-visit-popover-icons]')?.content.querySelector(`[data-icon="${name}"]`)?.cloneNode(true);
    const metricData = m => [
        ['views', 'Views', m.views, ''],
        ['downloads', 'Downloads', m.direct_downloads + m.selection_downloads,
            [m.direct_downloads ? `${m.direct_downloads.toLocaleString()} direct` : '', m.selection_downloads ? `${m.selection_downloads.toLocaleString()} selection` : ''].filter(Boolean).join(' · ')],
        ['basket', 'Basket actions', m.basket_adds + m.basket_removes,
            [m.basket_adds ? count(m.basket_adds, 'add') : '', m.basket_removes ? count(m.basket_removes, 'remove') : ''].filter(Boolean).join(' · ')]
    ];
    const renderMetrics = (inspector, photo) => {
        const summary = element('dl', undefined, 'photo-inspector-metrics');
        metricData(photo.metrics).forEach(([icon, label, total, description], index) => {
            if (!total) return;
            const group = element('div', undefined, 'photo-inspector-metric');
            group.style.gridColumn = String(index + 1);
            group.title = label;
            const heading = element('dt', undefined, 'visit-summary__metric');
            const svg = inspectorIcon(icon);
            if (svg) heading.append(svg);
            heading.append(element('span', label, 'visit-summary__accessible'));
            const detail = element('dd');
            detail.append(element('span', total.toLocaleString(), 'ranking-primary'));
            if (description) detail.append(element('span', description, 'photo-item__meta'));
            group.append(heading, detail);
            summary.append(group);
        });
        inspector.append(summary);
    };
    const renderActivity = (inspector, photo) => {
        if (!photo.activity.length) return;
        inspector.append(element('h3', 'Activity location'));
        const types = ['views', 'direct_downloads', 'selection_downloads', 'basket_adds', 'basket_removes'];
        const showCounts = photo.activity.length > 1 || types.filter(key => photo.metrics[key] > 0).length > 1;
        const columns = [0, 1, 2].filter(i => photo.activity.some(context => metricData(context.metrics)[i][2] > 0));
        const grid = element('div', undefined, 'photo-inspector-locations');
        grid.style.gridTemplateColumns = `minmax(0, 1.4fr)${showCounts ? ' minmax(0, 1fr)'.repeat(columns.length) : ''}`;
        photo.activity.forEach(context => {
            const name = element('span', context.title, 'photo-inspector-location-name');
            if (context.album_id !== null) name.dataset.albumId = context.album_id;
            grid.append(name);
            if (!showCounts) return;
            const values = metricData(context.metrics);
            columns.forEach(i => {
                const [icon, label, total, description] = values[i];
                const cell = element('span', undefined, 'photo-inspector-location-value');
                if (total) {
                    cell.title = label;
                    const value = element('span', undefined, 'visit-summary__metric');
                    const svg = inspectorIcon(icon);
                    if (svg) value.append(svg);
                    value.append(element('span', total.toLocaleString()), element('span', label, 'visit-summary__accessible'));
                    cell.append(value);
                    if (description) cell.append(element('small', description));
                }
                grid.append(cell);
            });
        });
        inspector.append(grid);
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
    const renderInspector = (inspector, photo, action, visit = null) => {
        inspector.replaceChildren();
        renderIdentity(inspector, photo, action);
        const scopes = element('div', undefined, visit ? 'photo-inspector-scopes photo-inspector-scopes--visit' : 'photo-inspector-scopes');
        const scope = (data, isVisit) => {
            const section = element('section', undefined, 'photo-inspector-scope');
            if (visit) section.append(element('h3', isVisit ? 'This visit' : 'Overall photo activity', 'photo-inspector-scope-title'));
            const primary = element('div', undefined, 'photo-inspector-primary');
            const scopeLabel = element('p', undefined, 'photo-inspector-meta');
            scopeLabel.append(element('span', isVisit ? 'Whole visit' : (document.body.dataset.inspectorPeriod || 'Selected period').replace(/^Last /i, ''), isVisit ? '' : 'photo-inspector-period'));
            primary.append(scopeLabel);
            const details = element('div', undefined, 'photo-inspector-details');
            if (isVisit && data.status !== 'available') {
                primary.append(element('p', data.status === 'outside_period' ? 'This visit is outside the selected period.' : 'Visit context unavailable.'));
            } else {
                renderMetrics(primary, data);
                let time;
                if (isVisit) {
                    const first = data.first_activity, last = data.latest_activity || first;
                    time = !first ? 'No activity for this photo in this visit.' : first.slice(0, 10) === last.slice(0, 10)
                        ? first.slice(11, 16) + (first.slice(11, 16) === last.slice(11, 16) ? '' : `–${last.slice(11, 16)}`)
                        : `${formatTimestamp(first)}–${formatTimestamp(last)}`;
                } else time = data.latest ? `Latest activity: ${formatTimestamp(data.latest)}` : 'No activity in this period';
                primary.append(element('p', time, 'photo-inspector-meta'));
                renderActivity(details, data);
            }
            if (!isVisit && photo.albums.length) {
                details.append(element('h3', 'Appears in'));
                const membership = element('div', undefined, 'photo-inspector-membership');
                photo.albums.forEach(album => {
                    const tag = element('span', album.title, 'photo-inspector-album');
                    tag.dataset.albumId = album.id;
                    membership.append(tag);
                });
                details.append(membership);
            }
            section.append(primary, details);
            return section;
        };
        if (visit) scopes.append(scope(visit, true));
        scopes.append(scope(photo, false));
        inspector.append(scopes);
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
                        renderInspector(inspector, payload.photo, action, visitId !== null ? payload.this_visit : null);
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
