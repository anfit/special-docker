(() => {
    'use strict';

    const buttons = document.querySelectorAll('[data-copy-target]');

    for (const button of buttons) {
        if (!(button instanceof HTMLButtonElement)) {
            continue;
        }

        const targetId = button.dataset.copyTarget;
        const target = targetId === undefined ? null : document.getElementById(targetId);
        const sourceId = button.dataset.copySource;
        const source = sourceId === undefined ? target : document.getElementById(sourceId);
        const statusId = button.dataset.copyStatus;
        const status = statusId === undefined ? null : document.getElementById(statusId);
        const defaultLabel = button.textContent || 'Copy';
        let resetTimer = 0;

        if (target === null || source === null) {
            button.disabled = true;
            continue;
        }

        button.addEventListener('click', async () => {
            button.disabled = true;
            try {
                await copyText(copyValue(source));
                button.textContent = 'Copied';
                announce(status, 'Copied to clipboard.');
            } catch {
                button.textContent = defaultLabel;
                announce(status, 'Copy failed. Select the text manually.');
            } finally {
                window.clearTimeout(resetTimer);
                resetTimer = window.setTimeout(() => {
                    button.textContent = defaultLabel;
                    button.disabled = false;
                }, 2000);
            }
        });
    }

    function copyValue(element) {
        return element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement
            ? element.value
            : element.textContent || '';
    }

    async function copyText(text) {
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            await navigator.clipboard.writeText(text);
            return;
        }

        const helper = document.createElement('textarea');
        helper.value = text;
        helper.setAttribute('readonly', '');
        helper.style.position = 'fixed';
        helper.style.opacity = '0';
        document.body.appendChild(helper);
        helper.select();
        helper.setSelectionRange(0, helper.value.length);

        let copied = false;
        try {
            copied = document.execCommand('copy');
        } finally {
            helper.remove();
        }
        if (!copied) {
            throw new Error('Clipboard access is unavailable.');
        }
    }

    // Destructive forms ask for confirmation without inline handlers, which the page CSP forbids.
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (form instanceof HTMLFormElement && form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });

    function announce(status, message) {
        if (status !== null) {
            status.textContent = message;
        }
    }
})();
