(() => {
    // Track intent before click handlers move focus into dialogs or back to an opener.
    // Keep native :focus-visible as the fallback before the first interaction.
    const pointer = () => { document.documentElement.dataset.ioaInput = 'pointer'; };
    document.addEventListener('pointerdown', pointer, {capture: true, passive: true});
    document.addEventListener('mousedown', pointer, {capture: true, passive: true});
    document.addEventListener('touchstart', pointer, {capture: true, passive: true});
    document.addEventListener('keydown', event => {
        if (event.metaKey || event.altKey || event.ctrlKey || ['Shift', 'Meta', 'Alt', 'Control'].includes(event.key)) return;
        document.documentElement.dataset.ioaInput = 'keyboard';
    }, true);
})();
