@props(['histories', 'statuses', 'timezone', 'prefix' => 'service', 'fields' => []])
<section class="mt-6 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
    <h2 class="text-base font-semibold">Status Timeline</h2>
    <ol class="mt-5 flex flex-col gap-5">
        @foreach ($histories->sortByDesc('id') as $history)
            <li wire:key="{{ $prefix }}-history-{{ $history->id }}" class="border-l-2 border-blue-100 dark:border-blue-900 pl-4">
                <p class="text-xs text-slate-400">{{ $history->created_at->timezone($timezone)->format('d M Y H:i') }} WITA · {{ $history->actor->name }}</p>
                <p class="mt-1 text-sm font-semibold">{{ __($history->event) }}</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $history->from_status ? $statuses[$history->from_status].' → ' : '' }}{{ $statuses[$history->to_status] }}</p>
                @if ($history->notes)<p class="mt-2 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ __($history->notes) }}</p>@endif
                @foreach ($fields as $key => $label)
                    @if (filled(data_get($history->metadata, $key)))<p wire:key="{{ $prefix }}-history-{{ $history->id }}-{{ $key }}" class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $label }}: {{ data_get($history->metadata, $key) }}</p>@endif
                @endforeach
            </li>
        @endforeach
    </ol>
</section>
