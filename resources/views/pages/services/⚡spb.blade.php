<?php

use App\Models\SpbService;
use App\Models\Vessel;
use App\Services\SpbServiceFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Surat Persetujuan Berlayar')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $vessel = '';

    #[Url]
    public string $vesselType = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, SpbService> */
    #[Computed]
    public function services(): LengthAwarePaginator
    {
        $query = app(SpbServiceFilters::class)->apply(SpbService::with(['vessel', 'assignedStaff', 'creator']), $this->filters);

        return $query->latest('id')->paginate(10);
    }

    /** @return array{search: string, status: string, vessel: string, vesselType: string, dateFrom: string, dateTo: string} */
    #[Computed]
    public function filters(): array
    {
        return [
            'search' => $this->search, 'status' => $this->status, 'vessel' => $this->vessel,
            'vesselType' => $this->vesselType,
            'dateFrom' => $this->dateFrom, 'dateTo' => $this->dateTo,
        ];
    }

    #[Computed]
    public function completedArchiveCount(): int
    {
        return app(SpbServiceFilters::class)->apply(SpbService::query()->where('status', 'completed'), $this->filters)->count();
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
        $counts = SpbService::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'SPB Aktif' => (int) $counts->only(SpbService::ACTIVE)->sum(),
            'Draft' => (int) $counts->get('draft', 0),
            'Siap Diajukan' => (int) $counts->get('ready_to_apply', 0),
            'Dalam Review' => (int) $counts->get('applied', 0),
            'Perlu Revisi' => (int) $counts->get('revision_required', 0),
            'Siap Berlayar' => (int) $counts->get('ready_to_sail', 0),
            'Selesai' => (int) $counts->get('completed', 0),
        ];
    }
};
?>

<x-dashboard.shell page="Surat Persetujuan Berlayar" :demo="false">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div><p class="text-xs text-slate-500 dark:text-slate-400">Layanan / Surat Persetujuan Berlayar</p><h1 class="mt-2 text-2xl font-semibold">Surat Persetujuan Berlayar</h1><p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Monitoring seluruh pengurusan SPB kapal</p></div>
        @can('manage-services')<x-dashboard.add-button :href="route('spb.create')">Buat Baru</x-dashboard.add-button>@endcan
    </div>
    @if (session('spb_status'))<p data-form-success role="status" class="mt-5 rounded-lg bg-emerald-50 dark:bg-emerald-950/40 p-4 text-sm text-emerald-700 dark:text-emerald-300">{{ session('spb_status') }}</p>@endif
    <div class="mt-4 grid grid-cols-2 gap-2 sm:mt-6 sm:gap-3 xl:grid-cols-7">
        @foreach ($this->summary as $label => $total)
            <div wire:key="spb-summary-{{ $loop->index }}" class="min-w-0 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-3 sm:p-4"><p class="text-[11px] leading-4 text-slate-500 dark:text-slate-400 wrap-break-word sm:text-xs">{{ $label }}</p><p class="mt-1 text-xl font-semibold tabular-nums sm:mt-2 sm:text-2xl">{{ $total }}</p></div>
        @endforeach
    </div>
    <x-dashboard.service-filters :columns="4">
        <x-dashboard.filter-field name="search" label="Pencarian" type="search" wide wire:model.live.debounce.350ms="search" maxlength="255" placeholder="Nomor service, vessel, tujuan" />
        <x-dashboard.filter-field name="spb-status-filter" label="Status / History" type="select" wire:model.live="status"><option value="">Semua status</option><option value="pending">SPB Pending</option>@foreach (\App\Models\SpbService::STATUSES as $key => $label)<option wire:key="filter-status-{{ $key }}" value="{{ $key }}">{{ $label }}</option>@endforeach</x-dashboard.filter-field>
        <x-dashboard.filter-field name="spb-vessel-filter" label="Vessel" type="select" wire:model.live="vessel"><option value="">Semua vessel</option>@foreach ($this->vessels as $item)<option wire:key="filter-vessel-{{ $item->id }}" value="{{ $item->id }}">{{ $item->vessel_name }}</option>@endforeach</x-dashboard.filter-field>
        <x-dashboard.filter-field name="spb-type-filter" label="Vessel Type" type="select" wire:model.live="vesselType"><option value="">Semua jenis kapal</option>@foreach (\App\Models\Vessel::TYPES as $key => $label)<option wire:key="filter-type-{{ $key }}" value="{{ $key }}">{{ $label }}</option>@endforeach</x-dashboard.filter-field>
        <x-dashboard.filter-field name="dateFrom" label="Estimated Departure dari (WITA)" type="date" wire:model.live="dateFrom" />
        <x-dashboard.filter-field name="dateTo" label="Sampai tanggal (WITA)" type="date" wire:model.live="dateTo" />
    </x-dashboard.service-filters>
    <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900">
        <x-dashboard.completed-service-archives type="spb" title="Daftar SPB" :filters="$this->filters" :count="$this->completedArchiveCount" :total="$this->services->total()" :error="session('spb_archive_error')" />
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-slate-50 dark:bg-slate-800 text-slate-500 dark:text-slate-400"><tr>@foreach (['Service Number', 'Vessel', 'Vessel Type', 'Departure Port', 'Destination', 'Estimated Departure (WITA)', 'PIC', 'Status', 'Last Update (WITA)', 'Action'] as $label)<th wire:key="spb-col-{{ $loop->index }}" class="whitespace-nowrap px-4 py-3 font-medium">{{ $label }}</th>@endforeach</tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                    @forelse ($this->services as $spb)
                        <tr wire:key="spb-{{ $spb->id }}">
                            <td class="whitespace-nowrap px-4 py-4 font-semibold text-blue-600 dark:text-blue-400"><a href="{{ route('spb.show', $spb) }}">{{ $spb->service_number }}</a></td>
                            <td class="px-4 py-4">{{ $spb->vessel->vessel_name }}</td><td class="px-4 py-4">{{ $spb->vessel->typeLabel() }}</td>
                            <td class="px-4 py-4">{{ $spb->departure_port }}</td><td class="px-4 py-4">{{ $spb->destination }}</td>
                            <td class="whitespace-nowrap px-4 py-4">{{ $spb->estimated_departure_at->setTimezone(config('spb.timezone'))->format('d M Y H:i') }}</td>
                            <td class="px-4 py-4">{{ $spb->assignedStaff?->name ?? $spb->creator->name }}</td>
                            <td class="px-4 py-4"><x-dashboard.spb-status :status="$spb->status" /></td>
                            <td class="whitespace-nowrap px-4 py-4">{{ $spb->updated_at->setTimezone(config('spb.timezone'))->format('d M Y H:i') }}</td>
                            <td class="px-4 py-4"><div class="flex items-center gap-3 whitespace-nowrap"><a href="{{ route('spb.show', $spb) }}" class="font-medium text-blue-600 dark:text-blue-400">Detail</a><x-dashboard.service-archive-download :service="$spb" :compact="true" /></div></td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="px-4 py-12 text-center text-slate-500 dark:text-slate-400">Tidak ada SPB sesuai pencarian.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $this->services->links() }}</div>
    </div>
</x-dashboard.shell>
