const mimeTypes = {
    pdf: 'application/pdf',
    jpg: 'image/jpeg',
    jpeg: 'image/jpeg',
    png: 'image/png',
    webp: 'image/webp',
    gif: 'image/gif',
    doc: 'application/msword',
    docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
};

const mimeAliases = {
    'application/x-pdf': 'application/pdf',
    'image/jpg': 'image/jpeg',
    'image/pjpeg': 'image/jpeg',
    'image/x-png': 'image/png',
    'application/x-msword': 'application/msword',
};
const genericMimeTypes = new Set(['', 'application/octet-stream', 'binary/octet-stream', 'application/download', 'application/x-download']);

export function fileValidationError(file, { accept, maxKb }) {
    const extension = file.name.split('.').pop().toLowerCase();
    const mime = mimeTypes[extension];
    const allowed = (accept || '').split(',').map((type) => type.trim().toLowerCase()).filter(Boolean);

    const acceptsType = allowed.length === 0 || allowed.some((type) =>
        type === `.${extension}` || type === mime || mimeAliases[type] === mime || type === '*/*'
        || (type.endsWith('/*') && mime?.startsWith(type.slice(0, -1))));
    if (!mime || !acceptsType) {
        return 'Jenis file tidak diizinkan. Pilih ' + accept + '.';
    }
    const reportedType = (file.type || '').toLowerCase().split(';')[0].trim();
    if (!genericMimeTypes.has(reportedType) && (mimeAliases[reportedType] || reportedType) !== mime) {
        return 'Tipe file tidak sesuai dengan ekstensi namanya.';
    }
    if (file.size === 0) {
        return 'File kosong tidak dapat diunggah.';
    }
    const limit = mime.startsWith('image/') ? 4096 : maxKb;
    if (file.size > limit * 1024) {
        return `Ukuran file maksimal ${limit / 1024} MB.`;
    }

    return null;
}

export async function imageValidationError(file, maxDimension, readDimensions = imageDimensions) {
    try {
        const { width, height } = await readDimensions(file);
        return width > maxDimension || height > maxDimension
            ? `Dimensi foto maksimal ${maxDimension} × ${maxDimension} piksel.`
            : null;
    } catch {
        return 'Foto tidak dapat dibaca. Pilih file gambar yang valid.';
    }
}

function imageDimensions(file) {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const image = new Image();
        image.onload = () => {
            URL.revokeObjectURL(url);
            resolve({ width: image.naturalWidth, height: image.naturalHeight });
        };
        image.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('Invalid image'));
        };
        image.src = url;
    });
}

async function compressProfilePhoto(file) {
    if (file.size < 1024 * 1024) return file;
    const url = URL.createObjectURL(file);
    try {
        const image = new Image();
        image.src = url;
        await image.decode();
        let scale = Math.min(1, 2048 / Math.max(image.naturalWidth, image.naturalHeight));
        while (scale > 0.05) {
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
            canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
            canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
            for (const quality of [0.85, 0.7, 0.55, 0.4]) {
                const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/webp', quality));
                if (blob?.type === 'image/webp' && blob.size < 1024 * 1024) {
                    return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.webp', { type: 'image/webp' });
                }
            }
            scale *= 0.75;
        }
        throw new Error('Compression failed');
    } finally {
        URL.revokeObjectURL(url);
    }
}

export function installFileUploadValidation(document, livewire = () => window.Livewire) {
    const forwardedEvents = new WeakSet();
    const selections = new WeakMap();
    const errors = new WeakMap();
    const validating = new WeakSet();
    const uploading = new WeakSet();
    let nextErrorId = 0;

    const settings = (input) => ({
        accept: input.accept,
        maxKb: Number(input.dataset.maxFileKb || 2048),
    });

    function forwardChange(input) {
        const event = new Event('change', { bubbles: true });
        forwardedEvents.add(event);
        input.dispatchEvent(event);
    }

    function clearUpload(input) {
        uploading.delete(input);
        delete input.dataset.fileUploading;
        input.value = '';
        const model = input.getAttributeNames().find((name) => name.startsWith('wire:model'));
        const root = input.closest('[wire\\:id]');
        if (model && root && livewire()) {
            const wire = livewire().find(root.getAttribute('wire:id'));
            const property = input.getAttribute(model);
            wire.$cancelUpload(property);
            wire.$set(property, null, false);
        }
        forwardChange(input);
    }

    function refreshErrors(form) {
        if (!form) return;
        const summary = form.querySelector('[data-file-upload-errors]');
        if (!summary) return;
        summary.replaceChildren();
        const invalidInputs = [...form.querySelectorAll('input[type="file"]')]
            .filter((input) => errors.has(input));
        summary.hidden = invalidInputs.length === 0;
        if (summary.hidden) {
            summary.removeAttribute('role');
            return;
        }
        summary.setAttribute('role', 'alert');
        const title = document.createElement('p');
        title.className = 'font-semibold';
        title.textContent = 'File belum dapat diunggah. Periksa pesan berikut:';
        summary.append(title);
        const list = document.createElement('ul');
        list.className = 'mt-2 list-disc space-y-1 pl-5';
        for (const input of invalidInputs) {
            const item = document.createElement('li');
            item.id = input.dataset.fileErrorId;
            item.textContent = errors.get(input) + ' ';
            const clear = document.createElement('button');
            clear.type = 'button';
            clear.className = 'font-semibold underline';
            clear.textContent = 'Batalkan pilihan file';
            clear.addEventListener('click', () => {
                selections.delete(input);
                validating.delete(input);
                delete input.dataset.fileValidating;
                setError(input, null);
                clearUpload(input);
            });
            item.append(clear);
            list.append(item);
        }
        summary.append(list);
    }

    function setError(input, message, notify = true) {
        const previousError = errors.get(input);
        if (!input.dataset.fileErrorId) {
            input.dataset.fileErrorId = `frontend-file-error-${++nextErrorId}`;
        }
        const id = input.dataset.fileErrorId;
        const describedBy = (input.getAttribute('aria-describedby') || '').split(/\s+/)
            .filter((value) => value && value !== id);
        if (message) {
            errors.set(input, message);
            input.dataset.fileError = message;
            input.setAttribute('aria-invalid', 'true');
            describedBy.push(id);
        } else {
            errors.delete(input);
            delete input.dataset.fileError;
            input.setAttribute('aria-invalid', 'false');
        }
        input.setAttribute('aria-describedby', describedBy.join(' '));
        input.setCustomValidity(message || '');
        refreshErrors(input.form);
        if (notify && message && message !== previousError) {
            input.dispatchEvent(new CustomEvent('form-notification', {
                bubbles: true, detail: { type: 'error', messages: [message] },
            }));
        }
    }

    document.addEventListener('dragover', (event) => {
        if (event.target.closest?.('[data-file-dropzone]') && [...(event.dataTransfer?.types || [])].includes('Files')) {
            event.preventDefault();
        }
    }, true);

    for (const type of ['drop', 'paste']) {
        document.addEventListener(type, (event) => {
            const zone = event.target.closest?.('[data-file-dropzone]');
            const source = type === 'drop' ? event.dataTransfer : event.clipboardData;
            const file = source?.files?.[0] || [...(source?.items || [])].find((item) => item.kind === 'file')?.getAsFile();
            if (!zone || !file) return;

            event.preventDefault();
            const input = zone.querySelector('input[type="file"]');
            if (!input || input.disabled) return;

            const transfer = new DataTransfer();
            transfer.items.add(file);
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }, true);
    }

    document.addEventListener('change', async (event) => {
        const input = event.target;
        if (!input.matches?.('input[type="file"]') || forwardedEvents.has(event)) return;
        const selection = {};
        selections.set(input, selection);
        validating.delete(input);
        delete input.dataset.fileValidating;
        setError(input, null);
        const files = [...input.files];
        let message = null;
        for (const file of files) {
            message = fileValidationError(file, settings(input));
            if (message) {
                message = `${file.name}: ${message}`;
                break;
            }
        }
        const checkDimensions = !message && files.length > 0 && input.dataset.maxImageDimension;
        const isImage = (file) => mimeTypes[file.name.split('.').pop().toLowerCase()]?.startsWith('image/');
        const compressImages = !message && files.some((file) => isImage(file) && file.size >= 1024 * 1024);
        if (!message && !checkDimensions && !compressImages) {
            return;
        }
        event.stopImmediatePropagation();
        if (checkDimensions || compressImages) {
            input.dataset.fileValidating = 'true';
            validating.add(input);
            if (checkDimensions) {
                for (const file of files) {
                    message = await imageValidationError(file, Number(input.dataset.maxImageDimension));
                    if (message) {
                        message = `${file.name}: ${message}`;
                        break;
                    }
                }
            }
            if (!message && compressImages) {
                try {
                    const compressed = await Promise.all(files.map((file) => isImage(file) ? compressProfilePhoto(file) : file));
                    if (selections.get(input) !== selection || !input.isConnected) return;
                    const transfer = new DataTransfer();
                    compressed.forEach((file) => transfer.items.add(file));
                    input.files = transfer.files;
                } catch {
                    message = 'Foto tidak dapat dikompresi. Pilih foto lain.';
                }
            }
        }
        if (selections.get(input) !== selection || !input.isConnected) return;
        delete input.dataset.fileValidating;
        validating.delete(input);
        if (message) {
            clearUpload(input);
            setError(input, message);
            const summary = input.form?.querySelector('[data-file-upload-errors]');
            summary?.focus({ preventScroll: true });
            summary?.scrollIntoView({ block: 'nearest' });
        } else {
            forwardChange(input);
        }
    }, true);

    document.addEventListener('livewire-upload-start', (event) => {
        const input = event.target;
        if (!input.matches?.('input[type="file"]')) return;
        uploading.add(input);
        input.dataset.fileUploading = 'true';
    }, true);

    for (const type of ['livewire-upload-finish', 'livewire-upload-error', 'livewire-upload-cancel']) {
        document.addEventListener(type, (event) => {
            const input = event.target;
            if (!input.matches?.('input[type="file"]')) return;
            uploading.delete(input);
            delete input.dataset.fileUploading;
            if (type === 'livewire-upload-error') {
                setError(input, 'Unggahan file gagal. Pilih ulang file ini sebelum menyimpan.', false);
            }
        }, true);
    }

    document.addEventListener('submit', (event) => {
        const form = event.target;
        const files = [...form.querySelectorAll('input[type="file"]')];
        let blocked = false;
        for (const input of files) {
            for (const file of input.files) {
                const message = fileValidationError(file, settings(input));
                if (message) {
                    setError(input, `${file.name}: ${message}`);
                }
            }
            blocked ||= errors.has(input) || validating.has(input) || uploading.has(input);
        }
        if (blocked) {
            event.preventDefault();
            event.stopImmediatePropagation();
            const invalid = files.find((input) => errors.has(input) || validating.has(input) || uploading.has(input));
            invalid?.focus();
            invalid?.dispatchEvent(new CustomEvent('form-notification', {
                bubbles: true,
                detail: { type: 'error', messages: [errors.get(invalid) || (uploading.has(invalid) ? 'Tunggu unggahan file selesai sebelum menyimpan form.' : 'Tunggu pemeriksaan file selesai sebelum mengirim form.')] },
            }));
        }
    }, true);

    document.addEventListener('reset', (event) => {
        for (const input of event.target.querySelectorAll('input[type="file"]')) {
            selections.delete(input);
            validating.delete(input);
            delete input.dataset.fileValidating;
            setError(input, null);
        }
    }, true);

    if (typeof MutationObserver !== 'undefined') {
        new MutationObserver((changes) => {
            for (const change of changes) {
                if ([...change.removedNodes].some((node) => node.matches?.('input[type="file"]')
                    || node.querySelector?.('input[type="file"]'))) {
                    refreshErrors(change.target.closest?.('form'));
                }
            }
        }).observe(document.documentElement, { childList: true, subtree: true });
    }
}
