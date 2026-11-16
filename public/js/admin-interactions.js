(() => {
    const interactiveSelector = '.menu-button, .admin-shell button, .admin-shell .button-link';
    document.addEventListener('pointerdown', (event) => {
        const control = event.target.closest(interactiveSelector);
        if (!control || control.disabled) return;
        control.classList.remove('gaugeiq-tap-flash');
        // Restart the short activation animation on every tap.
        void control.offsetWidth;
        control.classList.add('gaugeiq-tap-flash');
        window.setTimeout(() => control.classList.remove('gaugeiq-tap-flash'), 460);
    }, { passive: true });

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        const submitter = event.submitter || form.querySelector('button[type="submit"], button:not([type])');
        if (!submitter || !submitter.matches(interactiveSelector)) return;
        submitter.classList.add('gaugeiq-is-loading');
        submitter.setAttribute('aria-busy', 'true');
        submitter.disabled = true;
    }, true);
})();
