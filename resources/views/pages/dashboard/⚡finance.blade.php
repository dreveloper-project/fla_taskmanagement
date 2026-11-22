<?php

use App\Models\SpbService;
use App\Models\Vessel;
use App\Services\XlsxReportWriter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Finance')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $billingType = '';

    public string $phase = '';

    public string $vessel = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public function boot(): void
    {
        Gate::authorize('access-finance');
    }

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'billingType', 'phase', 'vessel', 'dateFrom', 'dateTo');
        $this->resetPage();
    }

    /** @return array<string, string> */
    #[Computed]
    public function codeColumns(): array
    {
        $columns = [
            'billing_code_labuh' => ['Labuh', 'departure', 'labuh'],
            'billing_code_rambu' => ['Rambu', 'departure', 'rambu'],
            'billing_code_mastercable' => ['Master Cable', 'departure', 'mastercable'],
            'arrival_billing_code_labuh' => ['Labuh', 'arrival', 'labuh'],
            'arrival_billing_code_rambu' => ['Rambu', 'arrival', 'rambu'],
            'arrival_billing_code_mastercable' => ['Master Cable', 'arrival', 'mastercable'],
        ];
        $selected = [];
        foreach ($columns as $column => [$label, $phase, $type]) {
            if (($this->phase === '' || $this->phase === $phase) && ($this->billingType === '' || $this->billingType === $type)) {
                $selected[$column] = ($phase === 'arrival' ? 'Kedatangan' : 'Keberangkatan').' · '.$label;
            }
        }

        return $selected;
    }

    /** @return Collection<int, Vessel> */
    #[Computed]
    public function vessels(): Collection
    {
        return Vessel::query()->orderBy('vessel_name')->get(['id', 'vessel_name']);
    }

    /** @return LengthAwarePaginator<int, SpbService> */
    #[Computed]
    public function services(): LengthAwarePaginator
    {
        return $this->billingQuery()->paginate(10);
    }

    public function exportExcel(XlsxReportWriter $writer): StreamedResponse
    {
        Gate::authorize('access-finance');
        $columns = $this->codeColumns;
        $query = $this->billingQuery();

        return response()->streamDownload(function () use ($writer, $columns, $query): void {
            $rows = $query->lazy(250)->map(function (SpbService $service) use ($columns): array {
                $row = [
                    $service->service_number, $service->vessel->vessel_name,
                    $service->estimated_departure_at->timezone(config('spb.timezone'))->format('d-m-Y H:i'),
                    $service->statusLabel(),
                ];
                foreach ($columns as $column => $label) {
                    $row[] = $service->$column;
                }

                return $row;
            });
            $writer->stream(['Nomor SPB', 'Vessel', 'Perkiraan keberangkatan (WITA)', 'Status', ...array_values($columns)], $rows);
        }, 'rekap-kode-billing.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return Builder<SpbService> */
    private function billingQuery(): Builder
    {
        $columns = array_keys($this->codeColumns);
        $query = SpbService::query()->with('vessel:id,vessel_name');
        $valid = Validator::make([
            'dateFrom' => $this->dateFrom ?: null, 'dateTo' => $this->dateTo ?: null,
            'vessel' => $this->vessel ?: null,
        ], [
            'dateFrom' => ['nullable', 'date_format:Y-m-d'], 'dateTo' => ['nullable', 'date_format:Y-m-d'],
            'vessel' => ['nullable', 'integer', 'min:1'],
        ])->passes();

        if (! $valid || $columns === [] || ($this->dateFrom && $this->dateTo && $this->dateFrom > $this->dateTo)) {
            return $query->whereRaw('1 = 0')->orderByDesc('estimated_departure_at')->orderByDesc('id');
        }

        $query->where(function (Builder $query) use ($columns): void {
            foreach ($columns as $column) {
                $query->orWhere(fn (Builder $query) => $query->whereNotNull($column)->where($column, '!=', ''));
            }
        });
        if ($this->vessel !== '') {
            $query->where('vessel_id', (int) $this->vessel);
        }
        if ($this->dateFrom !== '') {
            $query->where('estimated_departure_at', '>=', CarbonImmutable::parse($this->dateFrom, config('spb.timezone'))->startOfDay()->utc());
        }
        if ($this->dateTo !== '') {
            $query->where('estimated_departure_at', '<', CarbonImmutable::parse($this->dateTo, config('spb.timezone'))->startOfDay()->addDay()->utc());
        }
        if (trim($this->search) !== '') {
            $search = '%'.trim($this->search).'%';
            $query->where(function (Builder $query) use ($columns, $search): void {
                $query->where('service_number', 'like', $search)
                    ->orWhereHas('vessel', fn (Builder $query) => $query->where('vessel_name', 'like', $search));
                foreach ($columns as $column) {
                    $query->orWhere($column, 'like', $search);
                }
            });
        }

        return $query->orderByDesc('estimated_departure_at')->orderByDesc('id');
    }
};
?>

<x-dashboard.shell page="Finance" :demo="false">
    <h1 class="text-2xl font-semibold">Finance</h1>
    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Rekap kode billing PNBP yang dicatat pada layanan SPB.</p>
    <section class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-xs dark:border-slate-700 dark:bg-slate-900 dark:shadow-none" aria-labelledby="billing-filter-heading">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 dark:border-slate-800 sm:px-5">
            <div><h2 id="billing-filter-heading" class="text-sm font-semibold">Pencarian &amp; filter</h2><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Filter rekap berdasarkan SPB dan kode billing.</p></div>
            <button type="button" wire:click="resetFilters" class="rounded-lg px-3 py-2 text-xs font-semibold text-blue-600 hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-blue-950/40">Reset filter</button>
        </div>
        <div class="grid gap-4 p-4 sm:grid-cols-2 sm:p-5 lg:grid-cols-6">
            <div class="sm:col-span-2 lg:col-span-6"><x-dashboard.filter-field name="billing-search" label="Pencarian" type="search" wire:model.live.debounce.350ms="search" maxlength="255" placeholder="Nomor SPB, vessel, atau kode billing" /></div>
            <div class="lg:col-span-2"><x-dashboard.filter-field name="billing-type" label="Jenis kode billing" type="select" wire:model.live="billingType"><option value="">Semua jenis</option><option value="labuh">Labuh</option><option value="rambu">Rambu</option><option value="mastercable">Master Cable</option></x-dashboard.filter-field></div>
            <div class="lg:col-span-2"><x-dashboard.filter-field name="billing-phase" label="Tahap" type="select" wire:model.live="phase"><option value="">Semua tahap</option><option value="departure">Keberangkatan</option><option value="arrival">Kedatangan</option></x-dashboard.filter-field></div>
            <div class="sm:col-span-2 lg:col-span-2"><x-dashboard.filter-field name="billing-vessel" label="Vessel" type="select" wire:model.live="vessel"><option value="">Semua vessel</option>@foreach ($this->vessels as $item)<option wire:key="billing-vessel-{{ $item->id }}" value="{{ $item->id }}">{{ $item->vessel_name }}</option>@endforeach</x-dashboard.filter-field></div>
            <div class="lg:col-span-3"><x-dashboard.filter-field name="billing-date-from" label="Perkiraan keberangkatan dari (WITA)" type="date" wire:model.live="dateFrom" /></div>
            <div class="lg:col-span-3"><x-dashboard.filter-field name="billing-date-to" label="Sampai tanggal (WITA)" type="date" wire:model.live="dateTo" /></div>
            @if ($dateFrom && $dateTo && $dateFrom > $dateTo)<p role="alert" class="text-xs text-rose-600 dark:text-rose-400 sm:col-span-2 lg:col-span-6">Tanggal akhir harus sama dengan atau setelah tanggal awal.</p>@endif
        </div>
    </section>
    <section class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
        <div class="flex flex-wrap items-center justify-between gap-3 p-4"><div><h2 class="text-base font-semibold">Rekap kode billing</h2><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $this->services->total() }} SPB sesuai filter</p></div><button type="button" wire:click="exportExcel" wire:loading.attr="disabled" wire:target="exportExcel" class="rounded-lg bg-blue-500 px-4 py-2.5 text-xs font-semibold text-white hover:bg-blue-600 disabled:opacity-50">Export Excel</button></div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-slate-50 text-slate-500 dark:bg-slate-800 dark:text-slate-400"><tr>@foreach (['SPB', 'Vessel', 'Perkiraan keberangkatan (WITA)', 'Status', 'Kode billing'] as $label)<th class="whitespace-nowrap px-4 py-3 font-medium">{{ $label }}</th>@endforeach</tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                    @forelse ($this->services as $service)
                        <tr wire:key="billing-service-{{ $service->id }}">
                            <td class="whitespace-nowrap px-4 py-4 font-semibold text-blue-600 dark:text-blue-400"><a href="{{ route('spb.show', $service) }}">{{ $service->service_number }}</a></td>
                            <td class="px-4 py-4">{{ $service->vessel->vessel_name }}</td>
                            <td class="whitespace-nowrap px-4 py-4">{{ $service->estimated_departure_at->timezone(config('spb.timezone'))->format('d M Y H:i') }}</td>
                            <td class="px-4 py-4"><x-dashboard.spb-status :status="$service->status" /></td>
                            <td class="px-4 py-4"><dl class="space-y-2">@foreach ($this->codeColumns as $column => $label) @if (filled($service->$column))<div><dt class="text-slate-500 dark:text-slate-400">{{ $label }}</dt><dd class="break-all font-medium">{{ $service->$column }}</dd></div>@endif @endforeach</dl></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-12 text-center text-slate-500 dark:text-slate-400">Tidak ada kode billing sesuai filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $this->services->links() }}</div>
    </section>
</x-dashboard.shell>
