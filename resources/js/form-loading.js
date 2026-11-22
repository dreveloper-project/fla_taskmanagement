import { formSubmitMethod, isFormAction, isFormMessage } from './form-notifications.js';

export function installFormLoading(document, browser = window) {
    const pending = new Set();
    const initialized = new WeakSet();
    let installedLivewire = false;
    let activePopup = null;
    let redirecting = false;

    function show(token) {
        pending.add(token);
        const popup = document.querySelector('[data-form-loading]');
        if (!popup || popup.open) return;
        activePopup = popup;
        if (!initialized.has(popup)) {
            initialized.add(popup);
            popup.addEventListener('cancel', (event) => event.preventDefault());
        }
        popup.showModal();
        if (!browser.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            popup.animate([
                { opacity: 0, transform: 'translateY(12px) scale(0.96)' },
                { opacity: 1, transform: 'translateY(0) scale(1)' },
            ], { duration: 220, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' });
        }
    }

    function finish(token) {
        pending.delete(token);
        closeIfFinished();
    }

    function closeIfFinished() {
        if (pending.size === 0 && !redirecting && activePopup?.open) activePopup.close();
    }

    function reset() {
        pending.clear();
        redirecting = false;
        if (activePopup?.open) activePopup.close();
        activePopup = null;
    }

    function initializeLivewire() {
        if (installedLivewire || !browser.Livewire) return;
        installedLivewire = true;
        browser.Livewire.interceptMessage(({ message, onSuccess }) => {
            if (!isFormMessage(message)) return;
            onSuccess(({ payload }) => {
                if (pending.size > 0 && payload.effects?.redirect) redirecting = true;
            });
        });
        browser.Livewire.interceptAction(({ action, onFinish, onError, onFailure, onCancel }) => {
            if (!isFormAction(action) || action.isCancelled?.()) return;
            const token = {};
            let finished = false;
            const done = () => {
                if (finished) return;
                finished = true;
                finish(token);
            };
            onError(done);
            onFailure(done);
            onCancel(done);
            onFinish(done);
            show(token);
        });
    }

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!form.matches?.('form') || event.defaultPrevented) return;
        if (formSubmitMethod(form)) return;
        const target = event.submitter?.getAttribute('formtarget') ?? form.getAttribute('target');
        const method = event.submitter?.getAttribute('formmethod') ?? form.getAttribute('method');
        if ((target && target.toLowerCase() !== '_self') || method?.toLowerCase() === 'dialog') return;
        if (pending.has(form)) {
            event.preventDefault();
            return;
        }
        queueMicrotask(() => {
            if (form.isConnected && !event.defaultPrevented) show(form);
        });
    }, true);

    document.addEventListener('livewire:init', initializeLivewire);
    document.addEventListener('livewire:navigate', (event) => {
        if (!redirecting) return;
        queueMicrotask(() => {
            if (!event.defaultPrevented) return;
            redirecting = false;
            closeIfFinished();
        });
    });
    document.addEventListener('livewire:navigating', reset);
    document.addEventListener('livewire:navigated', reset);
    browser.addEventListener('pageshow', reset);
    initializeLivewire();
}
