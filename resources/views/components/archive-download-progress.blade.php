<dialog data-archive-progress aria-labelledby="archive-progress-title" aria-describedby="archive-progress-description" class="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border border-slate-200 bg-white p-6 text-slate-800 shadow-xl backdrop:bg-slate-950/45 backdrop:backdrop-blur-sm dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
    <div class="space-y-5">
        <div class="flex size-12 items-center justify-center rounded-xl bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-400" aria-hidden="true"><x-dashboard.icon name="download" class="size-6" /></div>
        <div>
            <h2 id="archive-progress-title" data-archive-title class="text-lg font-semibold">Menyiapkan ZIP</h2>
            <p id="archive-progress-description" data-archive-description class="mt-2 text-sm leading-6 text-slate-500 dark:text-slate-400">Mengumpulkan data dan berkas…</p>
        </div>
        <div data-archive-meter>
            <div class="mb-2 flex items-center justify-between gap-3 text-xs font-medium">
                <span data-archive-phase>Persiapan ZIP</span>
                <span data-archive-percent class="tabular-nums">0%</span>
            </div>
            <progress data-archive-bar aria-label="Persiapan ZIP" max="100" value="0" class="block h-2 w-full overflow-hidden rounded-full bg-slate-100 [&::-webkit-progress-bar]:bg-slate-100 [&::-webkit-progress-value]:rounded-full [&::-webkit-progress-value]:bg-blue-600 [&::-moz-progress-bar]:bg-blue-600 dark:bg-slate-800 dark:[&::-webkit-progress-bar]:bg-slate-800"></progress>
        </div>
        <p data-archive-status role="status" aria-live="polite" class="text-xs leading-5 text-slate-500 dark:text-slate-400">Anda dapat membatalkan selama proses berlangsung.</p>
        <button data-archive-cancel type="button" class="inline-flex min-h-11 w-full items-center justify-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline-offset-4 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800">Batal</button>
    </div>
</dialog>
