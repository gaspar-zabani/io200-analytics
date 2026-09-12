(() => {
    const dialog = document.querySelector('[data-photo-inspector-modal]');
    if (!dialog) return;
    const closeButton = dialog.querySelector('[data-inspector-close]');
    const status = dialog.querySelector('[role="status"]');
    let opener = null, backdropPress = false;
    const instance = window.IOAPhotoInspector.create(dialog.querySelector('[data-modal-inspector]'), {
        onLoad(photo) { status.textContent = `Photo ${photo.id} loaded.`; },
        onError() { status.textContent = 'Photo details unavailable.'; }
    });
    const cleanup = () => {
        instance.clear();
        status.textContent = '';
        backdropPress = false;
        const target = opener;
        opener = null;
        if (target?.isConnected) target.focus();
    };
    const close = () => {
        if (!dialog.open) return;
        dialog.close();
        cleanup();
    };
    window.openPhotoInspector = (photoId, sourceElement, visitId = null) => {
        const id = String(photoId);
        if (!/^[1-9]\d*$/.test(id)) return false;
        const source = sourceElement || document.activeElement;
        if (source instanceof HTMLElement && !dialog.contains(source)) opener = source;
        if (!dialog.open) dialog.showModal();
        status.textContent = 'Loading photo…';
        closeButton.focus();
        instance.load(id, visitId);
        return true;
    };
    closeButton.addEventListener('click', close);
    dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
    dialog.addEventListener('close', () => { if (!dialog.open) cleanup(); });
    const outside = event => {
        const rect = dialog.getBoundingClientRect();
        return event.target === dialog && (event.clientX < rect.left || event.clientX > rect.right
            || event.clientY < rect.top || event.clientY > rect.bottom);
    };
    dialog.addEventListener('pointerdown', event => { backdropPress = outside(event); });
    dialog.addEventListener('click', event => {
        if (backdropPress && outside(event)) close();
        backdropPress = false;
    });
})();
