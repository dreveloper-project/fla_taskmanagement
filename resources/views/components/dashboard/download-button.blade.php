@props(['href'])

<a data-page-loading-ignore {{ $attributes->merge(['href' => $href])->class(['group inline-flex min-h-8 max-w-full items-center justify-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700 shadow-xs transition-[background-color,border-color,box-shadow,color] duration-200 hover:border-blue-300 hover:bg-blue-100 hover:shadow-sm focus-visible:outline-offset-4 motion-reduce:transition-none dark:border-slate-700 dark:bg-slate-800 dark:text-blue-300 dark:shadow-none dark:hover:border-blue-800 dark:hover:bg-slate-700']) }}>
    <span class="flex size-5 shrink-0 items-center justify-center rounded-md bg-blue-100 dark:bg-blue-950" aria-hidden="true">
        <x-dashboard.icon name="download" class="size-3.5 transition-transform duration-200 group-hover:translate-y-0.5 motion-reduce:group-hover:translate-y-0 motion-reduce:transition-none" />
    </span>
    <span class="min-w-0 text-left wrap-anywhere">{{ $slot->isEmpty() ? 'Unduh dokumen' : $slot }}</span>
</a>
