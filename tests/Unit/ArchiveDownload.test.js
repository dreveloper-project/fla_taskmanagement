import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readArchiveStream, installArchiveDownloads } from '../../resources/js/archive-download.js';

const encoder = new TextEncoder();
const line = (event) => encoder.encode(`${JSON.stringify(event)}\n`);

function stream(chunks) {
    return new Response(new ReadableStream({
        start(controller) {
            for (const chunk of chunks) controller.enqueue(chunk);
            controller.close();
        },
    }));
}

test('split progress records and binary ZIP bytes remain intact across arbitrary chunk boundaries', async () => {
    const data = new Uint8Array([80, 75, 0, 255, 10, 128, 13, 0]);
    const parts = [line({ type: 'progress', percent: 25, message: 'Mengompres…' }), line({ type: 'file', filename: 'test.zip', size: data.length }), data];
    const bytes = new Uint8Array(parts.reduce((size, part) => size + part.length, 0));
    let offset = 0;
    for (const part of parts) { bytes.set(part, offset); offset += part.length; }
    const chunks = Array.from(bytes, (byte) => new Uint8Array([byte]));
    const progress = [];
    const transfer = [];

    const result = await readArchiveStream(stream(chunks), (...event) => progress.push(event), (percent) => transfer.push(percent));

    assert.deepEqual(progress, [[25, 'Mengompres…']]);
    assert.equal(result.filename, 'test.zip');
    assert.deepEqual(new Uint8Array(await result.blob.arrayBuffer()), data);
    assert.equal(transfer.at(-1), 100);
});

test('a preparation error is shown instead of producing a ZIP', async () => {
    await assert.rejects(readArchiveStream(stream([line({ type: 'error', message: 'Berkas tidak ditemukan.' })]), () => {}, () => {}), /Berkas tidak ditemukan/);
});

test('incomplete preparation or file transfer never reaches 100 percent', async () => {
    for (const chunks of [[], [line({ type: 'file', filename: 'test.zip', size: 10 }), new Uint8Array([80, 75])]]) {
        const progress = [];
        await assert.rejects(readArchiveStream(stream(chunks), () => {}, (value) => progress.push(value)), /terputus/);
        assert.equal(progress.includes(100), false);
    }
});

test('oversized file payloads are rejected', async () => {
    await assert.rejects(readArchiveStream(stream([line({ type: 'file', filename: 'test.zip', size: 1 }), new Uint8Array([80, 75])]), () => {}, () => {}), /Ukuran unduhan/);
});

test('aborting before receiving the ZIP prevents completion', async () => {
    const controller = new AbortController();
    controller.abort();

    await assert.rejects(readArchiveStream(stream([]), () => {}, () => {}, controller.signal), { name: 'AbortError' });
});

function harness(fetch) {
    const events = new Map();
    const browserEvents = new Map();
    const controls = new Map();
    const handlers = new Map();
    for (const name of ['title', 'description', 'percent', 'phase', 'bar', 'meter', 'status', 'cancel']) {
        controls.set(`[data-archive-${name}]`, { textContent: '', hidden: false, setAttribute() {}, addEventListener: (name, callback) => handlers.set(name, callback) });
    }
    const popup = {
        open: false, setAttribute() {}, querySelector: (selector) => controls.get(selector),
        showModal() { this.open = true; }, close() { this.open = false; },
        addEventListener: (name, callback) => handlers.set(name, callback),
    };
    const saved = [];
    const document = {
        querySelector: (selector) => selector === '[data-archive-progress]' ? popup : { content: 'csrf-test' },
        addEventListener: (name, callback) => events.set(name, callback),
        body: { append() {} },
        createElement: () => ({ click() { saved.push(this.download); }, remove() {} }),
    };
    const browser = { fetch, ReadableStream, URL: { createObjectURL: () => 'blob:test', revokeObjectURL() {} }, setTimeout() {}, addEventListener: (name, callback) => browserEvents.set(name, callback) };
    installArchiveDownloads(document, browser);
    const click = () => events.get('click')({ button: 0, preventDefault() {}, target: { closest: () => ({ href: '/archive/download' }) } });
    return { click, popup, controls, handlers, events, browserEvents, saved };
}

test('cancel aborts the request and does not save a file even if the server finishes afterward', async () => {
    let signal;
    let resolve;
    const h = harness((url, options) => { signal = options.signal; return new Promise((done) => { resolve = done; }); });
    h.click();
    assert.equal(h.popup.open, true);
    h.handlers.get('click')();
    assert.equal(signal.aborted, true);
    assert.equal(h.controls.get('[data-archive-title]').textContent, 'Unduhan dibatalkan');
    resolve(new Response('late result'));
    await new Promise((done) => setImmediate(done));

    assert.deepEqual(h.saved, []);
    assert.equal(h.controls.get('[data-archive-title]').textContent, 'Unduhan dibatalkan');
});

test('successful preparation downloads one file and shows completion', async () => {
    let options;
    const h = harness(async (url, request) => {
        options = request;
        const response = stream([line({ type: 'file', filename: 'SPB.zip', size: 2 }), new Uint8Array([80, 75])]);
        response.headers.set('Content-Type', 'application/vnd.fla.archive-stream');
        return response;
    });
    h.click();
    await new Promise((done) => setImmediate(done));

    assert.equal(options.method, 'POST');
    assert.equal(options.headers['X-CSRF-TOKEN'], 'csrf-test');
    assert.deepEqual(h.saved, ['SPB.zip']);
    assert.equal(h.controls.get('[data-archive-percent]').textContent, '100%');
    assert.equal(h.controls.get('[data-archive-title]').textContent, 'ZIP siap');
});

test('navigation and network loss abort unfinished downloads', () => {
    for (const event of ['livewire:navigating', 'pagehide', 'offline']) {
        let signal;
        const h = harness((url, request) => { signal = request.signal; return new Promise(() => {}); });
        h.click();
        (h.events.get(event) ?? h.browserEvents.get(event))();

        assert.equal(signal.aborted, true);
        assert.deepEqual(h.saved, []);
        if (event === 'offline') assert.equal(h.controls.get('[data-archive-title]').textContent, 'Unduhan gagal');
        else assert.equal(h.popup.open, false);
    }
});

test('a second click does not start a second concurrent ZIP preparation', () => {
    let requests = 0;
    const h = harness(() => { requests++; return new Promise(() => {}); });
    h.click();
    h.click();

    assert.equal(requests, 1);
});
