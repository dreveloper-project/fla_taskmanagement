@props(['service', 'compact' => false])

@if ($service->status === 'completed')
    @php($type = array_search($service::class, \App\Services\TaskServiceRegistry::TYPES, true))
    <a
        href="{{ route('service-archives.download', ['type' => $type, 'service' => $service->id]) }}"
        data-page-loading-ignore
        data-archive-download
        aria-label="Unduh arsip ZIP {{ $service->service_number }}"
        title="Unduh arsip ZIP {{ $service->service_number }}"
        {{ $attributes->class([
            'inline-flex w-fit shrink-0 items-center justify-center gap-1.5 whitespace-nowrap rounded-lg border border-slate-200 bg-white font-medium text-slate-600 transition-colors hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700 focus-visible:outline-offset-4 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:border-blue-700 dark:hover:bg-blue-950/40 dark:hover:text-blue-300 [&>svg]:size-3.5',
            'h-8 px-2.5 text-[11px]' => $compact,
            'h-9 px-3 text-xs' => ! $compact,
        ]) }}
    >
        <x-dashboard.icon name="download" class="size-3.5" />
        <span>{{ $compact ? 'ZIP' : 'Unduh arsip ZIP' }}</span>
    </a>
@endif
@if (session('archive_error'))
    <p role="alert" class="mt-3 basis-full rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300">{{ session('archive_error') }}</p>
@endif
