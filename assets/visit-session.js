(() => {
    document.querySelector('[data-session-clear-url]')?.addEventListener('click', event => {
        window.location.assign(event.currentTarget.dataset.sessionClearUrl);
    });
    const rows = [...document.querySelectorAll('[data-visit-session]')];
    let hovered = null, focused = null;
    const update = () => {
        const control = hovered || focused;
        const session = control?.closest('[data-visit-session]').dataset.visitSession;
        rows.forEach(row => row.classList.toggle('session-preview-dim', !!session && row.dataset.visitSession !== session));
    };
    document.querySelectorAll('[data-visit-session-control]').forEach(control => {
        control.addEventListener('pointerenter', event => {
            if (event.pointerType === 'touch') return;
            hovered = control; update();
        });
        control.addEventListener('pointerleave', () => { hovered = null; update(); });
        control.addEventListener('focus', () => { focused = control; update(); });
        control.addEventListener('blur', () => { focused = null; update(); });
        control.addEventListener('click', event => event.stopPropagation());
    });
})();
