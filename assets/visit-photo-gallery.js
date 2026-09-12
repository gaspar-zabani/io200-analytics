(() => {
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
