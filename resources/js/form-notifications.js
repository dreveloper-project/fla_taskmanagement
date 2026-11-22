const redirectNotificationKey = 'fla-form-notification';

export function notificationFromResponse(payload, { errors = [], successes = [] } = {}) {
    const snapshot = typeof payload.snapshot === 'string' ? JSON.parse(payload.snapshot) : payload.snapshot;
    const messages = errors.length ? errors : Object.values(snapshot?.memo?.errors || {}).flat();
    return messages.length
        ? { type: 'error', messages: [...new Set(messages)] }
        : { type: 'success', messages: successes.length ? [...new Set(successes)] : ['Form berhasil diproses.'] };
}

export function formSubmitMethod(form) {
    const directive = form.getAttributeNames?.().find((name) => name === 'wire:submit' || name.startsWith('wire:submit.'));
    const expression = form.getAttribute(directive || 'wire:submit');
    return expression ? expression.split('(')[0].trim().replace(/^\$wire\./, '') : null;
}

export function messageForms(message) {
    const root = message.component.el;
    return [ ...(root.matches?.('form') ? [root] : []), ...root.querySelectorAll('form') ]
        .filter((form) => !form.closest || form.closest('[wire\\:id]') === root)
        .filter((form) => formSubmitMethod(form));
}

export function isFormAction(action, component = action.component) {
    const origin = action.origin?.el;
    if (origin?.matches?.('form') && formSubmitMethod(origin) === action.name) return true;
    const methods = messageForms({ component }).map(formSubmitMethod);
    return methods.includes(action.name) || ['ready', 'changeStatus'].includes(action.name);
}

export function isFormMessage(message) {
    return [...message.actions].some((action) => isFormAction(action, message.component));
}

export function installFormNotifications(document, browser = window) {
    const initialized = new WeakSet();
    let installedLivewire = false;
    let animation;

    function show(notification) {
        const popup = document.querySelector('[data-form-popup]');
        if (!popup || !notification?.messages?.length) return;
        const success = notification.type === 'success';
        popup.querySelector('#form-popup-title').textContent = success ? 'Berhasil!' : 'Form belum berhasil diproses';
        const icon = popup.querySelector('[data-popup-icon]');
        icon.textContent = success ? '✓' : '!';
        icon.className = 'flex size-16 items-center justify-center rounded-full text-3xl font-semibold '
            + (success ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400' : 'bg-rose-50 text-rose-600 dark:bg-rose-950/40 dark:text-rose-400');
        const list = popup.querySelector('#form-popup-messages');
        list.replaceChildren();
        for (const message of notification.messages) {
            const item = document.createElement('li');
            item.textContent = message;
            list.append(item);
        }
        if (!popup.open) popup.showModal();
        animation?.cancel();
        if (!browser.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            animation = popup.animate([
                { opacity: 0, transform: 'translateY(20px) scale(0.92)' },
                { opacity: 1, transform: 'translateY(0) scale(1)' },
            ], { duration: 260, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' });
        }
        popup.querySelector('[data-popup-close]').focus();
    }

    function readMessages(html) {
        const view = new browser.DOMParser().parseFromString(html || '', 'text/html');
        return {
            errors: [...view.querySelectorAll('[data-form-error] li p')].map((node) => node.textContent.trim()),
            successes: [...view.querySelectorAll('[data-form-success]')].map((node) => node.textContent.trim()),
        };
    }

    function initializePage() {
        const popup = document.querySelector('[data-form-popup]');
        if (!popup || initialized.has(popup)) return;
        initialized.add(popup);
        popup.querySelector('[data-popup-close]').addEventListener('click', () => popup.close());
        let pending = null;
        try {
            pending = JSON.parse(browser.sessionStorage.getItem(redirectNotificationKey));
            browser.sessionStorage.removeItem(redirectNotificationKey);
        } catch { /* Storage may be unavailable in private browsing. */ }
        const initial = JSON.parse(popup.dataset.initialNotification || 'null');
        if (initial || pending) show(initial || pending);
    }

    function initializeLivewire() {
        if (installedLivewire || !browser.Livewire) return;
        installedLivewire = true;
        browser.Livewire.interceptMessage(({ message, onSuccess, onError, onFailure }) => {
            if (!isFormMessage(message)) return;
            onSuccess(({ payload, onRender }) => {
                const notification = notificationFromResponse(payload, readMessages(payload.effects?.html));
                if (payload.effects?.redirect && notification.type === 'success') {
                    try { browser.sessionStorage.setItem(redirectNotificationKey, JSON.stringify(notification)); }
                    catch { show(notification); }
                } else {
                    onRender(() => show(notification));
                }
            });
            onError(({ response, preventDefault }) => {
                preventDefault();
                const messages = {
                    403: 'Anda tidak memiliki izin untuk menjalankan aksi ini.',
                    419: 'Sesi berakhir. Muat ulang halaman lalu coba kembali.',
                    429: 'Terlalu banyak permintaan. Tunggu sebentar lalu coba kembali.',
                };
                show({ type: 'error', messages: [messages[response.status] || 'Proses gagal. Silakan coba kembali.'] });
            });
            onFailure(() => show({ type: 'error', messages: ['Koneksi terputus. Periksa koneksi dan status data sebelum mencoba kembali.'] }));
        });
    }

    document.addEventListener('form-notification', (event) => show(event.detail));
    document.addEventListener('livewire-upload-error', () => show({
        type: 'error', messages: ['Unggahan file gagal. Periksa file dan koneksi lalu coba kembali.'],
    }));
    document.addEventListener('livewire:navigated', initializePage);
    document.addEventListener('livewire:init', initializeLivewire);
    initializeLivewire();
    initializePage();
}
