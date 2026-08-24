document.addEventListener('DOMContentLoaded', function () {

    const IOA = {
        photoMap: new Map(),
        lastLightboxSrc: null
    };

    function normalizeUrl(src) {
        if (!src) return null;
        return new URL(src, window.location.origin).href;
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
const event = {
    type: type,
    timestamp: new Date().toISOString(),
    page: window.location.pathname,
    session_id: getSessionId(),
    ...data,
    is_admin: shouldIgnoreBrowser()
};

        console.log('[IO200 Analytics]', event);

        fetch('/storage/custom/io200-analytics/collect.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(event)
        })
        .then(response => response.json())
        .then(result => {
            console.log('[IO200 Analytics] Collector response:', result);
        })
        .catch(error => {
            console.error('[IO200 Analytics] Collector error:', error);
        });
    }

    document
        .querySelectorAll('.photo-wrapper[data-photoid]')
        .forEach(function(wrapper) {

            const link = wrapper.closest('a.js-lightbox');
            if (!link) return;

            const src = normalizeUrl(link.getAttribute('href'));
            const photoId = wrapper.dataset.photoid;

            if (!src || !photoId) return;

            IOA.photoMap.set(src, photoId);
        });

    console.log(
        '[IO200 Analytics] Photo map ready:',
        IOA.photoMap.size,
        'photos'
    );

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

    const lightboxObserver = new MutationObserver(function() {
        checkCurrentLightboxImage();
    });

    lightboxObserver.observe(document.body, {
        subtree: true,
        attributes: true,
        attributeFilter: ['class'],
        childList: true
    });

    document.addEventListener('click', function(event) {

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

        MyApp.hooks.onPhotoDownload = function(data) {

            trackEvent('photo_download', {
                photo_id: data.photo_id,
                download_url: data.download_url,
                mode: 'single'
            });
        };

        MyApp.hooks.onFinishedAlbumDownload = function(data) {

            trackEvent('batch_download', {
                photo_ids: data.photo_ids,
                photo_urls: data.photo_urls,
                count: data.photo_ids.length
            });
        };
    }

    console.log('[IO200 Analytics] Prototype running');

});
