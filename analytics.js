document.addEventListener('DOMContentLoaded', function () {

    const IOA = {
        photoMap: new Map(),
        photoImageMap: new Map(),
        lastLightboxSrc: null
    };

    function normalizeUrl(src) {
        if (!src) return null;
        try {
            return new URL(src, window.location.origin).href;
        } catch (error) {
            return null;
        }
    }
	
	function getSessionId() {
    let sessionId = sessionStorage.getItem('ioa_session_id');

    if (!sessionId) {
        sessionId = crypto.randomUUID();
        sessionStorage.setItem('ioa_session_id', sessionId);
    }

    return sessionId;
}

    function shouldIgnoreBrowser() {
        try {
            return localStorage.getItem('ioa_ignore_browser') === '1' ? 1 : 0;
        } catch (error) {
            return 0;
        }
    }


    function trackEvent(type, data = {}) {
        try {
            const event = {
                type: type,
                timestamp: new Date().toISOString(),
                page: window.location.pathname,
                session_id: getSessionId(),
                ...data,
                is_admin: shouldIgnoreBrowser()
            };

            fetch('/storage/custom/io200-analytics/collect.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(event)
            }).catch(function () {
                // Analytics failures must not affect IO200 behavior.
            });
        } catch (error) {
            // Analytics failures must not affect IO200 behavior.
        }
    }

    // Track visits to individual IO200 Photo Pages.
    const cmsMeta = document.querySelector('meta[name="cms"][data-photo]');

    if (
        document.body.classList.contains('template-photo') &&
        cmsMeta &&
        cmsMeta.dataset.photo
    ) {
        const photoId = cmsMeta.dataset.photo;

        const mainPhotoLink = document.querySelector(
            '.photo-image .photo-wrapper[data-photoid="' + photoId + '"]'
        )?.closest('a.js-lightbox');

        trackEvent('photo_view', {
            photo_id: photoId,
            image_url: mainPhotoLink
                ? normalizeUrl(mainPhotoLink.getAttribute('href'))
                : null,
            source: 'photo_page'
        });
    }

    function registerPhotoWrappers(root) {
        const wrappers = [];

        if (
            root instanceof Element &&
            root.matches('.photo-wrapper[data-photoid]')
        ) {
            wrappers.push(root);
        }

        root
            .querySelectorAll('.photo-wrapper[data-photoid]')
            .forEach(function(wrapper) {
                wrappers.push(wrapper);
            });

        wrappers.forEach(function(wrapper) {

            const link = wrapper.closest('a.js-lightbox');
            if (!link) return;

            const src = normalizeUrl(link.getAttribute('href'));
            const photoId = wrapper.dataset.photoid;

            if (!src || !photoId) return;

            IOA.photoMap.set(src, photoId);
            IOA.photoImageMap.set(String(photoId), src);
        });
    }

    registerPhotoWrappers(document);

    function checkCurrentLightboxImage() {

        const img = document.querySelector('.gslide.current img');

        if (!img) {
            IOA.lastLightboxSrc = null;
            return;
        }

        const src = normalizeUrl(img.getAttribute('src'));

        if (!src || src === IOA.lastLightboxSrc) return;

        IOA.lastLightboxSrc = src;

        trackEvent('photo_view', {
            photo_id: IOA.photoMap.get(src) || null,
            image_url: src
        });
    }

    const lightboxObserver = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            mutation.addedNodes.forEach(function(node) {
                if (node instanceof Element) {
                    registerPhotoWrappers(node);
                }
            });
        });

        checkCurrentLightboxImage();
    });

    lightboxObserver.observe(document.body, {
        subtree: true,
        attributes: true,
        attributeFilter: ['class'],
        childList: true
    });

    document.addEventListener('click', function(event) {

        if (!(event.target instanceof Element)) return;

        const basket = event.target.closest('.action-basket');
        if (!basket) return;

        const wrapper =
            event.target.closest('.photo-wrapper[data-photoid]');

        if (!wrapper) return;

        const photoId = wrapper.dataset.photoid;
        const wasSelected = basket.classList.contains('selected');

        setTimeout(function() {

            const isSelected =
                basket.classList.contains('selected');

            if (wasSelected === isSelected) return;

const link = wrapper.closest('a.js-lightbox');
const imageUrl = link
    ? normalizeUrl(link.getAttribute('href'))
    : null;

trackEvent(
    isSelected ? 'basket_add' : 'basket_remove',
    {
        photo_id: photoId,
        image_url: imageUrl
    }
);

        }, 100);

    }, true);

    if (window.MyApp && MyApp.hooks) {

        const previousPhotoDownloadHook = MyApp.hooks.onPhotoDownload;
        const previousAlbumDownloadHook = MyApp.hooks.onFinishedAlbumDownload;

        MyApp.hooks.onPhotoDownload = function(data) {

            const result = typeof previousPhotoDownloadHook === 'function'
                ? previousPhotoDownloadHook.apply(this, arguments)
                : undefined;

            if (data && typeof data === 'object') {
                trackEvent('photo_download', {
                    photo_id: data.photo_id,
                    image_url:
                        IOA.photoImageMap.get(String(data.photo_id)) || null,
                    download_url: data.download_url,
                    mode: 'single'
                });
            }

            return result;
        };

        MyApp.hooks.onFinishedAlbumDownload = function(data) {

            const result = typeof previousAlbumDownloadHook === 'function'
                ? previousAlbumDownloadHook.apply(this, arguments)
                : undefined;

            if (
                data &&
                typeof data === 'object' &&
                Array.isArray(data.photo_ids) &&
                Array.isArray(data.photo_urls)
            ) {
                trackEvent('batch_download', {
                    photo_ids: data.photo_ids,
                    photo_urls: data.photo_urls,
                    image_urls: data.photo_ids.map(function(photoId) {
                        return IOA.photoImageMap.get(String(photoId)) || null;
                    }),
                    count: data.photo_ids.length
                });
            }

            return result;
        };
    }

});
