<?php

use App\Models\ShipNationalityService;
use App\Models\Vessel;
use App\Services\ServiceListFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Surat Laut / Pas Besar / Pas Kecil')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $vessel = '';

    #[Url]
    public string $requestType = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    #[Url]
    public string $documentType = '';

    public function boot(): void
    {
        Gate::authorize('access-system');
    }

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, ShipNationalityService> */
    #[Computed]
    public function services(): LengthAwarePaginator
    {
        $query = app(ServiceListFilters::class)->apply(ShipNationalityService::with(['vessel.documents', 'assignedStaff']), $this->filters);

        return $query->latest('id')->paginate(10);
    }

    /** @return array<string, string> */
    #[Computed]
    public function filters(): array
    {
        return [
            'search' => $this->search, 'status' => $this->status, 'vessel' => $this->vessel,
            'dateFrom' => $this->dateFrom, 'dateTo' => $this->dateTo,
            'requestType' => $this->requestType,
            'documentType' => $this->documentType,
        ];
    }

    #[Computed]
    public function completedArchiveCount(): int
    {
        return app(ServiceListFilters::class)->apply(ShipNationalityService::query()->where('status', 'completed'), $this->filters)->count();
    }

    /** @return Collection<int, Vessel> */
    #[Computed]
    public function vessels(): Collection
    {
        return Vessel::query()->orderBy('vessel_name')->get(['id', 'vessel_name']);
    }

    /** @return array<string, int> */
    #[Computed]
    public function summary(): array
    {
        $counts = ShipNationalityService::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'Service Aktif' => (int) $counts->only(ShipNationalityService::ACTIVE)->sum(),
            'Draft' => (int) $counts->get('draft', 0),
            'Siap Diajukan' => (int) $counts->get('ready_to_apply', 0),
            'Dalam Review' => (int) $counts->get('under_review', 0),
            'Perlu Revisi' => (int) $counts->get('revision_required', 0),
            'Siap Diserahkan' => (int) $counts->get('ready_for_handover', 0),
            'Selesai' => (int) $counts->get('completed', 0),
        ];
    }
};
?>

<x-dashboard.shell page="Surat Laut / Pas Besar / Pas Kecil" :demo="false">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div><p class="text-xs text-slate-500 dark:text-slate-400">Layanan / Dokumen Kebangsaan</p><h1 class="mt-2 text-2xl font-semibold">Surat Laut / Pas Besar / Pas Kecil</h1><p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Monitoring penerbitan dan perpanjangan dokumen kebangsaan kapal.</p></div>
        @can('manage-services')<x-dashboard.add-button :href="route('ship-nationality.create')">Buat Baru</x-dashboard.add-button>@endcan
    </div>
    @if (session('nationality_status'))<p data-form-success role="status" class="mt-5 rounded-lg bg-emerald-50 dark:bg-emerald-950/40 p-4 text-sm text-emerald-700 dark:text-emerald-300">{{ session('nationality_status') }}</p>@endif
    <div class="mt-4 grid grid-cols-2 gap-2 sm:mt-6 sm:gap-3 xl:grid-cols-4">
        @foreach ($this->summary as $label => $total)<div wire:key="nationality-summary-{{ $loop->index }}" class="min-w-0 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-3 sm:p-4"><p class="text-[11px] leading-4 text-slate-500 dark:text-slate-400 wrap-break-word sm:text-xs">{{ $label }}</p><p class="mt-1 text-xl font-semibold tabular-nums sm:mt-2 sm:text-2xl">{{ $total }}</p></div>@endforeach
    </div>
    <x-dashboard.service-filters :columns="4">
        <x-dashboard.filter-field name="search" label="Pencarian" type="search" maxlength="255" wire:model.live.debounce.350ms="search" placeholder="Service, vessel, sertifikat" />
        <x-dashboard.filter-field name="status" label="Status" type="select" wire:model.live="status"><option value="">Semua status</option><option value="active">Active</option><option value="attention">Need Attention</option>@foreach (\App\Models\ShipNationalityService::STATUSES as $key => $label)<option wire:key="status-{{ $key }}" value="{{ $key }}">{{ $label }}</option>@endforeach</x-dashboard.filter-field>
        <x-dashboard.filter-field name="vessel" label="Vessel" type="select" wire:model.live="vessel"><option value="">Semua vessel</option>@foreach ($this->vessels as $item)<option wire:key="vessel-{{ $item->id }}" value="{{ $item->id }}">{{ $item->vessel_name }}</option>@endforeach</x-dashboard.filter-field>
        <x-dashboard.filter-field name="requestType" label="Request Type" type="select" wire:model.live="requestType"><option value="">Semua jenis</option>@foreach (\App\Models\ShipNationalityService::REQUEST_TYPES as $key => $label)<option wire:key="request-type-{{ $key }}" value="{{ $key }}">{{ $label }}</option>@endforeach</x-dashboard.filter-field>
        <x-dashboard.filter-field name="dateFrom" label="Created Date dari (WITA)" type="date" wire:model.live="dateFrom" />
        <x-dashboard.filter-field name="dateTo" label="Sampai tanggal (WITA)" type="date" wire:model.live="dateTo" />
        <x-dashboard.filter-field name="documentType" label="Document Type" type="select" wire:model.live="documentType"><option value="">Semua</option>@foreach (\App\Models\ShipNationalityService::DOCUMENT_TYPES as $key => $label)<option wire:key="filter-doc-{{ $key }}" value="{{ $key }}">{{ $label }}</option>@endforeach</x-dashboard.filter-field>
    </x-dashboard.service-filters>
    <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900">
        <x-dashboard.completed-service-archives type="ship-nationality" :filters="$this->filters" :count="$this->completedArchiveCount" :total="$this->services->total()" :error="session('completed_archive_error')" />
        <div class="overflow-x-auto"><table class="w-full text-left text-xs">
            <thead class="bg-slate-50 dark:bg-slate-800 text-slate-500 dark:text-slate-400"><tr>@foreach (['Service Number', 'Vessel', 'Document Type', 'Request Type', 'Current Expiry', 'PIC', 'Status', 'Created Date', 'Last Update', 'Action'] as $label)<th wire:key="nationality-col-{{ $loop->index }}" class="whitespace-nowrap px-4 py-3 font-medium">{{ $label }}</th>@endforeach</tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                @forelse ($this->services as $service)
                    <tr wire:key="nationality-service-{{ $service->id }}">
                        <td class="whitespace-nowrap px-4 py-4 font-semibold text-blue-600 dark:text-blue-400"><a href="{{ route('ship-nationality.show', $service) }}">{{ $service->service_number }}</a></td>
                        <td class="px-4 py-4">{{ $service->vessel->vessel_name }}</td>
                        <td class="px-4 py-4">{{ \App\Models\ShipNationalityService::DOCUMENT_TYPES[$service->document_type] }}</td>
                        <td class="px-4 py-4">{{ $service->request_type === 'renewal' ? 'RENEWAL' : 'NEW ISSUE' }}</td>
                        <td class="whitespace-nowrap px-4 py-4">{{ $service->vessel->documents->firstWhere('document_type', $service->document_type)?->expired_date?->format('d M Y') ?? '-' }}</td>
                        <td class="px-4 py-4">{{ $service->assignedStaff?->name ?? 'Belum ditentukan' }}</td>
                        <td class="px-4 py-4"><x-dashboard.ship-nationality-status :status="$service->status" /></td>
                        <td class="whitespace-nowrap px-4 py-4">{{ $service->created_at->timezone(config('ship-nationality.timezone'))->format('d M Y H:i') }}</td>
                        <td class="whitespace-nowrap px-4 py-4">{{ $service->updated_at->timezone(config('ship-nationality.timezone'))->format('d M Y H:i') }}</td>
                        <td class="px-4 py-4"><div class="flex items-center gap-3 whitespace-nowrap"><a href="{{ route('ship-nationality.show', $service) }}" class="text-blue-600 dark:text-blue-400">Detail</a><x-dashboard.service-archive-download :service="$service" :compact="true" /></div></td>
                    </tr>
                @empty<tr><td colspan="10" class="px-4 py-12 text-center text-slate-500 dark:text-slate-400">Tidak ada service sesuai pencarian.</td></tr>@endforelse
            </tbody>
        </table></div>
        <div class="p-4">{{ $this->services->links() }}</div>
    </div>
</x-dashboard.shell>

