import { test } from 'node:test';
import assert from 'node:assert/strict';
import { notificationFromResponse, isFormMessage, installFormNotifications } from '../../resources/js/form-notifications.js';

test('validation errors win over success messages and duplicate errors are combined', () => {
    assert.deepEqual(notificationFromResponse({ snapshot: { memo: { errors: [] } } }, {
        errors: ['Nama wajib diisi.', 'Nama wajib diisi.'], successes: ['Berhasil.'],
    }), { type: 'error', messages: ['Nama wajib diisi.'] });
});

test('snapshot validation errors are used when the form does not render its errors', () => {
    assert.deepEqual(notificationFromResponse({ snapshot: JSON.stringify({ memo: { errors: { title: ['Title wajib diisi.'] } } }) }),
        { type: 'error', messages: ['Title wajib diisi.'] });
});

test('successful forms use their existing flash message or a generic completion message', () => {
    assert.deepEqual(notificationFromResponse({}, { successes: ['Data berhasil disimpan.'] }),
        { type: 'success', messages: ['Data berhasil disimpan.'] });
    assert.deepEqual(notificationFromResponse({}), { type: 'success', messages: ['Form berhasil diproses.'] });
});

test('only submit methods and business actions notify, excluding filters updated automatically and uploads', () => {
    const component = { el: { querySelectorAll: () => [{ getAttribute: () => "reassign('bunker', 1)" }] } };
    for (const name of ['reassign', 'ready', 'changeStatus']) {
        assert.equal(isFormMessage({ component, actions: new Set([{ name }]) }), true);
    }
    for (const name of ['create', 'edit', '$refresh', '_finishUpload', 'selectTab']) {
        assert.equal(isFormMessage({ component, actions: new Set([{ name }]) }), false);
    }
});

test('a Livewire component rooted at its own form is recognized as a submission', () => {
    const form = {
        matches: (selector) => selector === 'form',
        querySelectorAll: () => [],
        getAttributeNames: () => ['wire:submit.prevent'],
        getAttribute: (name) => name === 'wire:submit.prevent' ? 'save' : null,
    };
    const message = { component: { el: form }, actions: [{ name: 'save' }] };
    assert.equal(isFormMessage(message), true);
});

test('parent updates are not mistaken for a nested component form submission', () => {
    const child = {};
    const form = { getAttribute: () => 'save', closest: () => child };
    const parent = { querySelectorAll: () => [form] };
    assert.equal(isFormMessage({ component: { el: parent }, actions: [{ name: 'save' }] }), false);
});

test('service submissions are recognized from their original form after the component no longer contains it', () => {
    const form = {
        matches: (selector) => selector === 'form',
        getAttribute: (name) => name === 'wire:submit' ? 'submit' : null,
    };
    const component = { el: { querySelectorAll: () => [] } };

    assert.equal(isFormMessage({ component, actions: [{ name: 'submit', origin: { el: form } }] }), true);
    assert.equal(isFormMessage({ component, actions: [{ name: 'addCrew', origin: { el: form } }] }), false);
});

function notificationHarness() {
    const events = new Map();
    const storage = new Map();
    const children = [];
    const title = { textContent: '' };
    const icon = {};
    const button = { addEventListener: () => {}, focus: () => {} };
    const list = { replaceChildren: () => children.splice(0), append: (child) => children.push(child) };
    let popup;
    const newPopup = () => ({
        dataset: { initialNotification: 'null' }, open: false, animations: 0,
        querySelector: (selector) => ({ '#form-popup-title': title, '[data-popup-icon]': icon,
            '#form-popup-messages': list, '[data-popup-close]': button })[selector],
        showModal() { this.open = true; }, close() { this.open = false; },
        animate() { this.animations++; return { cancel: () => {} }; },
    });
    popup = newPopup();
    let interceptor;
    const browser = {
        Livewire: { interceptMessage: (callback) => { interceptor = callback; } },
        sessionStorage: { getItem: (key) => storage.get(key) ?? null,
            setItem: (key, value) => storage.set(key, value), removeItem: (key) => storage.delete(key) },
        matchMedia: () => ({ matches: false }),
        DOMParser: class { parseFromString() { return { querySelectorAll: () => [] }; } },
    };
    const document = { querySelector: () => popup, createElement: () => ({ textContent: '' }),
        addEventListener: (name, callback) => events.set(name, callback) };
    installFormNotifications(document, browser);
    const callbacks = {};
    const message = { component: { el: { querySelectorAll: () => [{ getAttribute: () => 'save' }] } }, actions: [{ name: 'save' }] };
    interceptor({ message, onSuccess: (callback) => callbacks.success = callback,
        onError: (callback) => callbacks.error = callback, onFailure: (callback) => callbacks.failure = callback });
    return { callbacks, children, title, storage, browser, events, get popup() { return popup; },
        navigate() { popup = newPopup(); events.get('livewire:navigated')(); } };
}

test('Livewire validation failure opens an animated error popup after render', () => {
    const harness = notificationHarness();
    let afterRender;
    harness.callbacks.success({ payload: { snapshot: { memo: { errors: { name: ['Nama wajib diisi.'] } } }, effects: {} },
        onRender: (callback) => afterRender = callback });
    assert.equal(harness.popup.open, false);
    afterRender();
    assert.equal(harness.popup.open, true);
    assert.equal(harness.title.textContent, 'Form belum berhasil diproses');
    assert.equal(harness.children[0].textContent, 'Nama wajib diisi.');
    assert.equal(harness.popup.animations, 1);
});

test('redirect success is shown once on the destination page', () => {
    const harness = notificationHarness();
    harness.callbacks.success({ payload: { effects: { redirect: '/dashboard/vessel' } } });
    assert.equal(harness.popup.open, false);
    assert.equal(harness.storage.size, 1);
    harness.navigate();
    assert.equal(harness.title.textContent, 'Berhasil!');
    assert.equal(harness.popup.open, true);
    assert.equal(harness.storage.size, 0);
    harness.popup.close();
    harness.events.get('livewire:navigated')();
    assert.equal(harness.popup.open, false);
});

test('frontend errors and failed requests notify without reporting success or exposing server bodies', () => {
    const harness = notificationHarness();
    harness.events.get('form-notification')({ detail: { type: 'error', messages: ['File terlalu besar.'] } });
    assert.equal(harness.children[0].textContent, 'File terlalu besar.');
    let prevented = false;
    harness.callbacks.error({ response: { status: 403 }, body: 'secret server exception', preventDefault: () => prevented = true });
    assert.equal(prevented, true);
    assert.equal(harness.children[0].textContent, 'Anda tidak memiliki izin untuk menjalankan aksi ini.');
    harness.callbacks.failure();
    assert.match(harness.children[0].textContent, /Koneksi terputus/);
    harness.events.get('livewire-upload-error')();
    assert.match(harness.children[0].textContent, /Unggahan file gagal/);
});

test('reduced motion disables popup animation', () => {
    const harness = notificationHarness();
    harness.browser.matchMedia = () => ({ matches: true });
    harness.events.get('form-notification')({ detail: { type: 'success', messages: ['Berhasil.'] } });
    assert.equal(harness.popup.animations, 0);
});
