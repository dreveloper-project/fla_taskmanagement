@props(['label', 'value', 'icon', 'description', 'tone' => 'blue', 'compact' => false])

<article {{ $attributes->class(['grid h-full min-w-0 grid-cols-[minmax(0,1fr)_auto] items-start gap-x-2 rounded-xl border border-slate-200/80 dark:border-slate-700/80 bg-white dark:bg-slate-900 shadow-xs', 'gap-y-1 p-2.5 sm:p-3' => $compact, 'gap-y-1.5 p-3 sm:block sm:p-5' => ! $compact]) }}>
    <div class="col-start-2 row-start-1 flex items-center justify-between gap-3">
        <span @class([
            'flex shrink-0 items-center justify-center rounded-lg [&>svg]:size-4',
            'size-6 sm:size-7' => $compact,
            'size-7 sm:size-10 sm:rounded-xl sm:[&>svg]:size-5' => ! $compact,
            'bg-blue-50 dark:bg-blue-950/40 text-blue-600 dark:text-blue-400' => $tone === 'blue',
            'bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400' => $tone === 'rose',
            'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400' => $tone === 'green',
        ])><x-dashboard.icon :name="$icon" /></span>
        @unless ($compact)<span class="hidden text-[10px] font-medium tracking-wider text-slate-400 uppercase sm:inline">Today</span>@endunless
    </div>
    <div @class(['contents', 'sm:mt-4 sm:flex sm:items-baseline sm:gap-3' => ! $compact])>
        <p @class(['col-start-1 row-start-1 min-w-0 self-center leading-none font-semibold tracking-tight tabular-nums wrap-break-word', 'text-xl sm:text-2xl' => $compact, 'text-[22px] sm:text-[32px]' => ! $compact])>{{ $value }}</p>
        <h2 @class(['col-span-2 row-start-2 text-[11px] leading-4 font-medium text-slate-600 dark:text-slate-300 wrap-break-word', 'sm:text-[13px] sm:leading-normal' => ! $compact])>{{ $label }}</h2>
    </div>
    <p @class(['hidden text-slate-500 dark:text-slate-400 sm:block', 'col-span-2 row-start-3 text-[10px] leading-4' => $compact, 'mt-3 text-xs' => ! $compact])>{{ $description }}</p>
</article>
