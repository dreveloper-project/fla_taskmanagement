const streamType = 'application/vnd.fla.archive-stream';

export async function readArchiveStream(response, onProgress, onTransfer, signal) {
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let pending = new Uint8Array(0);
    let file = null;
    let received = 0;
    const chunks = [];

    function receive(chunk) {
        if (chunk.length === 0) return;
        received += chunk.length;
        if (received > file.size) throw new Error('Ukuran unduhan ZIP tidak sesuai. Silakan coba lagi.');
        chunks.push(chunk);
        onTransfer(Math.min(99, Math.floor(received / file.size * 100)));
    }

    try {
        while (true) {
            signal?.throwIfAborted();
            const { done, value } = await reader.read();
            if (done) break;
            if (file) {
                receive(value);
                continue;
            }
            const combined = new Uint8Array(pending.length + value.length);
            combined.set(pending);
            combined.set(value, pending.length);
            pending = combined;
            let newline;
            while (!file && (newline = pending.indexOf(10)) !== -1) {
                const event = JSON.parse(decoder.decode(pending.subarray(0, newline)));
                pending = pending.subarray(newline + 1);
                if (event.type === 'error') throw new Error(event.message);
                if (event.type === 'progress') {
                    if (!Number.isFinite(event.percent) || typeof event.message !== 'string') throw new Error('Progres ZIP tidak dapat dibaca.');
                    onProgress(Math.max(0, Math.min(100, event.percent)), event.message);
                } else if (event.type === 'file') {
                    if (!Number.isSafeInteger(event.size) || event.size <= 0 || typeof event.filename !== 'string') throw new Error('Informasi ZIP tidak valid.');
                    file = event;
                    onTransfer(0);
                    receive(pending);
                    pending = new Uint8Array(0);
                } else {
                    throw new Error('Respons ZIP tidak dikenali.');
                }
            }
            if (pending.length > 65536) throw new Error('Respons ZIP tidak valid.');
        }
        signal?.throwIfAborted();
        if (!file || received !== file.size) throw new Error('Unduhan ZIP terputus. Silakan coba lagi.');
        onTransfer(100);

        return { blob: new Blob(chunks, { type: 'application/zip' }), filename: file.filename };
    } finally {
        reader.releaseLock();
    }
}

export function installArchiveDownloads(document, browser = window) {
    let active = null;
    const initialized = new WeakSet();

    function update(request, percent, message, transfer = false) {
        if (active !== request || !request.busy) return;
        const popup = request.popup;
        popup.querySelector('[data-archive-title]').textContent = transfer ? 'Mengunduh ZIP' : 'Menyiapkan ZIP';
        popup.querySelector('[data-archive-description]').textContent = message;
        popup.querySelector('[data-archive-percent]').textContent = `${percent}%`;
        popup.querySelector('[data-archive-phase]').textContent = transfer ? 'Transfer file' : 'Persiapan ZIP';
        const bar = popup.querySelector('[data-archive-bar]');
        bar.value = percent;
        bar.setAttribute('aria-label', transfer ? 'Transfer file ZIP' : 'Persiapan ZIP');
    }

    function finish(request, title, message, failed = false) {
        request.busy = false;
        request.popup.setAttribute('aria-busy', 'false');
        request.popup.querySelector('[data-archive-title]').textContent = title;
        request.popup.querySelector('[data-archive-description]').textContent = message;
        request.popup.querySelector('[data-archive-meter]').hidden = failed;
        request.popup.querySelector('[data-archive-status]').textContent = failed ? 'Anda dapat menutup dialog ini dan mencoba kembali.' : 'Periksa unduhan pada browser Anda.';
        request.popup.querySelector('[data-archive-cancel]').textContent = 'Tutup';
    }

    function cancel() {
        if (!active) return;
        if (!active.busy) {
            active.popup.close();
            active = null;
            return;
        }
        active.controller.abort();
        finish(active, 'Unduhan dibatalkan', 'Proses ZIP dihentikan. Tidak ada file yang disimpan ke unduhan Anda.', true);
    }

    function leave() {
        if (!active) return;
        active.controller.abort();
        active.popup.close();
        active = null;
    }

    async function start(link, popup) {
        if (active?.busy) return;
        const request = { popup, controller: new AbortController(), busy: true };
        active = request;
        if (!initialized.has(popup)) {
            popup.addEventListener('cancel', (event) => {
                event.preventDefault();
                cancel();
            });
            popup.querySelector('[data-archive-cancel]').addEventListener('click', cancel);
            initialized.add(popup);
        }
        popup.querySelector('[data-archive-meter]').hidden = false;
        popup.querySelector('[data-archive-cancel]').textContent = 'Batal';
        popup.querySelector('[data-archive-status]').textContent = 'Anda dapat membatalkan selama proses berlangsung.';
        popup.setAttribute('aria-busy', 'true');
        update(request, 0, 'Mengumpulkan data dan berkas…');
        if (!popup.open) popup.showModal();

        try {
            const response = await browser.fetch(link.href, {
                method: 'POST', credentials: 'same-origin', signal: request.controller.signal,
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            });
            if (!response.ok || !response.headers.get('Content-Type')?.includes(streamType)) {
                if (response.status === 419 || response.status === 401 || response.redirected) throw new Error('Sesi berakhir. Muat ulang halaman lalu masuk kembali.');
                let message = 'ZIP gagal diunduh. Silakan coba lagi.';
                if (response.headers.get('Content-Type')?.includes('application/json')) {
                    const error = await response.json();
                    message = Object.values(error.errors ?? {}).flat()[0] ?? error.message ?? message;
                }
                throw new Error(message);
            }
            const result = await readArchiveStream(response,
                (percent, message) => update(request, percent, message),
                (percent) => update(request, percent, 'ZIP selesai disiapkan. Menerima file dari server…', true),
                request.controller.signal);
            request.controller.signal.throwIfAborted();
            if (active !== request) return;
            const url = browser.URL.createObjectURL(result.blob);
            const download = document.createElement('a');
            download.href = url;
            download.download = result.filename.replace(/[\\/]/g, '-');
            document.body.append(download);
            download.click();
            download.remove();
            browser.setTimeout(() => browser.URL.revokeObjectURL(url), 60000);
            finish(request, 'ZIP siap', 'File ZIP telah diterima. Unduhan diserahkan ke browser Anda.');
        } catch (error) {
            if (active !== request || request.controller.signal.aborted) return;
            request.controller.abort();
            finish(request, 'Unduhan gagal', error instanceof TypeError ? 'Koneksi terputus. Periksa jaringan lalu coba lagi.' : error.message, true);
        }
    }

    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const link = event.target?.closest?.('a[data-archive-download]');
        const popup = document.querySelector('[data-archive-progress]');
        if (!link || !popup || !browser.fetch || !browser.ReadableStream || !document.querySelector('meta[name="csrf-token"]')) return;
        event.preventDefault();
        void start(link, popup);
    });
    document.addEventListener('livewire:navigating', leave);
    browser.addEventListener('pagehide', leave);
    browser.addEventListener('offline', () => {
        if (!active?.busy) return;
        active.controller.abort();
        finish(active, 'Unduhan gagal', 'Koneksi terputus. Periksa jaringan lalu coba lagi.', true);
    });
}
