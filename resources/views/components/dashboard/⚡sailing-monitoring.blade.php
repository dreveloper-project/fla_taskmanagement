<?php

use App\Models\Vessel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public function boot(): void
    {
        Gate::authorize('access-system');
    }

    #[On('vessel-status-updated')]
    public function refreshSailingVessels(): void
    {
        unset($this->sailingVessels);
    }

    /** @return LengthAwarePaginator<int, Vessel> */
    #[Computed]
    public function sailingVessels(): LengthAwarePaginator
    {
        $query = Vessel::query()
            ->where('operational_status', 'sailing')
            ->with([
                'spbServices' => fn (HasMany $query): HasMany => $query
                    ->where('status', 'sailing')
                    ->whereNotNull('actual_departure_at')
                    ->latest('actual_departure_at')->latest('id')->limit(1)
                    ->with('assignedStaff'),
            ])
            ->orderByDesc('sailed_at')->orderByDesc('id');

        $vessels = $query->paginate(6, pageName: 'sailingPage');
        if ($vessels->currentPage() > $vessels->lastPage()) {
            $this->setPage($vessels->lastPage(), 'sailingPage');
            $vessels = $query->paginate(6, pageName: 'sailingPage');
        }

        return $vessels;
    }
};
?>

<section wire:poll.5s="refreshSailingVessels" aria-labelledby="sailing-heading" class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs dark:border-slate-700 dark:bg-slate-900">
    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 bg-linear-to-r from-blue-50/80 to-white p-5 dark:border-slate-800 dark:from-blue-950/40 dark:to-slate-900">
        <div class="flex items-center gap-3">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-blue-100 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400"><x-dashboard.icon name="ship" class="size-5" /></span>
            <div>
                <div class="flex items-center gap-2"><h2 id="sailing-heading" class="text-sm font-semibold">Kapal sedang berlayar</h2><span class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-700 dark:bg-blue-500/15 dark:text-blue-300">{{ $this->sailingVessels->total() }}</span></div>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Pantau status dan informasi keberangkatan armada.</p>
            </div>
        </div>
        <a href="{{ route('dashboard.vessel') }}" wire:navigate class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-blue-300 hover:text-blue-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:border-blue-500 dark:hover:text-blue-300">Lihat armada <x-dashboard.icon name="arrow" class="size-3.5" /></a>
    </div>
    <div class="p-5">
        <div wire:offline class="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">Koneksi terputus. Data akan diperbarui setelah terhubung kembali.</div>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($this->sailingVessels as $vessel)
                @php
                    $voyage = $vessel->spbServices->first();
                    $matchesDeparture = $vessel->sailed_at !== null
                        ? ($voyage?->actual_departure_at?->equalTo($vessel->sailed_at) ?? false)
                        : $voyage?->status === 'sailing';
                    $voyage = $matchesDeparture ? $voyage : null;
                    $departedAt = $vessel->sailed_at ?? $voyage?->actual_departure_at;
                @endphp
                <article wire:key="sailing-vessel-{{ $vessel->id }}" class="flex min-w-0 flex-col gap-4 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            @if ($vessel->profile_picture_path)
                                <img src="{{ route('vessels.picture', $vessel) }}" alt="Foto {{ $vessel->vessel_name }}" loading="lazy" class="size-10 shrink-0 rounded-lg object-cover" />
                            @else
                                <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-400 dark:bg-slate-800"><x-dashboard.icon name="ship" class="size-5" /></span>
                            @endif
                            <div class="min-w-0"><h3 class="text-sm font-semibold wrap-break-word">{{ $vessel->vessel_name }}</h3><p class="mt-1 text-xs text-slate-500 dark:text-slate-400 wrap-break-word">{{ $vessel->ownerName() }}</p></div>
                        </div>
                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-blue-50 px-2 py-1 text-[10px] font-semibold text-blue-700 dark:bg-blue-500/10 dark:text-blue-300"><span class="size-1.5 rounded-full bg-blue-500"></span>Berlayar</span>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/70">
                        <p class="text-[10px] font-medium tracking-wide text-slate-400 uppercase">Rute pelayaran</p>
                        @if ($voyage)
                            <div class="mt-2 flex items-center gap-2 text-xs font-semibold"><span class="min-w-0 flex-1 wrap-break-word">{{ $voyage->departure_port }}</span><x-dashboard.icon name="arrow" class="size-4 text-blue-500" /><span class="min-w-0 flex-1 text-right wrap-break-word">{{ $voyage->destination }}</span></div>
                        @else
                            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Informasi rute belum tersedia.</p>
                        @endif
                    </div>
                    <dl class="grid gap-3 text-xs">
                        <div class="flex items-start justify-between gap-3"><dt class="text-slate-500 dark:text-slate-400">Berangkat</dt><dd class="text-right font-medium">@if ($departedAt)<time datetime="{{ $departedAt->toIso8601String() }}">{{ $departedAt->timezone(config('spb.timezone'))->format('d M Y H:i') }} WITA</time>@else Belum tercatat @endif</dd></div>
                        <div class="flex items-start justify-between gap-3"><dt class="text-slate-500 dark:text-slate-400">Perkiraan tiba (ETA)</dt><dd class="text-right font-medium">@if ($voyage?->expected_arrival_at)<time datetime="{{ $voyage->expected_arrival_at->toIso8601String() }}">{{ $voyage->expected_arrival_at->timezone(config('spb.timezone'))->format('d M Y H:i') }} WITA</time>@else Belum tercatat @endif</dd></div>
                        <div class="flex items-start justify-between gap-3"><dt class="text-slate-500 dark:text-slate-400">PIC</dt><dd class="text-right font-medium">{{ $voyage?->assignedStaff?->name ?? 'Belum ditentukan' }}</dd></div>
                    </dl>
                    <div class="mt-auto flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
                        <a href="{{ route('dashboard.vessel', ['vessel' => $vessel->id]) }}" wire:navigate class="rounded-lg px-2 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">Detail kapal</a>
                        @if ($voyage)
                            <a href="{{ route('spb.show', $voyage) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg bg-blue-50 px-2.5 py-1.5 text-xs font-semibold text-blue-600 hover:bg-blue-100 dark:bg-blue-500/10 dark:text-blue-300 dark:hover:bg-blue-500/20">Detail SPB <x-dashboard.icon name="arrow" class="size-3.5" /></a>
                        @endif
                    </div>
                </article>
            @empty
                <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-slate-200 py-8 text-center md:col-span-2 xl:col-span-3 dark:border-slate-700">
                    <span class="flex size-12 items-center justify-center rounded-full bg-slate-50 text-slate-400 dark:bg-slate-800"><x-dashboard.icon name="anchor" class="size-6" /></span>
                    <p class="text-sm font-medium">Belum ada kapal yang sedang berlayar.</p>
                    <p class="px-4 text-xs text-slate-500 dark:text-slate-400">Kapal akan tampil saat statusnya berubah menjadi Berlayar.</p>
                </div>
            @endforelse
        </div>
        @if ($this->sailingVessels->hasPages())
            <div class="mt-5">{{ $this->sailingVessels->links() }}</div>
        @endif
        <p class="mt-4 flex items-center gap-1.5 text-[10px] text-slate-400"><x-dashboard.icon name="clock" class="size-3" />Diperbarui otomatis setiap 5 detik.</p>
    </div>
</section>
