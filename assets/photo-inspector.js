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
    const addSearchDetail = (value, label, total, description) => {
        if (!description) return;
        value.classList.add('photo-search-metric-detail');
        value.tabIndex = 0;
        value.addEventListener('click', () => value.focus());
        value.title = description.split(' · ').join('\n');
        value.setAttribute('aria-label', `${label}: ${total.toLocaleString()}; ${description}`);
    };
    const renderMetrics = (inspector, photo, reservePositions = true) => {
        const summary = element('dl', undefined, 'photo-inspector-metrics');
        metricData(photo.metrics).forEach(([icon, label, total, description], index) => {
            if (!total && reservePositions) return;
            const group = element('div', undefined, 'photo-inspector-metric');
            if (reservePositions) group.style.gridColumn = String(index + 1);
            group.title = label;
            const heading = element('dt', undefined, 'visit-summary__metric');
            const svg = inspectorIcon(icon);
            if (svg) heading.append(svg);
            heading.append(element('span', label, 'visit-summary__accessible'));
            const detail = element('dd');
            const primary = element('span', undefined, 'ranking-primary');
            if (!reservePositions) addSearchDetail(primary, label, total, description);
            if (reservePositions) primary.textContent = total.toLocaleString();
            else if (total) primary.append(element('span', total.toLocaleString()));
            detail.append(primary);
            if (description && reservePositions) detail.append(element('span', description, 'photo-item__meta'));
            group.append(heading, detail);
            summary.append(group);
        });
        inspector.append(summary);
    };
    const renderActivity = (inspector, photo, search = false) => {
        if (!photo.activity.length) return;
        inspector.append(element('h3', 'Activity location'));
        const types = ['views', 'direct_downloads', 'selection_downloads', 'basket_adds', 'basket_removes'];
        const showCounts = search || photo.activity.length > 1 || types.filter(key => photo.metrics[key] > 0).length > 1;
        const columns = [0, 1, 2].filter(i => search || photo.activity.some(context => metricData(context.metrics)[i][2] > 0));
        const grid = element('div', undefined, 'photo-inspector-locations');
        if (!search) grid.style.gridTemplateColumns = `minmax(0, 1.4fr)${showCounts ? ' minmax(0, 1fr)'.repeat(columns.length) : ''}`;
        if (search) grid.classList.add('photo-search-locations');
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
                    if (svg && !search) value.append(svg);
                    value.append(element('span', total.toLocaleString()), element('span', label, 'visit-summary__accessible'));
                    cell.append(value);
                    if (description) {
                        if (search) addSearchDetail(value, label, total, description);
                        else cell.append(element('small', description));
                    }
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
    const renderSearchResult = (inspector, photo) => {
        inspector.replaceChildren();
        const composition = element('section', undefined, 'photo-search-composition');
        composition.setAttribute('aria-label', 'Photo activity in selected period');
        const activeMetrics = metricData(photo.metrics).filter(([, , total]) => total > 0);
        composition.style.setProperty('--photo-metric-tracks', 'repeat(3, minmax(0, 100px))');
        composition.append(element('span', document.body.dataset.inspectorPeriod || 'Selected period', 'photo-inspector-period photo-search-period'));
        renderIdentity(composition, photo, null);
        renderMetrics(composition, photo, false);
        if (activeMetrics.length) {
            renderActivity(composition, photo, true);
        } else {
            composition.append(element('p', 'No activity in this period', 'photo-search-no-activity'));
        }
        inspector.append(composition);
        if (photo.albums.length) {
            const membership = element('p', undefined, 'photo-search-membership');
            membership.append(element('span', 'Belongs to', 'photo-search-membership-label'));
            photo.albums.forEach((album, index) => {
                if (index) membership.append(document.createTextNode(' · '));
                const name = element('span', album.title);
                name.dataset.albumId = album.id;
                membership.append(name);
            });
            inspector.append(membership);
        }
    };
    const renderInspector = (inspector, photo, action, visit = null) => {
        inspector.replaceChildren();
        const header = element('div', undefined, 'photo-inspector-header');
        renderIdentity(header, photo, action);
        header.append(element('span', `Overall · ${(document.body.dataset.inspectorPeriod || 'Selected period').replace(/^Last /i, '')}`, 'photo-inspector-period'));
        inspector.append(header);
        const grid = element('div', undefined, 'photo-inspector-analysis');
        grid.setAttribute('role', 'table');
        grid.setAttribute('aria-label', 'Photo activity by scope and location');
        const headings = element('div', undefined, 'photo-inspector-analysis-row');
        headings.setAttribute('role', 'row');
        const labelHeading = element('span', undefined, 'photo-inspector-analysis-label');
        labelHeading.setAttribute('role', 'columnheader');
        labelHeading.append(element('span', 'Scope or location', 'visit-summary__accessible'));
        headings.append(labelHeading);
        metricData(photo.metrics).forEach(([icon, label]) => {
            const heading = element('span', undefined, 'visit-summary__metric photo-inspector-analysis-value');
            heading.setAttribute('role', 'columnheader');
            heading.title = label;
            const svg = inspectorIcon(icon);
            if (svg) heading.append(svg);
            heading.append(element('span', label, 'visit-summary__accessible'));
            headings.append(heading);
        });
        grid.append(headings);
        const row = (label, metrics, total = false, albumId = null) => {
            const result = element('div', undefined, `photo-inspector-analysis-row${total ? ' photo-inspector-analysis-total' : ''}`);
            result.setAttribute('role', 'row');
            const name = element('span', label, 'photo-inspector-analysis-label');
            name.setAttribute('role', 'rowheader');
            if (albumId !== null) name.dataset.albumId = albumId;
            result.append(name);
            metricData(metrics || {}).forEach(([, metricLabel, value, description]) => {
                const cell = element('span', undefined, 'photo-inspector-analysis-value');
                cell.setAttribute('role', 'cell');
                if (value > 0) {
                    const number = element('span', value.toLocaleString(), total ? 'ranking-primary' : '');
                    number.setAttribute('aria-label', `${metricLabel}: ${value.toLocaleString()}`);
                    addSearchDetail(number, metricLabel, value, description);
                    cell.append(number);
                }
                result.append(cell);
            });
            return result;
        };
        const scope = (data, isVisit) => {
            const group = element('div', undefined, 'photo-inspector-analysis-scope');
            group.setAttribute('role', 'rowgroup');
            group.setAttribute('aria-label', isVisit ? 'This visit' : 'Overall activity');
            const available = !isVisit || data.status === 'available';
            group.append(row(isVisit ? 'This visit' : 'Overall activity', available ? data.metrics : null, true));
            const note = (content, isSpan = false) => {
                const line = element('div', undefined, 'photo-inspector-analysis-row');
                line.setAttribute('role', 'row');
                const cell = element('div', undefined, `photo-inspector-analysis-note${isSpan ? ' photo-inspector-analysis-note--span' : ''}`);
                cell.setAttribute('role', 'cell');
                cell.setAttribute('aria-colspan', '4');
                cell.append(content);
                line.append(cell);
                group.append(line);
            };
            if (!available) {
                note(element('p', data.status === 'outside_period' ? 'This visit is outside the selected period.' : 'Visit context unavailable.'));
            } else {
                if (isVisit && data.first_activity) {
                    const first = data.first_activity, last = data.latest_activity || first;
                    const time = first.slice(0, 10) === last.slice(0, 10)
                        ? first.slice(11, 16) + (first.slice(11, 16) === last.slice(11, 16) ? '' : `–${last.slice(11, 16)}`)
                        : `${formatTimestamp(first)}–${formatTimestamp(last)}`;
                    const span = element('span', undefined, 'visit-summary__metric visit-summary__metric--span');
                    span.title = `Recorded activity span: ${time}`;
                    const clock = inspectorIcon('clock');
                    if (clock) span.append(clock);
                    span.append(element('span', time, 'visit-summary__value'));
                    note(span, true);
                }
                if (!metricData(data.metrics).some(([, , value]) => value > 0)) {
                    note(element('p', isVisit ? 'No activity for this photo in this visit.' : 'No activity in this period'));
                }
                data.activity.forEach(context => group.append(row(context.title, context.metrics, false, context.album_id)));
            }
            grid.append(group);
        };
        scope(photo, false);
        if (visit) scope(visit, true);
        inspector.append(grid);
        if (photo.albums.length) {
            const membership = element('p', undefined, 'photo-inspector-membership-line');
            membership.append(element('span', 'Belongs to', 'photo-search-membership-label'));
            photo.albums.forEach((album, index) => {
                if (index) membership.append(document.createTextNode(' · '));
                const name = element('span', album.title);
                name.dataset.albumId = album.id;
                membership.append(name);
            });
            inspector.append(membership);
        }
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
                    } else if (view === 'search') {
                        renderSearchResult(inspector, payload.photo);
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
