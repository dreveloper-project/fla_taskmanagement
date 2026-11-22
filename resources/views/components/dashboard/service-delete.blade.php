@props(['service'])

@can('delete', $service)
    <div x-data class="mt-4 flex justify-end">
        <button type="button" x-on:click="$refs.deleteServiceDialog.showModal()" class="inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 transition hover:bg-rose-100 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300 dark:hover:bg-rose-900/50">
            <x-dashboard.icon name="trash" class="size-4" /> Hapus service
        </button>
        <dialog wire:ignore.self x-ref="deleteServiceDialog" aria-labelledby="delete-service-title" class="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border border-slate-200 bg-white p-5 text-slate-800 shadow-xl backdrop:bg-slate-950/45 backdrop:backdrop-blur-sm dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
            <form wire:submit="deleteService" class="space-y-4">
                <div class="flex items-center gap-3"><span class="flex size-10 items-center justify-center rounded-xl bg-rose-50 text-rose-600 dark:bg-rose-950/40 dark:text-rose-300"><x-dashboard.icon name="trash" /></span><h2 id="delete-service-title" class="text-base font-semibold">Hapus service ini?</h2></div>
                <p class="text-sm leading-6 text-slate-600 dark:text-slate-300">Service <strong>{{ $service->service_number }}</strong>, dokumen yang diunggah pada service, dan task terkait akan dihapus permanen. Dokumen milik Vessel tetap disimpan.</p>
                <x-form-errors fields="deletion" id="delete-service-errors" />
                <div class="flex justify-end gap-2">
                    <button type="button" x-on:click="$refs.deleteServiceDialog.close()" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700">Batal</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="deleteService" class="inline-flex items-center gap-2 rounded-lg bg-rose-600 px-3 py-2 text-xs font-semibold text-white hover:bg-rose-700 disabled:cursor-wait disabled:opacity-50 dark:bg-rose-500 dark:hover:bg-rose-600"><x-dashboard.icon name="trash" class="size-4" />Hapus permanen</button>
                </div>
            </form>
        </dialog>
    </div>
@endcan
