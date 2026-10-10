(() => {
    document.querySelectorAll('[data-activity-trail]').forEach(trail => {
        const journey = trail.closest('.visit-journey');
        const stops = [...trail.querySelectorAll('[data-trail-stop]')];
        const segments = [...journey.querySelectorAll('[data-activity-segment]')];
        if (stops.length < 2 || stops.length !== segments.length) return;
        const controls = [...journey.querySelectorAll('[data-trail-scroll]')];
        const shell = trail.closest('[data-trail-shell]');
        const panel = journey.querySelector('.visit-activity-summary');
        let selected = 0;
        let pointerFrame;
        const positionPointer = () => {
            if (!panel) return;
            const box = panel.getBoundingClientRect();
            if (!box.width) return;
            const stop = stops[selected].getBoundingClientRect();
            const center = (stop.left + stop.right) / 2 - box.left - panel.clientLeft;
            panel.style.setProperty('--trail-pointer-x', `${Math.max(16, Math.min(center, panel.clientWidth - 16))}px`);
            panel.setAttribute('data-trail-pointer', '');
        };
        const schedulePointer = () => {
            if (pointerFrame) return;
            pointerFrame = requestAnimationFrame(() => { pointerFrame = null; positionPointer(); });
        };
        const updateControls = () => {
            // Keep long stop widths stable when edge arrows appear or disappear.
            if (shell?.clientWidth) trail.style.setProperty('--trail-stop-width', `${Math.max(1, shell.clientWidth - 80)}px`);
            const end = Math.max(0, trail.scrollWidth - trail.clientWidth);
            controls.forEach(control => {
                control.hidden = control.dataset.trailScroll === '-1' ? trail.scrollLeft <= 1 : trail.scrollLeft >= end - 1;
                if (control.hidden && document.activeElement === control) trail.focus({preventScroll: true});
            });
            schedulePointer();
        };
        const revealSelected = () => {
            // Edge controls change the viewport width; settle it before revealing the whole stop.
            for (let pass = 0; pass < 3; pass++) {
                updateControls();
                const box = trail.getBoundingClientRect();
                const stop = stops[selected].getBoundingClientRect();
                if (stop.left < box.left) trail.scrollLeft += stop.left - box.left;
                else if (stop.right > box.right) trail.scrollLeft += stop.right - box.right;
            }
            updateControls();
            positionPointer();
        };
        const select = index => {
            selected = index;
            stops.forEach((stop, i) => stop.setAttribute('aria-pressed', String(i === index)));
            segments.forEach((segment, i) => { segment.hidden = i !== index; });
            revealSelected();
        };
        controls.forEach(control => control.addEventListener('click', () => {
            trail.scrollLeft += Number(control.dataset.trailScroll) * Math.max(100, trail.clientWidth * .8);
            updateControls();
        }));
        trail.addEventListener('scroll', updateControls, {passive: true});
        if (typeof ResizeObserver !== 'undefined') {
            const observer = new ResizeObserver(updateControls);
            observer.observe(shell || trail);
            if (panel) observer.observe(panel);
        }
        document.fonts?.ready.then(schedulePointer);
        window.addEventListener('resize', () => requestAnimationFrame(revealSelected));
        stops.forEach((stop, index) => {
            stop.addEventListener('click', () => select(index));
            stop.addEventListener('keydown', event => {
                if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey || event.isComposing) return;
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                event.preventDefault();
                event.stopPropagation();
                const next = event.key === 'Home' ? 0 : event.key === 'End' ? stops.length - 1
                    : (index + (event.key === 'ArrowRight' ? 1 : -1) + stops.length) % stops.length;
                stops[next].focus({preventScroll: true});
                select(next);
            });
        });
        journey.classList.add('visit-journey--trail');
        trail.hidden = false;
        select(0);
        const details = journey.closest('details.visit-item');
        details?.addEventListener('toggle', () => { if (details.open) select(0); });
    });
    document.querySelectorAll('[data-visit-gallery]').forEach(gallery => {
        const more = gallery.querySelector('[data-gallery-more]');
        if (!more) return;
        const grid = gallery.querySelector('[data-gallery-grid]');
        const data = gallery.querySelector('[data-gallery-remaining]');
        const status = gallery.querySelector('[data-gallery-status]');
        let photos;
        try {
            photos = JSON.parse(data.textContent);
            if (!Array.isArray(photos)) throw new Error('Invalid gallery');
        } catch (error) {
            more.disabled = true;
            status.textContent = 'More photos unavailable.';
            return;
        }
        data.remove();
        let next = 0;
        const reveal = () => {
            if (next >= photos.length) return;
            const fragment = document.createDocumentFragment();
            const batch = photos.slice(next, next + 50);
            batch.forEach(photo => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'visit-photo-trigger';
                button.dataset.visitPhoto = '';
                button.dataset.photoId = photo.id;
                button.dataset.visitId = gallery.dataset.visitId;
                button.setAttribute('aria-haspopup', 'dialog');
                button.setAttribute('aria-expanded', 'false');
                button.setAttribute('aria-controls', 'visit-photo-popover');
                button.setAttribute('aria-label', `Inspect ${photo.title} in this Visit`);
                button.title = photo.title;
                if (photo.image_url) {
                    const image = document.createElement('img');
                    image.src = photo.image_url;
                    image.alt = '';
                    image.loading = 'lazy';
                    button.append(image);
                } else {
                    const placeholder = document.createElement('span');
                    placeholder.textContent = '–';
                    placeholder.setAttribute('aria-hidden', 'true');
                    button.append(placeholder);
                }
                fragment.append(button);
            });
            const firstNew = fragment.firstElementChild;
            grid.insertBefore(fragment, more);
            next += batch.length;
            status.textContent = `${batch.length} more photos shown. ${grid.querySelectorAll('[data-visit-photo]').length} photos visible.`;
            // Put keyboard users at the new photos, including when the final control disappears.
            if (document.activeElement === more) firstNew?.focus({preventScroll: true});
            if (next >= photos.length) more.remove();
            else {
                const count = Math.min(50, photos.length - next);
                more.textContent = `+${count}`;
                more.setAttribute('aria-label', `Show ${count} more photos`);
            }
        };
        more.addEventListener('click', reveal);
        // Synchronous reveal lets the popover advance across a batch boundary.
        gallery.addEventListener('visit-gallery-reveal', reveal);
    });
})();
