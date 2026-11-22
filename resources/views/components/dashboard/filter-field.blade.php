@props(['name', 'label', 'type' => 'text', 'wide' => false])

@php
    $fieldClasses = 'block h-10 w-full min-w-0 rounded-lg border border-slate-200 bg-slate-50 px-3 text-xs text-slate-700 outline-none transition-colors placeholder:text-slate-400 hover:border-slate-300 focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-500/15 motion-reduce:transition-none dark:border-slate-700 dark:bg-slate-950/50 dark:text-slate-200 dark:placeholder:text-slate-500 dark:hover:border-slate-600 dark:focus:border-blue-400 dark:focus:bg-slate-900 dark:focus:ring-blue-400/20';
@endphp

<div @class(['min-w-0', 'sm:col-span-2' => $wide])>
    <label for="{{ $name }}" class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300">{{ $label }}</label>
    @if ($type === 'select')
        <select {{ $attributes->merge(['id' => $name, 'name' => $name, 'class' => $fieldClasses]) }}>
            {{ $slot }}
        </select>
    @elseif (in_array($type, ['date', 'datetime-local'], true))
        <x-date-time-input :name="$name" :label="$label" :type="$type" :compact="true" :attributes="$attributes" />
    @else
        <div class="relative">
            @if ($type === 'search')
                <x-dashboard.icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400 dark:text-slate-500" />
            @endif
            <input {{ $attributes->class([$fieldClasses, 'pl-9' => $type === 'search'])->merge(['id' => $name, 'name' => $name, 'type' => $type]) }}>
        </div>
    @endif
</div>
