export function installPageLoading(document, browser = window) {
    let completion = 0;
    let active = null;
    let timeout;

    function reset() {
        completion++;
        active = null;
        browser.clearTimeout(timeout);
        const loading = document.querySelector('[data-page-loading]');
        if (loading) loading.hidden = true;
    }

    function show() {
        const loading = document.querySelector('[data-page-loading]');
        if (!loading) return;
        loading.hidden = false;
        const token = {};
        active = token;
        browser.clearTimeout(timeout);
        timeout = browser.setTimeout(() => {
            if (active === token) reset();
        }, 30000);
    }

    function isPageDestination(destination) {
        const current = new URL(browser.location.href);
        return ['http:', 'https:'].includes(destination.protocol)
            && destination.origin === current.origin
            && !(/\/(?:download|export)(?:\/|$)/.test(destination.pathname))
            && !(destination.pathname === current.pathname && destination.search === current.search && destination.href.includes('#'));
    }

    function startAfterEvent(event) {
        const previousCompletion = completion;
        queueMicrotask(() => {
            if (!event.defaultPrevented && completion === previousCompletion) show();
        });
    }

    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const link = event.target?.closest?.('a[href]');
        if (!link || link.hasAttribute('download') || link.hasAttribute('data-page-loading-ignore')) return;
        const target = link.getAttribute('target')?.trim().toLowerCase();
        if (target && target !== '_self') return;
        const href = link.getAttribute('href');
        if (!href?.trim()) return;
        let destination;
        try {
            destination = new URL(href, document.baseURI);
        } catch {
            return;
        }
        if (isPageDestination(destination)) startAfterEvent(event);
    });

    document.addEventListener('livewire:navigate', (event) => {
        if (event.detail?.url && isPageDestination(new URL(event.detail.url, document.baseURI))) startAfterEvent(event);
    });
    document.addEventListener('livewire:navigated', reset);
    browser.addEventListener('pageshow', reset);
    browser.addEventListener('pagehide', reset);
    browser.addEventListener('offline', reset);
    reset();
}
