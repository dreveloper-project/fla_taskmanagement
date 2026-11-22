@props(['type', 'filters', 'count', 'total', 'title' => 'Daftar layanan', 'error' => null])

@php
    $label = $type === 'spb' ? 'SPB' : 'layanan';
    $exportFilters = array_filter($filters, fn (string $value): bool => trim($value) !== '');
    $downloadUrl = $type === 'spb'
        ? route('spb.archives.download', $exportFilters)
        : route('service-archives.completed', ['type' => $type, ...$exportFilters]);
@endphp

@if ($error)
    <p role="alert" class="m-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300">{{ $error }}</p>
@endif
<x-form-errors :id="$type.'-archive-errors'" class="m-4" />
<div {{ $attributes->class(['flex flex-col gap-4 border-b border-slate-200 p-4 dark:border-slate-700 sm:flex-row sm:items-center sm:justify-between sm:p-5']) }}>
    <div>
        <h2 class="text-sm font-semibold">{{ $title }}</h2>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $total }} layanan ditemukan</p>
    </div>
    <div class="flex flex-col gap-2 sm:items-end" wire:loading.attr="inert" wire:loading.class="opacity-50" wire:target="{{ implode(',', array_keys($filters)) }}">
        @if ($count > 0)
            <x-dashboard.download-button data-archive-download class="w-full sm:w-auto" :aria-describedby="$type.'-export-scope'" :href="$downloadUrl">Unduh ZIP Completed ({{ $count }})</x-dashboard.download-button>
            <p id="{{ $type }}-export-scope" class="text-xs text-slate-500 dark:text-slate-400">{{ $exportFilters ? 'Completed sesuai filter aktif' : 'Semua '.$label.' Completed' }} &middot; Seluruh halaman</p>
        @else
            <button type="button" disabled aria-describedby="{{ $type }}-export-scope" class="inline-flex min-h-8 w-full cursor-not-allowed items-center justify-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-400 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-500 sm:w-auto">
                <x-dashboard.icon name="download" class="size-4 shrink-0" aria-hidden="true" />
                Unduh ZIP Completed (0)
            </button>
            <p id="{{ $type }}-export-scope" class="text-xs text-slate-500 dark:text-slate-400">Tidak ada {{ $label }} Completed yang sesuai dengan filter.</p>
        @endif
    </div>
</div>
