<dialog data-form-loading aria-labelledby="form-loading-title" aria-describedby="form-loading-description" aria-busy="true"
    class="m-auto w-[calc(100%-2rem)] max-w-sm overflow-hidden rounded-3xl border border-slate-200/80 bg-white p-0 text-slate-800 shadow-2xl backdrop:bg-slate-950/45 backdrop:backdrop-blur-sm dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
    <div class="flex flex-col items-center gap-5 px-7 py-8 text-center">
        <div aria-hidden="true" class="relative flex size-18 items-center justify-center rounded-2xl bg-blue-50 dark:bg-blue-500/10">
            <span class="absolute size-12 animate-spin rounded-full border-[3px] border-blue-200 border-t-blue-600 motion-reduce:animate-none dark:border-blue-900 dark:border-t-blue-400"></span>
            <svg class="size-5 text-blue-600 dark:text-blue-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-6-6H8Zm6 0v6h6M8 13h8m-8 4h5" />
            </svg>
        </div>
        <div class="space-y-2">
            <h2 id="form-loading-title" class="text-lg font-semibold tracking-tight">{{ request()->routeIs('login') ? 'Dalam proses masuk' : 'Memproses data' }}</h2>
            <p id="form-loading-description" class="text-sm leading-relaxed text-slate-500 dark:text-slate-400">{{ request()->routeIs('login') ? 'Mohon tunggu, Anda sedang masuk ke ruang kerja.' : 'Mohon tunggu, form Anda sedang diproses.' }}</p>
        </div>
        <div role="progressbar" aria-label="Proses pengiriman form" class="h-1.5 w-full overflow-hidden rounded-full bg-blue-100 dark:bg-slate-800">
            <span class="form-loading-bar block h-full w-2/5 rounded-full bg-linear-to-r from-blue-500 to-cyan-400"></span>
        </div>
        <p role="status" class="text-xs font-medium text-slate-400 dark:text-slate-500">Hasil proses akan ditampilkan secara otomatis</p>
    </div>
</dialog>
