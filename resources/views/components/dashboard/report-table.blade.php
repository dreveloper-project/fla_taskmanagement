@props(['kind', 'report', 'rows', 'filters'])

<section class="mt-6 overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 dark:border-slate-700 p-5">
        <h2 class="text-sm font-semibold">{{ \App\Services\ReportService::REPORTS[$kind] }} <span class="ml-2 text-xs font-normal text-slate-400">{{ $rows->total() }}</span></h2>
        <a href="{{ route('reports.export', ['kind' => $kind] + $filters) }}" class="report-controls inline-flex items-center gap-2 rounded-lg border border-emerald-200 dark:border-emerald-900 bg-emerald-50 dark:bg-emerald-950/40 px-3 py-2 text-xs font-semibold text-emerald-700 dark:text-emerald-300 transition-colors hover:bg-emerald-100 dark:hover:bg-emerald-950/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">
            <x-dashboard.icon name="spreadsheet" class="text-emerald-600 dark:text-emerald-400" />
            Export XLSX
        </a>
    </div>
    <div class="report-table overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead class="bg-slate-50 dark:bg-slate-800 text-slate-500 dark:text-slate-400"><tr>@foreach ($report->columns($kind) as $key => $label)<th wire:key="{{ $kind }}-header-{{ $key }}" class="whitespace-nowrap px-4 py-3 font-medium">{{ $label }}</th>@endforeach<th class="report-controls px-4 py-3">Action</th></tr></thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr wire:key="{{ $kind }}-row-{{ $row->report_type ?? $row->edition ?? '' }}-{{ $row->id }}" class="border-t border-slate-100 dark:border-slate-700">
                        @foreach ($report->row($kind, $row) as $key => $value)
                            <td wire:key="{{ $kind }}-cell-{{ $row->report_type ?? $row->edition ?? '' }}-{{ $row->id }}-{{ $key }}" class="whitespace-nowrap px-4 py-3 {{ $key === 'attention' && $value !== 'No' && $value !== 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-600 dark:text-slate-300' }}">{{ $value }}</td>
                        @endforeach
                        <td class="report-controls whitespace-nowrap px-4 py-3">@if ($report->rowUrl($kind, $row))<a class="font-medium text-blue-600 dark:text-blue-400" href="{{ $report->rowUrl($kind, $row) }}">{{ ['services' => 'View Service', 'vessels' => 'View Vessel', 'documents' => 'View Vessel'][$kind] }}</a>@else—@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($report->columns($kind)) + 1 }}" class="p-8 text-center text-sm text-slate-500 dark:text-slate-400">Tidak ada data laporan untuk periode/filter yang dipilih.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="report-controls border-t border-slate-100 dark:border-slate-700 p-4">{{ $rows->links() }}</div>
    <p class="px-4 pb-3 text-[10px] text-slate-400">Halaman {{ $rows->currentPage() }} / {{ $rows->lastPage() }}. XLSX memuat seluruh hasil filter.</p>
</section>
