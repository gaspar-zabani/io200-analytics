(() => {
    const dialog = document.querySelector('[data-checkpoint-dialog]');
    const split = document.querySelector('[data-checkpoint-split]');
    const toggle = document.querySelector('[data-checkpoint-toggle]');
    const menu = document.querySelector('[data-checkpoint-menu]');
    let dialogOpener;
    const closeMenu = (restoreFocus = false) => {
        if (!menu || menu.hidden) return;
        menu.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
        if (restoreFocus) toggle.focus();
    };
    const openMenu = (focusLast = false) => {
        if (!menu) return;
        menu.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        menu.style.left = '';
        menu.style.right = '0';
        const box = menu.getBoundingClientRect();
        if (box.left < 12) { menu.style.right = 'auto'; menu.style.left = `${12 - split.getBoundingClientRect().left}px`; }
        const items = menu.querySelectorAll('button, a[href]');
        (focusLast ? items[items.length - 1] : items[0])?.focus();
    };
    toggle?.addEventListener('click', () => menu.hidden ? openMenu() : closeMenu(true));
    toggle?.addEventListener('keydown', event => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            openMenu(event.key === 'ArrowUp');
        }
    });
    menu?.addEventListener('keydown', event => {
        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
        const items = Array.from(menu.querySelectorAll('button, a[href]'));
        const index = items.indexOf(document.activeElement);
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1
            : (index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
        event.preventDefault();
        items[next]?.focus();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && menu && !menu.hidden) { event.preventDefault(); closeMenu(true); }
    });
    document.addEventListener('click', event => { if (split && !split.contains(event.target)) closeMenu(); });
    split?.addEventListener('focusout', event => { if (!split.contains(event.relatedTarget)) closeMenu(); });
    window.addEventListener('resize', () => closeMenu());
    document.querySelectorAll('[data-checkpoint-choice]').forEach(link => { link.addEventListener('click', () => closeMenu()); });
    document.querySelectorAll('[data-checkpoint-open]').forEach(button => {
        button.addEventListener('click', () => {
            dialogOpener = button;
            closeMenu();
            dialog?.showModal();
            if (button.dataset.checkpointOpen === 'create') document.getElementById('new-checkpoint-name')?.focus();
            else document.querySelector('[data-checkpoint-manage-focus]')?.focus();
        });
    });
    document.querySelector('[data-checkpoint-close]')?.addEventListener('click', () => dialog?.close());
    dialog?.addEventListener('close', () => {
        const mobile = document.querySelector('[data-period-select]');
        const target = mobile?.getClientRects().length ? (dialogOpener?.getClientRects().length ? dialogOpener : mobile) : toggle;
        target?.focus();
    });
    document.querySelectorAll('[data-activity-select], [data-period-select], [data-checkpoint-select]').forEach(select => {
        select.addEventListener('change', event => {
            const option = event.target.selectedOptions[0];
            const url = new URL(option.dataset.filterUrl || option.dataset.activityUrl, location.href);
            location.assign(url.href);
        });
    });
})();
