(() => {
    const popover = document.querySelector('[data-visit-photo-popover]');
    if (!popover) return;
    const content = popover.querySelector('[data-popover-content]');
    const status = popover.querySelector('[data-popover-status]');
    let anchor = null;
    const close = (restoreFocus = false) => {
        if (!anchor) return;
        const previous = anchor;
        anchor = null;
        previous.setAttribute('aria-expanded', 'false');
        instance.clear();
        popover.hidden = true;
        status.textContent = '';
        if (restoreFocus && previous.isConnected) previous.focus();
    };
    const position = () => {
        if (!anchor || popover.hidden) return;
        const viewport = window.visualViewport;
        const left = viewport?.offsetLeft || 0, top = viewport?.offsetTop || 0;
        const width = viewport?.width || window.innerWidth;
        const height = viewport?.height || window.innerHeight;
        const edge = 12, gap = 10;
        const rect = anchor.getBoundingClientRect();
        if (!anchor.isConnected || !anchor.getClientRects().length || rect.bottom < top
            || rect.top > top + height || rect.right < left || rect.left > left + width) {
            close(popover.contains(document.activeElement));
            return;
        }
        popover.style.maxWidth = `${Math.max(1, width - edge * 2)}px`;
        popover.style.maxHeight = `${Math.max(1, height - edge * 2)}px`;
        popover.style.setProperty('--popover-max-height', popover.style.maxHeight);
        const box = popover.getBoundingClientRect();
        let x = rect.left + (rect.width - box.width) / 2;
        let y = rect.top - box.height - gap;
        if (y < top + edge) {
            if (rect.right + gap + box.width <= left + width - edge) {
                x = rect.right + gap;
                y = rect.top;
            } else if (rect.bottom + gap + box.height <= top + height - edge) {
                y = rect.bottom + gap;
            } else if (rect.left - gap - box.width >= left + edge) {
                x = rect.left - gap - box.width;
                y = rect.top;
            } else {
                y = rect.bottom + gap;
            }
        }
        x = Math.max(left + edge, Math.min(x, left + width - edge - box.width));
        y = Math.max(top + edge, Math.min(y, top + height - edge - box.height));
        popover.style.left = `${x}px`;
        popover.style.top = `${y}px`;
        // Derive the pointer from the final clamped position, not the preferred side.
        let placement = null, offset = 0;
        if (y + box.height <= rect.top) placement = 'above';
        else if (y >= rect.bottom) placement = 'below';
        else if (x + box.width <= rect.left) placement = 'left';
        else if (x >= rect.right) placement = 'right';
        if (placement) {
            const vertical = placement === 'left' || placement === 'right';
            const size = vertical ? box.height : box.width;
            offset = vertical ? (rect.top + rect.bottom) / 2 - y : (rect.left + rect.right) / 2 - x;
            popover.dataset.placement = placement;
            popover.style.setProperty('--pointer-offset', `${Math.max(16, Math.min(offset, size - 16))}px`);
        } else {
            // On tiny viewports an overlapping fallback has no truthful pointer direction.
            delete popover.dataset.placement;
        }
    };
    const instance = window.IOAPhotoInspector.create(content, {
        view: 'visit',
        onLoad() { status.textContent = 'Visit photo activity loaded.'; position(); },
        onError() { status.textContent = 'Photo details unavailable.'; position(); }
    });
    const open = trigger => {
        close();
        anchor = trigger;
        trigger.setAttribute('aria-expanded', 'true');
        popover.hidden = false;
        popover.querySelector('.visit-photo-popover-surface').scrollTop = 0;
        status.textContent = 'Loading Visit photo activity…';
        instance.load(trigger.dataset.photoId, trigger.dataset.visitId);
        position();
        if (!popover.hidden) popover.focus({preventScroll: true});
    };
    // Delegation also covers thumbnails added by progressive gallery reveal.
    document.addEventListener('click', event => {
        const trigger = event.target instanceof Element ? event.target.closest('[data-visit-photo]') : null;
        if (trigger) open(trigger);
    });
    popover.querySelector('[data-popover-close]').addEventListener('click', () => close(true));
    const openFull = () => {
        if (!anchor) return;
        const source = anchor;
        close();
        const dialog = document.querySelector('[data-photo-inspector-modal]');
        if (window.openPhotoInspector(source.dataset.photoId, source, source.dataset.visitId) && dialog) {
            // Let the modal finish its normal focus restoration before resuming browsing.
            dialog.addEventListener('close', () => queueMicrotask(() => {
                if (!dialog.open && source.isConnected && document.activeElement === source) open(source);
            }), {once: true});
        }
    };
    popover.querySelector('[data-popover-full]').addEventListener('click', openFull);
    const verticalTarget = (photos, direction) => {
        const source = anchor.getBoundingClientRect();
        const rows = [];
        photos.forEach(photo => {
            const rect = photo.getBoundingClientRect();
            let row = rows.find(row => Math.abs(row.top - rect.top) < 2);
            if (!row) rows.push(row = {top: rect.top, photos: []});
            row.photos.push({photo, x: (rect.left + rect.right) / 2});
        });
        rows.sort((a, b) => a.top - b.top);
        const index = rows.findIndex(row => row.photos.some(item => item.photo === anchor));
        const row = rows[index + direction];
        const x = (source.left + source.right) / 2;
        return {
            photo: row?.photos.reduce((best, item) => Math.abs(item.x - x) < Math.abs(best.x - x) ? item : best).photo,
            needsMore: direction === 1 && (!row || (row === rows[rows.length - 1] && x > Math.max(...row.photos.map(item => item.x)) + 2))
        };
    };
    document.addEventListener('pointerdown', event => {
        if (anchor && !popover.contains(event.target) && !anchor.contains(event.target)) {
            close(popover.contains(document.activeElement));
        }
    });
    document.addEventListener('keydown', event => {
        if (!anchor || document.querySelector('[data-photo-inspector-modal][open]')) return;
        const target = event.target;
        if (target instanceof Element && (target.closest('input, textarea, select, [role="textbox"]')
            || target.isContentEditable)) return;
        if (anchor && event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close(true);
            return;
        }
        if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey || event.isComposing) return;
        if (event.key === 'Enter') {
            // Preserve activation of the explicitly focused close button.
            if (target instanceof Element && target.closest('[data-popover-close]')) return;
            event.preventDefault();
            openFull();
            return;
        }
        if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return;
        const gallery = anchor.closest('[data-visit-gallery]');
        if (!gallery) return;
        event.preventDefault();
        const vertical = event.key === 'ArrowUp' || event.key === 'ArrowDown';
        const direction = event.key === 'ArrowRight' || event.key === 'ArrowDown' ? 1 : -1;
        let photos = [...gallery.querySelectorAll('[data-visit-photo]')];
        const index = photos.indexOf(anchor);
        if (index < 0) return;
        if (vertical ? verticalTarget(photos, direction).needsMore : direction === 1 && index === photos.length - 1) {
            gallery.dispatchEvent(new Event('visit-gallery-reveal'));
            photos = [...gallery.querySelectorAll('[data-visit-photo]')];
        }
        const next = vertical ? verticalTarget(photos, direction).photo : photos[index + direction];
        if (!next) return;
        const rect = next.getBoundingClientRect();
        const viewport = window.visualViewport;
        const top = viewport?.offsetTop || 0, left = viewport?.offsetLeft || 0;
        if (rect.top < top + 12 || rect.bottom > top + (viewport?.height || window.innerHeight) - 12
            || rect.left < left + 12 || rect.right > left + (viewport?.width || window.innerWidth) - 12) {
            // Minimal immediate scrolling keeps positioning and focus tied to the new anchor.
            next.scrollIntoView({behavior: 'instant', block: 'nearest', inline: 'nearest'});
        }
        open(next);
    }, true);
    document.addEventListener('focusin', event => {
        if (anchor && !popover.contains(event.target) && !anchor.contains(event.target)) close();
    });
    // Reposition after scrolling, resizing, image loading, and disclosure changes.
    window.addEventListener('scroll', position, true);
    window.addEventListener('resize', position);
    window.visualViewport?.addEventListener('resize', position);
    window.visualViewport?.addEventListener('scroll', position);
    document.addEventListener('toggle', position, true);
    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(position).observe(popover);
})();
