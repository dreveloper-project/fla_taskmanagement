import { test } from 'node:test';
import assert from 'node:assert/strict';
import { installFormLoading } from '../../resources/js/form-loading.js';

function harness({ reducedMotion = false, livewireReady = true } = {}) {
    const events = new Map();
    const browserEvents = new Map();
    const popupEvents = new Map();
    const interceptors = [];
    const messageInterceptors = [];
    const popup = {
        open: false, animations: 0,
        addEventListener: (name, callback) => popupEvents.set(name, callback),
        showModal() { this.open = true; },
        close() { this.open = false; },
        animate() { this.animations++; },
    };
    const livewire = {
        interceptAction: (callback) => interceptors.push(callback),
        interceptMessage: (callback) => messageInterceptors.push(callback),
    };
    const browser = {
        Livewire: livewireReady ? livewire : undefined,
        matchMedia: () => ({ matches: reducedMotion }),
        addEventListener: (name, callback) => browserEvents.set(name, callback),
    };
    const document = {
        querySelector: (selector) => selector === '[data-form-loading]' ? popup : null,
        addEventListener: (name, callback) => events.set(name, callback),
    };
    installFormLoading(document, browser);

    function request(name = 'save', forms = [], origin = null) {
        const callbacks = {};
        const methods = ['Send', 'Finish', 'Error', 'Failure', 'Cancel', 'Skipped'];
        const hooks = Object.fromEntries(methods.map((method) => [
            `on${method}`, (callback) => callbacks[method] = callback,
        ]));
        const action = {
            component: { el: { querySelectorAll: () => forms === null ? [] : (forms.length ? forms : [{ getAttribute: () => 'save' }]) } },
            name, origin,
        };
        for (const intercept of interceptors) intercept({ action, ...hooks });
        const messageCallbacks = {};
        const message = { component: action.component, actions: new Set([action]) };
        for (const intercept of messageInterceptors) {
            intercept({ message, onSuccess: (callback) => messageCallbacks.Success = callback });
        }
        if (callbacks.Finish) {
            callbacks.Send ??= () => {};
            callbacks.Skipped ??= callbacks.Finish;
            callbacks.Respond = (effects = {}) => messageCallbacks.Success?.({ payload: { effects } });
        }
        return callbacks;
    }

    function submit(attributes = {}, overrides = {}) {
        const form = {
            isConnected: true, matches: () => true,
            getAttribute: (name) => attributes[name] ?? null,
        };
        const event = { target: form, defaultPrevented: false,
            preventDefault() { this.defaultPrevented = true; }, ...overrides };
        events.get('submit')(event);
        return event;
    }
    return { popup, events, browserEvents, popupEvents, browser, livewire, request, submit, interceptors };
}

test('form loading starts before the request is sent and closes after rendering, including validation responses', () => {
    const h = harness();
    const request = h.request();
    assert.equal(h.popup.open, true);
    request.Send();
    assert.equal(h.popup.open, true);
    assert.equal(h.popup.animations, 1);
    request.Finish();
    assert.equal(h.popup.open, false);
});

test('HTTP errors, network failures, cancellations and skipped requests dismiss loading', () => {
    for (const outcome of ['Error', 'Failure', 'Cancel', 'Skipped']) {
        const h = harness();
        const request = h.request();
        request.Send();
        request[outcome]();
        assert.equal(h.popup.open, false, outcome);
        request.Finish();
        assert.equal(h.popup.open, false, 'cleanup remains safe when called twice');
    }
});

test('saving a draft keeps loading visible after the response until the redirected page loads', () => {
    const h = harness();
    const request = h.request();
    request.Send();

    request.Respond({ redirect: '/dashboard/services/spb/1' });
    request.Finish();

    assert.equal(h.popup.open, true, 'the destination page is still loading after save completes');
    h.browserEvents.get('pageshow')();
    assert.equal(h.popup.open, false);

    const next = h.request();
    next.Finish();
    assert.equal(h.popup.open, false, 'the redirect state is cleared on the destination page');
});

test('a redirect keeps loading visible when another form finishes before the destination arrives', () => {
    const h = harness();
    const first = h.request();
    const second = h.request();
    first.Respond({ redirect: '/dashboard/services/bunker/1' });
    first.Finish();

    second.Respond();
    second.Finish();

    assert.equal(h.popup.open, true);
    h.events.get('livewire:navigating')();
    h.events.get('livewire:navigated')();
    assert.equal(h.popup.open, false);
});

test('Livewire redirects retain form loading until the page swap begins', async () => {
    const h = harness();
    const request = h.request();
    request.Respond({ redirect: '/dashboard/services/sijil/1', redirectUsingNavigate: true });
    h.events.get('livewire:navigate')({ defaultPrevented: false });
    request.Finish();

    await Promise.resolve();

    assert.equal(h.popup.open, true);
    h.events.get('livewire:navigating')();
    assert.equal(h.popup.open, false, 'page loading takes over as the destination is swapped in');
    h.events.get('livewire:navigated')();
    assert.equal(h.popup.open, false);
});

test('cancelling Livewire navigation releases form loading after a successful save', async () => {
    const h = harness();
    const request = h.request();
    request.Respond({ redirect: '/dashboard/services/sijil/1', redirectUsingNavigate: true });
    const event = { defaultPrevented: false };
    h.events.get('livewire:navigate')(event);
    event.defaultPrevented = true;
    request.Finish();

    await Promise.resolve();

    assert.equal(h.popup.open, false);
});

test('responses without a redirect release loading normally after rendering', () => {
    const h = harness();
    const request = h.request();
    request.Respond({ html: '<p>Nama kapal wajib diisi.</p>' });
    assert.equal(h.popup.open, true);

    request.Finish();

    assert.equal(h.popup.open, false);
});

test('overlapping submissions keep loading visible until all requests finish', () => {
    const h = harness();
    const first = h.request();
    const second = h.request();
    first.Send();
    second.Send();
    first.Finish();
    assert.equal(h.popup.open, true);
    second.Finish();
    assert.equal(h.popup.open, false);
    assert.equal(h.popup.animations, 1);
});

test('polling, reactive filters and temporary uploads do not block the screen', () => {
    const h = harness();
    for (const name of ['$refresh', '_finishUpload', 'selectTab']) {
        assert.deepEqual(h.request(name), {});
    }
    assert.equal(h.popup.open, false);
});

test('native forms show loading, preserve submission data and block repeat submits', async () => {
    const h = harness();
    const event = h.submit({ method: 'post' });
    await Promise.resolve();
    assert.equal(h.popup.open, true);
    assert.equal(event.defaultPrevented, false);
    h.events.get('submit')(event);
    assert.equal(event.defaultPrevented, true);
    h.browserEvents.get('pageshow')();
    assert.equal(h.popup.open, false);
});

test('prevented submissions and forms opening another tab do not display loading', async () => {
    const h = harness();
    h.submit({}, { defaultPrevented: true });
    const cancelledLater = h.submit();
    cancelledLater.preventDefault();
    h.submit({ target: '_blank' });
    h.submit({ method: 'dialog' });
    await Promise.resolve();
    assert.equal(h.popup.open, false);
});

test('navigation clears old requests and loading continues working on the next page', () => {
    const h = harness();
    const old = h.request();
    old.Send();
    h.events.get('livewire:navigating')();
    h.events.get('livewire:navigated')();
    assert.equal(h.popup.open, false);
    const next = h.request();
    next.Send();
    old.Finish();
    assert.equal(h.popup.open, true);
    next.Finish();
    assert.equal(h.popup.open, false);
});

test('late Livewire initialization registers only once and respects reduced motion', () => {
    const h = harness({ reducedMotion: true, livewireReady: false });
    h.browser.Livewire = h.livewire;
    h.events.get('livewire:init')();
    h.events.get('livewire:init')();
    assert.equal(h.interceptors.length, 1);
    h.request().Send();
    assert.equal(h.popup.open, true);
    assert.equal(h.popup.animations, 0);
    let prevented = false;
    h.popupEvents.get('cancel')({ preventDefault: () => prevented = true });
    assert.equal(prevented, true);
});

test('safety certificate submit shows loading before Livewire sends its buffered save request', async () => {
    const h = harness();
    const event = h.submit({ 'wire:submit': 'save' });
    event.preventDefault();
    await Promise.resolve();
    assert.equal(h.popup.open, false, 'the native event waits for Livewire to accept the action');

    const request = h.request('save', [event.target]);
    assert.equal(h.popup.open, true, 'loading starts while the save action is still buffered');
    request.Send();
    assert.equal(h.popup.open, true);
    assert.equal(h.popup.animations, 1, 'handoff from submit to request does not reopen the popup');
    request.Finish();
    assert.equal(h.popup.open, false);
});

test('failure closes loading started by submit and does not clear a later submission', async () => {
    const h = harness();
    const first = h.submit({ 'wire:submit': 'save' });
    first.preventDefault();
    await Promise.resolve();
    const request = h.request('save', [first.target]);
    request.Send();
    request.Failure();
    assert.equal(h.popup.open, false);

    const second = h.submit({ 'wire:submit': 'save' }, { target: first.target });
    second.preventDefault();
    await Promise.resolve();
    const next = h.request('save', [second.target]);
    request.Finish();
    assert.equal(h.popup.open, true);
    next.Send();
    next.Finish();
    assert.equal(h.popup.open, false);
});

test('cancel before sending also closes loading started by a Livewire submit', async () => {
    const h = harness();
    const event = h.submit({ 'wire:submit': 'save' });
    event.preventDefault();
    await Promise.resolve();
    const request = h.request('save', [event.target]);
    request.Cancel();
    assert.equal(h.popup.open, false);
    request.Send();
    assert.equal(h.popup.open, false);
});

test('sijil manual submission opens loading from its originating form even when it is absent from the component tree', async () => {
    const h = harness();
    const event = h.submit({ 'wire:submit': 'submit' });
    event.preventDefault();
    await Promise.resolve();

    const request = h.request('submit', null, { el: event.target, directive: { value: 'submit' } });

    assert.equal(h.popup.open, true);
    request.Finish();
    assert.equal(h.popup.open, false);
});

test('a rejected confirmation does not start loading when Livewire never creates the submit action', async () => {
    const h = harness();
    const event = h.submit({ 'wire:submit': 'cancel' });
    event.preventDefault();

    await Promise.resolve();

    assert.equal(h.popup.open, false);
});

test('all service submission methods show loading while crew additions and uploads stay interactive', () => {
    for (const name of ['save', 'submit', 'apply', 'recordReview', 'recordResult', 'recordInspection', 'scheduleInspection', 'issueCertificate', 'handover', 'applyCrewChanges', 'schedule', 'complete', 'finish', 'cancel', 'confirmArrival']) {
        const h = harness();
        const form = { matches: () => true, getAttribute: () => name };

        const request = h.request(name, [form], { el: form });

        assert.equal(h.popup.open, true, name);
        request.Finish();
        assert.equal(h.popup.open, false, name);
    }
    const h = harness();
    for (const name of ['addCrew', 'removeCrew', '_startUpload', '_finishUpload']) {
        assert.deepEqual(h.request(name), {});
    }
    assert.equal(h.popup.open, false);
});
