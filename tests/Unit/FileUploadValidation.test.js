import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileValidationError, imageValidationError, installFileUploadValidation } from '../../resources/js/file-upload-validation.js';

const documents = { accept: '.pdf,.jpg,.jpeg,.png,.webp,.gif,.doc,.docx', maxKb: 2048 };
const photos = { accept: 'image/jpeg,image/png,image/webp', maxKb: 2048 };

test('allows images up to 4 MB while retaining the document limit', () => {
    assert.equal(fileValidationError({ name: 'photo.jpg', type: 'image/jpeg', size: 4 * 1024 * 1024 }, documents), null);
    assert.match(fileValidationError({ name: 'photo.jpg', type: 'image/jpeg', size: 4 * 1024 * 1024 + 1 }, documents), /4 MB/);
    assert.match(fileValidationError({ name: 'document.pdf', type: 'application/pdf', size: 2 * 1024 * 1024 + 1 }, documents), /2 MB/);
});

for (const [name, type] of [['document.PDF', 'application/pdf'], ['photo.jpg', 'image/jpeg'], ['photo.jpeg', 'image/jpeg'], ['photo.png', 'image/png'], ['photo.webp', 'image/webp'], ['photo.gif', 'image/gif'], ['document.doc', 'application/msword'], ['document.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']]) {
    test(`accepts ${name} at the maximum document size`, () => {
        assert.equal(fileValidationError({ name, type, size: 2048 * 1024 }, documents), null);
    });
}

for (const [name, type] of [['shell.php', 'application/pdf'], ['document.pdf.exe', 'application/pdf'], ['vector.svg', 'image/svg+xml']]) {
    test(`rejects unsupported document ${name}`, () => {
        assert.match(fileValidationError({ name, type, size: 10 }, documents), /Jenis file tidak diizinkan/);
    });
}

test('rejects a MIME type that disagrees with the extension', () => {
    assert.match(fileValidationError({ name: 'photo.png', type: 'text/plain', size: 10 }, documents), /Tipe file tidak sesuai/);
});

test('permits an unknown browser MIME for an allowed extension', () => {
    assert.equal(fileValidationError({ name: 'document.pdf', type: '', size: 10 }, documents), null);
});

test('rejects empty files and files one byte above the size limit', () => {
    assert.match(fileValidationError({ name: 'document.pdf', type: 'application/pdf', size: 0 }, documents), /File kosong/);
    assert.match(fileValidationError({ name: 'document.pdf', type: 'application/pdf', size: 2048 * 1024 + 1 }, documents), /maksimal 2 MB/);
});

test('uses the per-input size limit', () => {
    assert.match(fileValidationError({ name: 'document.pdf', type: 'application/pdf', size: 1024 * 1024 + 1 }, { ...documents, maxKb: 1024 }), /maksimal 1 MB/);
});

test('profile pictures allow WebP and reject PDF', () => {
    assert.equal(fileValidationError({ name: 'photo.webp', type: 'image/webp', size: 10 }, photos), null);
    assert.match(fileValidationError({ name: 'document.pdf', type: 'application/pdf', size: 10 }, photos), /Jenis file tidak diizinkan/);
});

test('profile pictures accept the dimension boundary and reject excessive width or height', async () => {
    assert.equal(await imageValidationError({}, 4096, async () => ({ width: 4096, height: 4096 })), null);
    for (const dimensions of [{ width: 4097, height: 1 }, { width: 1, height: 4097 }]) {
        assert.match(await imageValidationError({}, 4096, async () => dimensions), /maksimal 4096 × 4096/);
    }
});

test('rejects an image that cannot be decoded', async () => {
    assert.match(await imageValidationError({}, 4096, async () => { throw new Error('Invalid'); }), /Foto tidak dapat dibaca/);
});

function uploadHarness(wire = null) {
    const handlers = new Map();
    const dispatched = [];
    const attributes = new Map();
    attributes.set('aria-describedby', 'upload-help');
    if (wire) attributes.set('wire:model', 'documents.proof');
    const form = { querySelector: () => null, querySelectorAll: () => [input] };
    const input = {
        accept: documents.accept, dataset: { maxFileKb: '2048' }, files: [], form,
        isConnected: true, value: '', validity: '', focused: false,
        matches: () => true,
        getAttributeNames: () => [...attributes.keys()],
        getAttribute: (name) => attributes.get(name),
        setAttribute: (name, value) => attributes.set(name, value),
        closest: () => wire ? { getAttribute: () => 'test-component' } : null,
        setCustomValidity(message) { this.validity = message; },
        focus() { this.focused = true; },
        dispatchEvent(event) {
            if (event.type !== 'change') return true;
            Object.defineProperty(event, 'target', { value: this });
            handlers.get('change')(event);
        },
    };
    Object.defineProperty(input, 'value', {
        set(value) { if (value === '') this.files = []; },
        get() { return this.files.length ? 'selected' : ''; },
    });
    installFileUploadValidation({ addEventListener: (type, handler, capture) => {
        assert.equal(capture, true);
        handlers.set(type, (event) => {
            const pending = handler(event);
            if (type === 'change' && !event.stopped && event.target.isConnected) {
                dispatched.push([...event.target.files]);
            }

            return pending;
        });
    } }, () => wire ? { find: () => wire } : null);
    const event = (target) => ({ target, stopped: false, prevented: false,
        stopImmediatePropagation() { this.stopped = true; },
        preventDefault() { this.prevented = true; },
    });
    return { input, form, dispatched, handlers, event };
}

for (const type of ['drop', 'paste']) {
    test(`${type} sends a file through the normal input validation`, () => {
        const originalDataTransfer = globalThis.DataTransfer;
        globalThis.DataTransfer = class {
            files = [];
            items = { add: (file) => this.files.push(file) };
        };
        try {
            const { input, dispatched, handlers } = uploadHarness();
            const zone = { querySelector: () => input };
            const file = { name: 'proof.pdf', type: 'application/pdf', size: 10 };
            const event = {
                target: { closest: () => zone },
                [type === 'drop' ? 'dataTransfer' : 'clipboardData']: { files: [file] },
                prevented: false,
                preventDefault() { this.prevented = true; },
            };

            handlers.get(type)(event);

            assert.equal(event.prevented, true);
            assert.deepEqual(dispatched, [[file]]);
        } finally {
            globalThis.DataTransfer = originalDataTransfer;
        }
    });
}

test('pasting text into a file drop zone does not intercept the paste', () => {
    const { handlers } = uploadHarness();
    const event = { target: { closest: () => ({}) }, clipboardData: { files: [], items: [] }, preventDefault() { throw new Error('Text paste was intercepted'); } };

    handlers.get('paste')(event);
});

test('a dropped invalid file is rejected by the existing upload guard', () => {
    const originalDataTransfer = globalThis.DataTransfer;
    globalThis.DataTransfer = class {
        files = [];
        items = { add: (file) => this.files.push(file) };
    };
    try {
        const { input, handlers, dispatched } = uploadHarness();
        const zone = { querySelector: () => input };
        handlers.get('drop')({
            target: { closest: () => zone },
            dataTransfer: { files: [{ name: 'bad.php', type: 'application/pdf', size: 10 }] },
            preventDefault() {},
        });

        assert.match(input.validity, /bad.php/);
        assert.ok(dispatched.every((files) => files.length === 0));
    } finally {
        globalThis.DataTransfer = originalDataTransfer;
    }
});

test('invalid selection is cleared before upload and blocks only its own form', async () => {
    const { input, form, dispatched, handlers, event } = uploadHarness();
    input.files = [{ name: 'malware.php', type: 'application/pdf', size: 10 }];
    const change = event(input);
    await handlers.get('change')(change);
    assert.equal(change.stopped, true);
    assert.deepEqual(input.files, []);
    assert.deepEqual(dispatched, [[]]);
    assert.match(input.validity, /malware.php/);
    delete input.dataset.fileError;
    const submit = event(form);
    handlers.get('submit')(submit);
    assert.equal(submit.prevented, true);
    const otherSubmit = event({ querySelectorAll: () => [] });
    handlers.get('submit')(otherSubmit);
    assert.equal(otherSubmit.prevented, false);
});

test('valid replacement clears the error and proceeds to upload and submission', async () => {
    const { input, form, dispatched, handlers, event } = uploadHarness();
    input.files = [{ name: 'large.pdf', type: 'application/pdf', size: 3000000 }];
    await handlers.get('change')(event(input));
    const accepted = { name: 'valid.pdf', type: 'application/pdf', size: 10 };
    input.files = [accepted];
    await handlers.get('change')(event(input));
    assert.equal(input.validity, '');
    assert.deepEqual(dispatched.at(-1), [accepted]);
    const submit = event(form);
    handlers.get('submit')(submit);
    assert.equal(submit.prevented, false);
});

test('reset clears upload errors and a disconnected input cannot start an upload', async () => {
    const { input, form, dispatched, handlers, event } = uploadHarness();
    input.files = [{ name: 'bad.php', type: '', size: 10 }];
    await handlers.get('change')(event(input));
    handlers.get('reset')(event(form));
    assert.equal(input.validity, '');
    input.isConnected = false;
    input.files = [{ name: 'valid.pdf', type: 'application/pdf', size: 10 }];
    await handlers.get('change')(event(input));
    assert.deepEqual(dispatched, [[]]);
});

test('rejected Livewire file cancels its upload and clears the previous server selection without submitting', async () => {
    const calls = [];
    const { input, handlers, event } = uploadHarness({
        $cancelUpload: (...args) => calls.push(['cancel', ...args]),
        $set: (...args) => calls.push(['set', ...args]),
    });
    input.files = [{ name: 'bad.pdf', type: 'text/plain', size: 10 }];
    await handlers.get('change')(event(input));
    assert.deepEqual(calls, [['cancel', 'documents.proof'], ['set', 'documents.proof', null, false]]);
    assert.match(input.getAttribute('aria-describedby'), /^upload-help frontend-file-error-/);
    input.files = [{ name: 'good.pdf', type: 'application/pdf', size: 10 }];
    await handlers.get('change')(event(input));
    assert.equal(input.getAttribute('aria-describedby'), 'upload-help');
});

test('image decoding blocks submission and stale image checks cannot upload an older selection', async (context) => {
    const images = [];
    const originalImage = globalThis.Image;
    context.mock.method(URL, 'createObjectURL', () => 'blob:test');
    context.mock.method(URL, 'revokeObjectURL', () => {});
    globalThis.Image = class {
        constructor() { images.push(this); }
    };
    try {
        const { input, form, dispatched, handlers, event } = uploadHarness();
        input.dataset.maxImageDimension = '4096';
        input.files = [{ name: 'first.png', type: 'image/png', size: 10 }];
        const first = handlers.get('change')(event(input));
        const submit = event(form);
        handlers.get('submit')(submit);
        assert.equal(submit.prevented, true);
        assert.deepEqual(dispatched, []);
        const replacement = { name: 'replacement.png', type: 'image/png', size: 10 };
        input.files = [replacement];
        const second = handlers.get('change')(event(input));
        Object.assign(images[0], { naturalWidth: 4097, naturalHeight: 10 });
        images[0].onload();
        await first;
        assert.deepEqual(dispatched, []);
        assert.equal(input.dataset.fileError, undefined);
        Object.assign(images[1], { naturalWidth: 100, naturalHeight: 100 });
        images[1].onload();
        await second;
        assert.deepEqual(dispatched, [[replacement]]);
        assert.equal(input.dataset.fileValidating, undefined);
    } finally {
        if (originalImage === undefined) delete globalThis.Image;
        else globalThis.Image = originalImage;
    }
});

for (const [name, type] of [
    ['scan.pdf', 'application/octet-stream'],
    ['scan.pdf', 'binary/octet-stream'],
    ['scan.pdf', 'application/download'],
    ['scan.PDF', 'application/x-pdf'],
    ['photo.jpg', 'image/jpg'],
    ['photo.jpeg', 'image/pjpeg'],
    ['photo.png', 'image/x-png'],
    ['scan.pdf', 'APPLICATION/PDF; charset=binary'],
]) {
    test(`accepts supported ${name} reported by the browser as ${type}`, () => {
        assert.equal(fileValidationError({ name, type, size: 100 }, documents), null);
    });
}

test('generic MIME does not allow unsupported extensions or oversized files', () => {
    assert.match(fileValidationError({ name: 'script.php', type: 'application/octet-stream', size: 100 }, documents), /Jenis file tidak diizinkan/);
    assert.match(fileValidationError({ name: 'scan.pdf', type: 'application/octet-stream', size: 2048 * 1024 + 1 }, documents), /maksimal 2 MB/);
});

test('image wildcard inputs accept images while rejecting documents', () => {
    const settings = { accept: 'image/*', maxKb: 2048 };
    assert.equal(fileValidationError({ name: 'photo.png', type: 'image/png', size: 100 }, settings), null);
    assert.match(fileValidationError({ name: 'scan.pdf', type: 'application/pdf', size: 100 }, settings), /Jenis file tidak diizinkan/);
});

test('valid documents reach the native Livewire change handler once without clearing the selection', async () => {
    const calls = [];
    const { input, handlers, event, dispatched } = uploadHarness({
        $cancelUpload: (...args) => calls.push(['cancel', ...args]),
        $set: (...args) => calls.push(['set', ...args]),
    });
    const file = { name: 'scan.pdf', type: 'application/octet-stream', size: 100 };
    input.files = [file];
    const change = event(input);

    await handlers.get('change')(change);

    assert.equal(change.stopped, false);
    assert.deepEqual(dispatched, [[file]]);
    assert.deepEqual(input.files, [file]);
    assert.deepEqual(calls, []);
});

test('saving is blocked while Livewire is still uploading and resumes after completion', async () => {
    const { input, form, handlers, event } = uploadHarness();
    input.files = [{ name: 'scan.pdf', type: 'application/pdf', size: 100 }];
    await handlers.get('change')(event(input));
    handlers.get('livewire-upload-start')(event(input));
    const pending = event(form);

    handlers.get('submit')(pending);

    assert.equal(pending.prevented, true);
    assert.equal(input.dataset.fileUploading, 'true');
    handlers.get('livewire-upload-finish')(event(input));
    const finished = event(form);
    handlers.get('submit')(finished);
    assert.equal(finished.prevented, false);
    assert.equal(input.dataset.fileUploading, undefined);
});

test('failed uploads cannot silently submit an empty document and can be retried', async () => {
    const { input, form, handlers, event } = uploadHarness();
    input.files = [{ name: 'scan.pdf', type: 'application/pdf', size: 100 }];
    await handlers.get('change')(event(input));
    handlers.get('livewire-upload-start')(event(input));
    handlers.get('livewire-upload-error')(event(input));
    const failed = event(form);
    handlers.get('submit')(failed);

    assert.equal(failed.prevented, true);
    assert.match(input.validity, /Unggahan file gagal/);
    await handlers.get('change')(event(input));
    handlers.get('livewire-upload-start')(event(input));
    handlers.get('livewire-upload-finish')(event(input));
    const retried = event(form);
    handlers.get('submit')(retried);
    assert.equal(retried.prevented, false);
    assert.equal(input.validity, '');
});

test('cancelling a pending upload releases its submission guard', () => {
    const { input, form, handlers, event } = uploadHarness();
    handlers.get('livewire-upload-start')(event(input));
    handlers.get('livewire-upload-cancel')(event(input));
    const submit = event(form);

    handlers.get('submit')(submit);

    assert.equal(submit.prevented, false);
    assert.equal(input.dataset.fileUploading, undefined);
});

test('an upload in another form does not block submission', () => {
    const { input, handlers, event } = uploadHarness();
    handlers.get('livewire-upload-start')(event(input));
    const unrelated = event({ querySelectorAll: () => [] });

    handlers.get('submit')(unrelated);

    assert.equal(unrelated.prevented, false);
});
