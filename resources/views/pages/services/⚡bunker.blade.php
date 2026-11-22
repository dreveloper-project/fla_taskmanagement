<?php

use App\Models\BunkerService;
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

new #[Title('Izin Bunker')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $vessel = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    public function boot(): void
    {
        Gate::authorize('access-system');
    }

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, BunkerService> */
    #[Computed]
    public function services(): LengthAwarePaginator
    {
        $query = app(ServiceListFilters::class)->apply(BunkerService::with(['vessel', 'assignedStaff']), $this->filters);

        return $query->latest('id')->paginate(10);
    }

    /** @return array<string, string> */
    #[Computed]
    public function filters(): array
    {
        return [
            'search' => $this->search, 'status' => $this->status, 'vessel' => $this->vessel,
            'dateFrom' => $this->dateFrom, 'dateTo' => $this->dateTo,
        ];
    }

    #[Computed]
    public function completedArchiveCount(): int
    {
        return app(ServiceListFilters::class)->apply(BunkerService::query()->where('status', 'completed'), $this->filters)->count();
    }

    /** @return Collection<int, Vessel> */
    #[Computed]
    public function vessels(): Collection
    {
        return Vessel::orderBy('vessel_name')->get(['id', 'vessel_name']);
    }

    /** @return array<string, int> */
    #[Computed]
    public function summary(): array
    {
        $counts = BunkerService::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'Service Aktif' => (int) $counts->only(BunkerService::ACTIVE)->sum(), 'Draft' => (int) $counts->get('draft', 0),
            'Siap Diajukan' => (int) $counts->get('ready_to_apply', 0), 'Dalam Review' => (int) $counts->get('under_review', 0),
            'Perlu Revisi' => (int) $counts->get('revision_required', 0), 'Disetujui' => (int) $counts->get('approved', 0),
            'Bunker Terjadwal' => (int) $counts->get('bunker_scheduled', 0), 'Selesai' => (int) $counts->get('completed', 0),
        ];
    }
};
?>

<x-dashboard.shell page="Izin Bunker" :demo="false">
    <div class="flex flex-wrap items-center justify-between gap-4"><div><h1 class="text-2xl font-semibold">Izin Bunker</h1><p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Monitoring pengurusan izin dan pelaksanaan bunker kapal.</p></div>@can('manage-services')<x-dashboard.add-button :href="route('bunker.create')">Buat Baru</x-dashboard.add-button>@endcan</div>
    <section class="mt-4 grid grid-cols-2 gap-2 md:grid-cols-4" aria-label="Ringkasan Izin Bunker">
        @foreach ($this->summary as $label => $count)<div wire:key="bunker-summary-{{ $loop->index }}"><x-dashboard.stat-card :compact="true" :label="$label" :value="$count" icon="document" description="Service tersimpan" /></div>@endforeach
    </section>
    <x-dashboard.service-filters :columns="3">
        <x-dashboard.filter-field name="search" label="Pencarian" type="search" maxlength="255" wire:model.live.debounce.300ms="search" placeholder="Service, Vessel, Supplier..." />
        <x-dashboard.filter-field name="status" label="Status" type="select" wire:model.live="status"><option value="">Semua status</option><option value="active">Active</option><option value="attention">Need Attention</option>@foreach (\App\Models\BunkerService::STATUSES as $key => $label)<option wire:key="bunker-filter-status-{{ $key }}" value="{{ $key }}">{{ $label }}</option>@endforeach</x-dashboard.filter-field>
        <x-dashboard.filter-field name="vessel" label="Vessel" type="select" wire:model.live="vessel"><option value="">Semua Vessel</option>@foreach ($this->vessels as $item)<option wire:key="bunker-filter-vessel-{{ $item->id }}" value="{{ $item->id }}">{{ $item->vessel_name }}</option>@endforeach</x-dashboard.filter-field>
        <x-dashboard.filter-field name="dateFrom" label="Jadwal dari (WITA)" type="date" wire:model.live="dateFrom" />
        <x-dashboard.filter-field name="dateTo" label="Jadwal sampai (WITA)" type="date" wire:model.live="dateTo" />
    </x-dashboard.service-filters>
    <p wire:loading class="mt-3 text-xs text-blue-600 dark:text-blue-400">Memuat...</p>
    <section class="mt-6 overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900">
        <x-dashboard.completed-service-archives type="bunker" :filters="$this->filters" :count="$this->completedArchiveCount" :total="$this->services->total()" :error="session('completed_archive_error')" />
        <div class="overflow-x-auto"><table class="w-full text-left text-xs">
            <thead class="bg-slate-50 dark:bg-slate-800 text-slate-500 dark:text-slate-400"><tr>@foreach (['Service Number', 'Vessel', 'Pemasok', 'Bunker Location', 'Scheduled Date (WITA)', 'Fuel / Bunker Type', 'Jumlah', 'PIC', 'Status', 'Last Update', 'Action'] as $label)<th wire:key="bunker-column-{{ $loop->index }}" class="whitespace-nowrap px-4 py-3 font-medium">{{ $label }}</th>@endforeach</tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                @forelse ($this->services as $service)
                    <tr wire:key="bunker-row-{{ $service->id }}">
                        <td class="whitespace-nowrap px-4 py-4 font-semibold text-blue-600 dark:text-blue-400"><a href="{{ route('bunker.show', $service) }}">{{ $service->service_number }}</a></td>
                        <td class="px-4 py-4">{{ $service->vessel->vessel_name }}</td>
                        <td class="px-4 py-4">{{ $service->supplier ?? '-' }}</td><td class="px-4 py-4">{{ $service->bunker_location ?? '-' }}</td>
                        <td class="whitespace-nowrap px-4 py-4">{{ $service->scheduled_at?->timezone(config('bunker.timezone'))->format('d M Y H:i') ?? '-' }}</td>
                        <td class="px-4 py-4">{{ $service->bunker_type ?? '-' }}</td><td class="whitespace-nowrap px-4 py-4">{{ $service->quantity ?? '-' }} {{ $service->unitLabel() }}</td>
                        <td class="px-4 py-4">{{ $service->assignedStaff->name }}</td>
                        <td class="px-4 py-4"><x-dashboard.bunker-status :status="$service->status" /></td>
                        <td class="whitespace-nowrap px-4 py-4">{{ $service->updated_at->timezone(config('bunker.timezone'))->format('d M Y H:i') }}</td>
                        <td class="px-4 py-4"><div class="flex items-center gap-3 whitespace-nowrap"><a href="{{ route('bunker.show', $service) }}" class="font-semibold text-blue-600 dark:text-blue-400">Detail</a><x-dashboard.service-archive-download :service="$service" :compact="true" /></div></td>
                    </tr>
                @empty<tr><td colspan="11" class="p-8 text-center text-slate-500 dark:text-slate-400">Belum ada service yang sesuai.</td></tr>@endforelse
            </tbody>
        </table></div>
        <div class="border-t border-slate-100 dark:border-slate-700 p-4">{{ $this->services->links() }}</div>
    </section>
</x-dashboard.shell>
