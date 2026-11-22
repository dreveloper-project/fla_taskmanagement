<?php

use App\Models\VesselDocument;
use App\Services\ReportFilters;
use App\Services\ReportService;
use App\Services\TaskServiceRegistry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Reports')] class extends Component
{
    use WithPagination;

    public array $filters = [];

    #[Locked]
    public array $applied = [];

    #[Locked]
    public string $tab = 'summary';

    #[Locked]
    public ?string $errorForm = null;

    public function boot(): void
    {
        Gate::authorize('access-reports');
    }

    public function mount(): void
    {
        $this->applied = ReportFilters::validate(request()->query());
        $this->filters = $this->applied;
    }

    public function applyFilters(string $form = 'filters'): void
    {
        $this->errorForm = $form === 'documents' ? 'documents' : 'filters';
        $this->resetValidation();
        $this->applied = ReportFilters::validate($this->filters);
        foreach (array_keys(ReportService::REPORTS) as $kind) {
            $this->resetPage($kind.'Page');
        }
        unset($this->report, $this->summary, $this->breakdown, $this->trend, $this->distribution, $this->vesselSummary, $this->documentSummary);
    }

    public function resetFilters(): void
    {
        $this->filters = ReportFilters::defaults();
        $this->resetValidation();
        $this->applyFilters();
    }

    public function selectTab(string $tab): void
    {
        abort_unless(in_array($tab, ['summary', 'services', 'vessels', 'staff'], true), 404);
        if ($tab === 'staff') {
            Gate::authorize('access-reports');
        }
        $this->tab = $tab;
    }

    #[Computed]
    public function report(): ReportService
    {
        return new ReportService($this->applied);
    }

    #[Computed]
    public function options(): array
    {
        return $this->report->options();
    }

    #[Computed]
    public function summary(): array
    {
        return $this->report->summary();
    }

    #[Computed]
    public function breakdown(): \Illuminate\Support\Collection
    {
        return $this->report->breakdown();
    }

    #[Computed]
    public function distribution(): \Illuminate\Support\Collection
    {
        return $this->report->distribution();
    }

    #[Computed]
    public function trend(): \Illuminate\Support\Collection
    {
        return $this->report->trend();
    }

    #[Computed]
    public function vesselSummary(): array
    {
        return $this->report->vesselSummary();
    }

    #[Computed]
    public function documentSummary(): array
    {
        return $this->report->documentSummary();
    }

    public function rows(string $kind): LengthAwarePaginator
    {
        abort_unless(isset(ReportService::REPORTS[$kind]), 404);
        if (in_array($kind, ['staff'], true)) {
            Gate::authorize('access-reports');
        }

        return $this->report->reportQuery($kind)->paginate(10, ['*'], $kind.'Page');
    }
};
?>

<x-dashboard.shell page="Reports" :demo="false">
    <style>
        @media print {
            @page { size: landscape; margin: 10mm; }
            aside, header, .report-controls, nav[aria-label="Pagination Navigation"] { display: none !important; }
            .lg\:pl-64 { padding-left: 0 !important; }
            main { max-width: none !important; padding: 0 !important; }
            .report-table { overflow: visible !important; }
            table { width: 100% !important; table-layout: fixed; font-size: 9px !important; }
            th, td { white-space: normal !important; overflow-wrap: anywhere; padding: 4px !important; }
            tr { break-inside: avoid; }
        }
    </style>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div><p class="mb-1.5 text-xs text-slate-500 dark:text-slate-400">Operations analytics</p><h1 class="text-2xl font-semibold sm:text-[28px]">Reports</h1><p class="mt-2 text-[13px] text-slate-500 dark:text-slate-400">Ringkasan layanan, kapal, dokumen, dan pekerjaan staf.</p></div>
        <button type="button" x-on:click="window.print()" class="report-controls rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-2 text-xs">Print halaman ini</button>
    </div>
    <form novalidate wire:submit="applyFilters" class="report-controls mt-6 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
        <x-form-errors id="report-1-errors" :show="$errorForm === 'filters'" />
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-dashboard.filter-field name="report_date_from" label="Date From" type="date" wire:model="filters.date_from" required />
            <x-dashboard.filter-field name="report_date_to" label="Date To" type="date" wire:model="filters.date_to" required />
            <label class="text-xs text-slate-600 dark:text-slate-300">Service Type<select wire:model="filters.service_type" class="mt-1 block w-full rounded-lg border border-slate-200 dark:border-slate-700 p-2.5"><option value="">Semua layanan</option>@foreach (TaskServiceRegistry::LABELS as $key => $label)<option wire:key="report-type-{{ $key }}" value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
            <label class="text-xs text-slate-600 dark:text-slate-300">Status Service<select wire:model="filters.status" class="mt-1 block w-full rounded-lg border border-slate-200 dark:border-slate-700 p-2.5"><option value="">Semua status</option>@foreach (ReportFilters::statuses() as $key => $label)<option wire:key="report-status-{{ $key }}" value="{{ $key }}">{{ $label }} ({{ $key }})</option>@endforeach</select></label>
            <label class="text-xs text-slate-600 dark:text-slate-300">Vessel<select wire:model="filters.vessel_id" class="mt-1 block w-full rounded-lg border border-slate-200 dark:border-slate-700 p-2.5"><option value="">Semua kapal</option>@foreach ($this->options['vessels'] as $vessel)<option wire:key="report-vessel-{{ $vessel->id }}" value="{{ $vessel->id }}">{{ $vessel->vessel_name }}</option>@endforeach</select></label>
            <label class="text-xs text-slate-600 dark:text-slate-300">PIC / Staff<select wire:model="filters.staff_id" class="mt-1 block w-full rounded-lg border border-slate-200 dark:border-slate-700 p-2.5"><option value="">Semua staf</option>@foreach ($this->options['staff'] as $staff)<option wire:key="report-staff-{{ $staff->id }}" value="{{ $staff->id }}">{{ $staff->name }}</option>@endforeach</select></label>
            <div class="flex items-end gap-2"><button wire:loading.attr="disabled" class="rounded-lg bg-blue-500 px-4 py-2.5 text-xs font-semibold text-white disabled:opacity-50">Apply Filter</button><button type="button" wire:click="resetFilters" class="rounded-lg border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-xs">Reset</button></div>
        </div>
    </form>
    <p class="mt-3 text-xs leading-5 text-slate-500 dark:text-slate-400">Periode aktif: {{ $applied['date_from'] }} s/d {{ $applied['date_to'] }} (WITA).
        Layanan memakai tanggal dibuat. Tren selesai memakai tanggal selesai. Kapal/dokumen menampilkan inventaris saat ini; filter layanan/PIC membatasi kapal ke layanan dalam periode.
        @if ($applied['service_type'])Layanan: {{ TaskServiceRegistry::LABELS[$applied['service_type']] }}.@endif
    </p>
    <nav aria-label="Report sections" class="report-controls mt-5 flex flex-wrap gap-2">
        @foreach ((auth()->user()->can('access-reports') ? ['summary' => 'Operational Summary', 'services' => 'Service Report', 'vessels' => 'Vessel & Document Report', 'staff' => 'Staff Performance'] : ['summary' => 'Operational Summary', 'services' => 'Service Report', 'vessels' => 'Vessel & Document Report']) as $key => $label)
            <button wire:key="report-tab-{{ $key }}" wire:click="selectTab('{{ $key }}')" @class(['rounded-lg px-4 py-2.5 text-xs font-semibold', 'bg-blue-500 text-white' => $tab === $key, 'border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </nav>
    <div wire:loading class="mt-3 text-xs text-blue-600 dark:text-blue-400">Memuat laporan...</div>

    @if ($tab === 'summary')
        <section class="mt-4 sm:mt-6 grid grid-cols-2 gap-2 sm:gap-3 xl:grid-cols-3">
            <x-dashboard.stat-card label="Total Services" :value="$this->summary['total']" icon="document" description="Dibuat dalam periode" />
            <x-dashboard.stat-card label="Active Services" :value="$this->summary['active']" icon="clock" description="Status aktif setiap layanan" />
            <x-dashboard.stat-card label="Completed Services" :value="$this->summary['completed']" icon="check" description="Layanan berstatus completed" tone="green" />
            <x-dashboard.stat-card label="Need Attention" :value="$this->summary['attention']" icon="alert" :description="$this->summary['service_attention'].' layanan · '.$this->summary['expired_documents'].' dokumen expired'" tone="rose" />
            <x-dashboard.stat-card label="Completion Rate" :value="$this->summary['completion_rate'].'%'" icon="chart" description="Completed / (Total − Cancelled)" />
            <x-dashboard.stat-card label="Average Completion Time" :value="ReportService::duration($this->summary['average_seconds'])" icon="clock" description="Hanya completed dengan waktu valid" />
        </section>
        @if ($this->summary['total'] === 0)<p class="mt-5 rounded-xl border border-dashed border-slate-300 dark:border-slate-700 p-5 text-sm text-slate-500 dark:text-slate-400">Tidak ada data laporan untuk periode/filter yang dipilih.</p>@endif
        <section class="mt-6 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
            <h2 class="text-sm font-semibold">Service Type Breakdown</h2>
            <div class="report-table mt-4 overflow-x-auto"><table class="w-full text-left text-xs"><thead class="text-slate-500 dark:text-slate-400"><tr>@foreach (['Service Type', 'Total', 'Active', 'Completed', 'Rejected', 'Cancelled', 'Average Completion'] as $label)<th wire:key="breakdown-header-{{ $loop->index }}" class="p-3">{{ $label }}</th>@endforeach</tr></thead><tbody>
                @foreach ($this->breakdown as $row)<tr wire:key="breakdown-{{ $row->report_type }}" class="border-t border-slate-100 dark:border-slate-700"><td class="p-3">{{ TaskServiceRegistry::LABELS[$row->report_type] }}</td><td class="p-3">{{ $row->total }}</td><td class="p-3">{{ $row->active }}</td><td class="p-3">{{ $row->completed }}</td><td class="p-3">{{ $row->rejected }}</td><td class="p-3">{{ $row->cancelled }}</td><td class="p-3">{{ ReportService::duration($row->average_seconds === null ? null : (float) $row->average_seconds) }}</td></tr>@endforeach
            </tbody></table></div>
        </section>
        <div class="mt-6 grid gap-5 lg:grid-cols-2">
            <section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5"><h2 class="text-sm font-semibold">Monthly Trend (WITA)</h2><table class="mt-4 w-full text-left text-xs"><thead><tr><th class="p-2">Bulan</th><th class="p-2">Created</th><th class="p-2">Completed</th></tr></thead><tbody>@forelse ($this->trend as $row)<tr wire:key="trend-{{ $row->month }}" class="border-t border-slate-100 dark:border-slate-700"><td class="p-2">{{ $row->month }}</td><td class="p-2">{{ $row->created }}</td><td class="p-2">{{ $row->completed }}</td></tr>@empty<tr><td colspan="3" class="p-3 text-slate-500 dark:text-slate-400">Tidak ada data laporan untuk periode/filter yang dipilih.</td></tr>@endforelse</tbody></table><p class="mt-3 text-[11px] text-slate-500 dark:text-slate-400">Created dan Completed dihitung pada tanggal kejadian masing-masing. Penyelesaian dapat berasal dari layanan yang dibuat sebelum periode.</p></section>
            <section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5"><h2 class="text-sm font-semibold">Status Distribution</h2><dl class="mt-4 space-y-3">@foreach ($this->distribution as $row)<div wire:key="distribution-{{ $loop->index }}" class="flex justify-between border-b border-slate-100 dark:border-slate-700 pb-2 text-xs"><dt>{{ $row->status_group }}</dt><dd class="font-semibold">{{ $row->total }}</dd></div>@endforeach</dl></section>
        </div>
    @elseif ($tab === 'services')
        <x-dashboard.report-table kind="services" :report="$this->report" :rows="$this->rows('services')" :filters="$applied" />
    @elseif ($tab === 'vessels')
        <section class="mt-4 sm:mt-6 grid grid-cols-2 gap-2 sm:gap-3 xl:grid-cols-4">
            @foreach (['total' => 'Total Vessels', 'berthed' => 'Berthed', 'sailing' => 'Sailing', 'maintenance' => 'Maintenance'] as $key => $label)<x-dashboard.stat-card wire:key="vessel-stat-{{ $key }}" :label="$label" :value="$this->vesselSummary[$key]" icon="ship" description="Inventaris kapal saat ini" />@endforeach
            @foreach (['valid' => 'Valid Documents', 'expiring_soon' => 'Segera kedaluwarsa', 'expired' => 'Kedaluwarsa', 'unknown' => 'Unknown Expiry'] as $key => $label)<x-dashboard.stat-card wire:key="document-stat-{{ $key }}" :label="$label" :value="$this->documentSummary[$key]" icon="document" description="Dokumen current sesuai filter" />@endforeach
        </section>
        <x-dashboard.report-table kind="vessels" :report="$this->report" :rows="$this->rows('vessels')" :filters="$applied" />
        <form novalidate wire:submit="applyFilters('documents')" class="report-controls mt-6 grid gap-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5 sm:grid-cols-2 xl:grid-cols-3">
        <x-form-errors id="report-2-errors" :show="$errorForm === 'documents'" />
            <label class="text-xs">Document Type<select wire:model="filters.document_type" class="mt-1 block w-full rounded-lg border border-slate-200 dark:border-slate-700 p-2"><option value="">Semua dokumen</option>@foreach (VesselDocument::TYPES as $key => $label)<option wire:key="doc-type-{{ $key }}" value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
            <label class="text-xs">Document Status<select wire:model="filters.document_status" class="mt-1 block w-full rounded-lg border border-slate-200 dark:border-slate-700 p-2"><option value="">Semua status</option>@foreach (['valid', 'expiring_soon', 'expired', 'unknown'] as $status)<option wire:key="doc-status-{{ $status }}" value="{{ $status }}">{{ $status }}</option>@endforeach</select></label>
            <label class="text-xs">Current / Version<select wire:model="filters.document_scope" class="mt-1 block w-full rounded-lg border border-slate-200 dark:border-slate-700 p-2"><option value="current">Current</option><option value="versions">Versions</option><option value="all">Current &amp; Versions</option></select></label>
            <x-dashboard.filter-field name="report_expiry_from" label="Expiry From" type="date" wire:model="filters.expiry_from" />
            <x-dashboard.filter-field name="report_expiry_to" label="Expiry To" type="date" wire:model="filters.expiry_to" />
            <button class="self-end rounded-lg bg-blue-500 px-4 py-2 text-xs text-white">Apply Document Filter</button>
        </form>
        <p class="mt-2 text-[11px] text-slate-500 dark:text-slate-400">Status expiry mengikuti master dokumen dan peringatan {{ config('vessels.expiration_warning_days', 30) }} hari. Versi arsip tidak dihitung sebagai dokumen aktif.</p>
        <x-dashboard.report-table kind="documents" :report="$this->report" :rows="$this->rows('documents')" :filters="$applied" />
    @elseif ($tab === 'staff')
        <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">Perlu perhatian untuk staf mencakup layanan yang perlu perhatian.</p>
        <x-dashboard.report-table kind="staff" :report="$this->report" :rows="$this->rows('staff')" :filters="$applied" />
    @endif
</x-dashboard.shell>
