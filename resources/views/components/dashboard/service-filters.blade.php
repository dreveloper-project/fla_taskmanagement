@props(['columns' => 3, 'label' => 'Pencarian dan filter layanan'])

<section {{ $attributes->class(['mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs dark:border-slate-700 dark:bg-slate-900 dark:shadow-none']) }} aria-label="{{ $label }}">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 bg-slate-50/70 px-4 py-3 dark:border-slate-800 dark:bg-slate-800/40 sm:px-5">
        <div class="flex items-center gap-2.5">
            <span class="flex size-7 items-center justify-center rounded-lg bg-blue-100 text-blue-600 dark:bg-blue-950 dark:text-blue-300"><x-dashboard.icon name="search" class="size-4" /></span>
            <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Pencarian &amp; filter</h2>
        </div>
        <span class="hidden text-xs text-slate-500 dark:text-slate-400 sm:inline">Temukan layanan berdasarkan kriteria</span>
    </div>
    <div @class(['grid items-end gap-4 p-4 sm:grid-cols-2 sm:p-5', 'xl:grid-cols-3' => $columns === 3, 'xl:grid-cols-4' => $columns === 4])>
        {{ $slot }}
    </div>
</section>
