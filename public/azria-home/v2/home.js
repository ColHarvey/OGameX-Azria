(() => {
    'use strict';
    const panels = [...document.querySelectorAll('[data-panel]')];
    const switches = [...document.querySelectorAll('[data-auth]')];
    const preview = document.body.dataset.preview === 'true';
    function selectPanel(name, focus = false) {
        const panel = panels.find(item => item.dataset.panel === name) || panels[0];
        if (!panel) return;
        panels.forEach(item => { item.hidden = item !== panel; });
        document.querySelector('.auth-tabs').hidden = panel.dataset.panel !== 'login';
        switches.forEach(item => {
            const active = item.dataset.auth === panel.dataset.panel;
            item.classList.toggle('active', active);
            if (item.tagName === 'BUTTON') item.setAttribute('aria-pressed', String(active));
        });
        if (focus) panel.querySelector('input')?.focus({ preventScroll: true });
    }
    const requested = document.body.dataset.errors === 'true'
        ? document.body.dataset.mode : (location.hash === '#login' ? 'login' : document.body.dataset.mode);
    selectPanel(requested);
    switches.forEach(item => item.addEventListener('click', event => {
        event.preventDefault();
        selectPanel(item.dataset.auth, true);
        if (matchMedia('(max-width: 800px)').matches) document.querySelector('.auth-panel').scrollIntoView({ block: 'start' });
    }));
    document.querySelector('[data-panel]:not([hidden]) [data-error-summary]')?.focus();
    const dialog = document.querySelector('#document-dialog');
    const frame = dialog.querySelector('iframe');
    document.querySelectorAll('[data-document]').forEach(link => link.addEventListener('click', event => {
        if (typeof dialog.showModal !== 'function') return;
        const target = new URL(link.href, location.href);
        if (!preview && target.origin !== location.origin) return;
        event.preventDefault();
        const title = link.textContent.trim();
        document.querySelector('#document-title').textContent = title;
        frame.title = title;
        if (preview) {
            const message = document.querySelector('.preview-feedback').textContent;
            const safeMessage = message.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
            frame.srcdoc = '<html><body style="font:18px sans-serif;padding:30px;color:#263342"><p>' + safeMessage + '</p></body></html>';
        } else {
            frame.removeAttribute('srcdoc');
            frame.src = target.href;
        }
        dialog.showModal();
    }));
    dialog.querySelector('.dialog-close').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    dialog.addEventListener('close', () => { frame.removeAttribute('src'); frame.removeAttribute('srcdoc'); });
    if (preview) {
        document.querySelectorAll('[data-game-form]').forEach(form => form.addEventListener('submit', event => {
            event.preventDefault();
            document.querySelector('.preview-feedback').hidden = false;
        }));
        document.querySelectorAll('.languages a, .password-help a').forEach(link => link.addEventListener('click', event => {
            event.preventDefault();
            document.querySelector('.preview-feedback').hidden = false;
        }));
    }
})();
