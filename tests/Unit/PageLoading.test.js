import { test } from 'node:test';
import assert from 'node:assert/strict';
import { installPageLoading } from '../../resources/js/page-loading.js';

function harness() {
    const events = new Map();
    const browserEvents = new Map();
    const timers = new Map();
    const scheduled = [];
    const loading = { hidden: false };
    const current = 'https://fla.test/dashboard/services/sijil?status=active';
    const document = {
        baseURI: current,
        querySelector: () => loading,
        addEventListener: (name, callback) => events.set(name, callback),
    };
    const browser = {
        location: { href: current },
        addEventListener: (name, callback) => browserEvents.set(name, callback),
        setTimeout(callback, duration) {
            const timer = { callback, duration };
            timers.set(timer, timer);
            scheduled.push(timer);
            return timer;
        },
        clearTimeout: (timer) => timers.delete(timer),
    };
    installPageLoading(document, browser);

    function click(attributes = { href: '/dashboard/vessel' }, overrides = {}) {
        const link = {
            hasAttribute: (name) => Object.hasOwn(attributes, name),
            getAttribute: (name) => attributes[name] ?? null,
        };
        const event = {
            button: 0, defaultPrevented: false,
            target: { closest: (selector) => selector === 'a[href]' ? link : null },
            preventDefault() { this.defaultPrevented = true; },
            ...overrides,
        };
        events.get('click')(event);
        return event;
    }

    function navigate(path = '/dashboard/vessel', context = {}) {
        const event = {
            detail: { url: new URL(path, current), ...context }, defaultPrevented: false,
            preventDefault() { this.defaultPrevented = true; },
        };
        events.get('livewire:navigate')(event);
        return event;
    }

    return { loading, events, browserEvents, timers, scheduled, click, navigate };
}

test('ordinary sidebar and internal page links display loading without preventing navigation', async () => {
    const h = harness();
    assert.equal(h.loading.hidden, true);

    const event = h.click();
    await Promise.resolve();

    assert.equal(h.loading.hidden, false);
    assert.equal(event.defaultPrevented, false);
    h.browserEvents.get('pageshow')();
    assert.equal(h.loading.hidden, true);
    assert.equal(h.timers.size, 0);
});

test('Livewire navigation shows loading through the page swap and closes when the destination is ready', async () => {
    const h = harness();

    h.navigate();
    await Promise.resolve();

    assert.equal(h.loading.hidden, false);
    h.events.get('livewire:navigating')?.();
    assert.equal(h.loading.hidden, false);
    h.events.get('livewire:navigated')();
    assert.equal(h.loading.hidden, true);
});

test('browser back and forward navigation also display loading', async () => {
    const h = harness();

    h.navigate('/dashboard/clients', { history: true, cached: true });
    await Promise.resolve();

    assert.equal(h.loading.hidden, false);
    h.events.get('livewire:navigated')();
    assert.equal(h.loading.hidden, true);
});

test('cached navigation that completes immediately never leaves a queued loading indicator behind', async () => {
    const h = harness();

    h.navigate('/dashboard/clients', { history: true, cached: true });
    h.events.get('livewire:navigated')();
    await Promise.resolve();

    assert.equal(h.loading.hidden, true);
    assert.equal(h.timers.size, 0);
});

test('cancelled native and Livewire navigations do not display loading', async () => {
    const h = harness();

    h.click().preventDefault();
    h.navigate().preventDefault();
    await Promise.resolve();

    assert.equal(h.loading.hidden, true);
});

for (const [name, attributes, overrides] of [
    ['downloads marked by the shared button', { href: '/private/file', 'data-page-loading-ignore': '' }, {}],
    ['native downloads', { href: '/file.pdf', download: '' }, {}],
    ['document download routes', { href: '/sijil/1/documents/2/download' }, {}],
    ['report export routes', { href: '/dashboard/reports/export/services' }, {}],
    ['new tabs', { href: '/dashboard/vessel', target: '_blank' }, {}],
    ['other frames', { href: '/dashboard/vessel', target: '_parent' }, {}],
    ['external websites', { href: 'https://other.test/dashboard' }, {}],
    ['email links', { href: 'mailto:staff@example.test' }, {}],
    ['script links', { href: 'javascript:void(0)' }, {}],
    ['same page anchors', { href: '#main-content' }, {}],
    ['empty fragments', { href: '#' }, {}],
    ['empty links', { href: '' }, {}],
    ['invalid URLs', { href: 'https://[' }, {}],
    ['prevented clicks', { href: '/dashboard/vessel' }, { defaultPrevented: true }],
    ['control clicks', { href: '/dashboard/vessel' }, { ctrlKey: true }],
    ['command clicks', { href: '/dashboard/vessel' }, { metaKey: true }],
    ['shift clicks', { href: '/dashboard/vessel' }, { shiftKey: true }],
    ['alt clicks', { href: '/dashboard/vessel' }, { altKey: true }],
    ['middle clicks', { href: '/dashboard/vessel' }, { button: 1 }],
    ['ordinary buttons', {}, { target: { closest: () => null } }],
]) {
    test(`${name} do not trigger page loading`, async () => {
        const h = harness();

        h.click(attributes, overrides);
        await Promise.resolve();

        assert.equal(h.loading.hidden, true);
        assert.equal(h.timers.size, 0);
    });
}

test('changing query parameters or opening another page with a fragment displays loading', async () => {
    for (const href of ['?status=completed', '/dashboard/vessel#documents', '/dashboard/services/sijil']) {
        const h = harness();

        h.click({ href, target: ' _SELF ' });
        await Promise.resolve();

        assert.equal(h.loading.hidden, false, href);
    }
});

test('page cache restoration, leaving the page and going offline reset loading', async () => {
    for (const event of ['pageshow', 'pagehide', 'offline']) {
        const h = harness();
        h.navigate();
        await Promise.resolve();

        h.browserEvents.get(event)();

        assert.equal(h.loading.hidden, true, event);
        assert.equal(h.timers.size, 0, event);
    }
});

test('a navigation that never completes cannot leave loading visible indefinitely', async () => {
    const h = harness();
    h.navigate();
    await Promise.resolve();

    h.scheduled[0].callback();

    assert.equal(h.loading.hidden, true);
});

test('an old navigation timeout does not hide the loading of a newer navigation', async () => {
    const h = harness();
    h.navigate();
    await Promise.resolve();
    const oldTimer = h.scheduled[0];
    h.navigate('/dashboard/clients');
    await Promise.resolve();

    oldTimer.callback();

    assert.equal(h.loading.hidden, false);
    h.events.get('livewire:navigated')();
    assert.equal(h.loading.hidden, true);
});
