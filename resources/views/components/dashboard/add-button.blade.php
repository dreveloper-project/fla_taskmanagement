@props(['href' => null, 'variant' => 'primary', 'compact' => false, 'icon' => 'plus'])

@php
    $buttonClasses = \Illuminate\Support\Arr::toCssClasses([
        'group inline-flex shrink-0 items-center justify-center gap-2 rounded-lg border text-xs font-semibold transition-[translate,box-shadow,background-color,color,border-color] duration-200 hover:-translate-y-0.5 active:translate-y-0 focus-visible:outline-offset-4 motion-reduce:hover:translate-y-0 motion-reduce:transition-none disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:translate-y-0 disabled:hover:shadow-none',
        'min-h-9 px-3 py-1.5' => ! $compact,
        'min-h-8 px-2.5 py-1' => $compact,
        'border-blue-600 bg-blue-600 text-white shadow-sm shadow-blue-500/15 hover:border-blue-700 hover:bg-blue-700 hover:shadow-md dark:border-blue-800 dark:bg-blue-950 dark:text-blue-200 dark:shadow-none dark:hover:border-blue-700 dark:hover:bg-blue-900' => $variant === 'primary',
        'border-blue-200 bg-blue-50 text-blue-700 shadow-xs hover:bg-blue-100 dark:border-slate-700 dark:bg-slate-800 dark:text-blue-300 dark:shadow-none dark:hover:border-slate-600 dark:hover:bg-slate-700' => $variant === 'soft',
    ]);
@endphp

@if ($href)
    <a {{ $attributes->merge(['href' => $href, 'class' => $buttonClasses]) }}>
@else
    <button {{ $attributes->merge(['type' => 'button', 'class' => $buttonClasses]) }}>
@endif
    <span @class(['flex shrink-0 items-center justify-center rounded-md transition-transform duration-200 group-hover:scale-110 group-disabled:scale-100 motion-reduce:group-hover:scale-100 motion-reduce:transition-none', 'size-5' => ! $compact, 'size-4.5' => $compact, 'bg-white/15 dark:bg-blue-900' => $variant === 'primary', 'bg-blue-100 dark:bg-slate-700' => $variant === 'soft']) aria-hidden="true">
        <x-dashboard.icon :name="$icon" class="size-3.5" />
    </span>
    <span>{{ $slot }}</span>
@if ($href)
    </a>
@else
    </button>
@endif
