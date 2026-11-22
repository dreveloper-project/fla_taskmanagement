<?php

use App\Models\BunkerService;
use App\Models\SafetyCertificateService;
use App\Models\ShipNationalityService;
use App\Models\SijilService;
use App\Models\SpbService;
use App\Models\VesselDocument;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public bool $showDocumentExpirySummary = false;

    public string $departureReportDate = '';

    public function boot(): void
    {
        \Illuminate\Support\Facades\Gate::authorize('access-system');
    }

    public function mount(): void
    {
        $this->departureReportDate = today(config('spb.timezone'))->toDateString();
        $this->showDocumentExpirySummary = (bool) session()->pull('show_document_expiry_summary', false);

        if ($this->showDocumentExpirySummary) {
            session()->forget('auth_status');
        }
    }

    /** @return Collection<int, ShipNationalityService> */
    #[Computed]
    public function activeNationalityServices(): Collection
    {
        return ShipNationalityService::with(['vessel', 'assignedStaff'])->whereIn('status', ShipNationalityService::ACTIVE)->latest('id')->limit(5)->get();
    }

    /** @return Collection<int, ShipNationalityService> */
    #[Computed]
    public function nationalityAttention(): Collection
    {
        return ShipNationalityService::with('vessel')->needsAttention()->latest('id')->limit(5)->get();
    }

    #[Computed]
    public function activeServicesCount(): int
    {
        return ShipNationalityService::whereIn('status', ShipNationalityService::ACTIVE)->count() + $this->activeSpbCount + SafetyCertificateService::whereIn('status', SafetyCertificateService::ACTIVE)->count() + BunkerService::whereIn('status', BunkerService::ACTIVE)->count() + SijilService::whereIn('status', SijilService::ACTIVE)->count();
    }

    /** @return Collection<int, VesselDocument> */
    #[Computed]
    public function certificateNotifications(): Collection
    {
        return VesselDocument::query()
            ->with('vessel:id,vessel_name')
            ->whereNotNull('expired_date')
            ->whereDate('expired_date', '<=', today(config('app.timezone'))->addDays(20)->toDateString())
            ->orderBy('expired_date')->orderBy('id')->get();
    }

    #[Computed]
    public function expiringDocumentsCount(): int
    {
        return VesselDocument::whereBetween('expired_date', [today(config('app.timezone'))->toDateString(), today(config('app.timezone'))->addDays((int) config('vessels.expiration_warning_days'))->toDateString()])->count();
    }

    #[Computed]
    public function expiredDocumentsCount(): int
    {
        return VesselDocument::whereDate('expired_date', '<', today(config('app.timezone'))->toDateString())->count();
    }

    #[Computed]
    public function needAttentionCount(): int
    {
        return ShipNationalityService::needsAttention()->count() + SafetyCertificateService::needsAttention()->count() + BunkerService::needsAttention()->count() + $this->sijilAttention->count() + SpbService::where('status', 'revision_required')->count() + $this->expiredDocumentsCount;
    }

    /** @return Collection<int, SafetyCertificateService> */
    #[Computed]
    public function activeSafetyServices(): Collection
    {
        return SafetyCertificateService::with(['vessel', 'assignedStaff'])->whereIn('status', SafetyCertificateService::ACTIVE)->latest('id')->limit(5)->get();
    }

    /** @return Collection<int, SafetyCertificateService> */
    #[Computed]
    public function safetyAttention(): Collection
    {
        return SafetyCertificateService::with('vessel')->needsAttention()->latest('id')->limit(5)->get();
    }

    /** @return Collection<int, SijilService> */
    #[Computed]
    public function sijilAttention(): Collection
    {
        return app(\App\Services\SijilReadinessService::class)->needingAttention();
    }

    /** @return Collection<int, SijilService> */
    #[Computed]
    public function activeSijilServices(): Collection
    {
        return SijilService::with(['vessel', 'assignedStaff'])->whereIn('status', SijilService::ACTIVE)->latest('id')->limit(5)->get();
    }

    /** @return Collection<int, BunkerService> */
    #[Computed]
    public function activeBunkerServices(): Collection
    {
        return BunkerService::with(['vessel', 'assignedStaff'])->whereIn('status', BunkerService::ACTIVE)->latest('id')->limit(5)->get();
    }

    /** @return Collection<int, BunkerService> */
    #[Computed]
    public function bunkerAttention(): Collection
    {
        return BunkerService::with('vessel')->needsAttention()->latest('id')->limit(5)->get();
    }

    #[Computed]
    public function activeSpbCount(): int
    {
        return SpbService::whereIn('status', SpbService::ACTIVE)->count();
    }

    /** @return Collection<int, SpbService> */
    #[Computed]
    public function activeSpb(): Collection
    {
        return SpbService::with(['vessel', 'assignedStaff'])->whereIn('status', SpbService::ACTIVE)->latest()->limit(5)->get();
    }

    /** @return Collection<int, SpbService> */
    #[Computed]
    public function departureReports(): Collection
    {
        if (Validator::make(['date' => $this->departureReportDate], ['date' => ['required', 'date_format:Y-m-d']])->fails()) {
            return new Collection;
        }

        $date = CarbonImmutable::parse($this->departureReportDate, config('spb.timezone'))->startOfDay();

        return SpbService::query()
            ->with('vessel:id,vessel_name')
            ->where('status', 'completed')
            ->where('estimated_departure_at', '>=', $date->utc())
            ->where('estimated_departure_at', '<', $date->addDay()->utc())
            ->orderBy('estimated_departure_at')->orderBy('id')->get();
    }
};
?>

<x-dashboard.shell :demo="false">
    @if ($showDocumentExpirySummary)
        @php
            $summaryToday = today(config('app.timezone'))->toDateString();
            $summaryExpired = $this->certificateNotifications->filter(fn ($document) => $document->expired_date->toDateString() < $summaryToday)->count();
            $summaryUpcoming = $this->certificateNotifications->count() - $summaryExpired;
        @endphp
        <dialog wire:ignore.self x-data x-init="$nextTick(() => $el.showModal())" aria-labelledby="expiry-summary-title" aria-describedby="expiry-summary-description"
            class="m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-lg overflow-y-auto rounded-2xl border border-slate-200 bg-white p-0 text-slate-800 shadow-2xl backdrop:bg-slate-950/60 backdrop:backdrop-blur-sm dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
            <div class="relative overflow-hidden bg-linear-to-br from-blue-600 to-indigo-700 px-6 py-6 text-white sm:px-7">
                <div class="flex items-start justify-between gap-4">
                    <span class="flex size-11 items-center justify-center rounded-xl bg-white/15"><x-dashboard.icon name="bell" class="size-6" /></span>
                    <button type="button" x-on:click="$el.closest('dialog').close()" aria-label="Tutup ringkasan" class="flex size-10 items-center justify-center rounded-full text-xl text-white/80 hover:bg-white/15 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">&times;</button>
                </div>
                <p class="mt-5 text-xs font-semibold tracking-wide text-blue-100 uppercase">Ringkasan dokumen kapal</p>
                <h2 id="expiry-summary-title" class="mt-1 text-2xl font-semibold">Selamat datang kembali</h2>
                <p id="expiry-summary-description" class="mt-2 text-sm leading-6 text-blue-100">Berikut masa berlaku dokumen kapal yang perlu Anda perhatikan.</p>
            </div>
            <div class="space-y-5 p-6 sm:p-7">
                <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/30">
                        <x-dashboard.icon name="clock" class="size-5 text-amber-700 dark:text-amber-400" />
                        <p class="mt-3 text-3xl font-bold tabular-nums text-amber-800 dark:text-amber-300">{{ $summaryUpcoming }}</p>
                        <p class="mt-1 text-sm font-semibold text-amber-900 dark:text-amber-200">Akan kedaluwarsa</p>
                        <p class="mt-1 text-xs text-amber-800 dark:text-amber-300">Hari ini hingga 20 hari ke depan</p>
                    </div>
                    <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-900 dark:bg-rose-950/30">
                        <x-dashboard.icon name="alert" class="size-5 text-rose-700 dark:text-rose-400" />
                        <p class="mt-3 text-3xl font-bold tabular-nums text-rose-800 dark:text-rose-300">{{ $summaryExpired }}</p>
                        <p class="mt-1 text-sm font-semibold text-rose-900 dark:text-rose-200">Sudah kedaluwarsa</p>
                        <p class="mt-1 text-xs text-rose-800 dark:text-rose-300">Perlu ditindaklanjuti</p>
                    </div>
                </div>
                <p class="text-sm leading-6 text-slate-600 dark:text-slate-300">{{ $summaryUpcoming + $summaryExpired > 0 ? 'Buka daftar untuk melihat nama kapal, jenis dokumen, dan tanggal berakhirnya.' : 'Tidak ada dokumen yang kedaluwarsa atau akan kedaluwarsa dalam 20 hari.' }}</p>
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" x-on:click="$el.closest('dialog').close()" class="min-h-11 rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">Tutup</button>
                    <a href="#certificate-notifications-heading" x-on:click="$el.closest('dialog').close()" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">Lihat daftar dokumen <x-dashboard.icon name="arrow" class="size-4" /></a>
                </div>
            </div>
        </dialog>
    @endif
    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
        <div><p class="mb-1.5 text-xs text-slate-500 dark:text-slate-400">Your port of control</p><h1 class="text-2xl font-semibold tracking-tight sm:text-[28px]">Overview</h1><p class="mt-2 text-[13px] text-slate-500 dark:text-slate-400">Semua aktivitas maritim Anda, dalam satu pandangan.</p></div>
        <div class="flex w-fit items-center gap-2 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2.5 text-xs shadow-xs"><x-dashboard.icon name="calendar" class="size-4 text-slate-400" /><time datetime="{{ now(config('spb.timezone'))->toDateString() }}">{{ now(config('spb.timezone'))->format('d M Y') }}</time></div>
    </div>

    <section aria-labelledby="certificate-notifications-heading" class="mt-4 scroll-mt-4 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs dark:border-slate-700 dark:bg-slate-900">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-4 dark:border-slate-800 sm:px-5">
            <div class="flex items-center gap-3">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300"><x-dashboard.icon name="alert" class="size-5" /></span>
                <div><h2 id="certificate-notifications-heading" class="text-sm font-semibold">Notifikasi masa berlaku dokumen kapal</h2><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Peringatan mulai 20 hari sebelum kedaluwarsa dan setelah kedaluwarsa.</p></div>
            </div>
            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $this->certificateNotifications->count() }} dokumen</span>
        </div>
        <div class="max-h-96 overflow-y-auto">
            @forelse ($this->certificateNotifications as $document)
                @php
                    $today = today(config('app.timezone'));
                    $daysRemaining = (int) $today->diffInDays(\Carbon\CarbonImmutable::parse($document->expired_date->toDateString(), config('app.timezone')), false);
                @endphp
                <div wire:key="certificate-notification-{{ $document->id }}" class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 last:border-0 dark:border-slate-800 sm:px-5">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ \App\Models\VesselDocument::TYPES[$document->document_type] ?? $document->document_type }}</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400"><a href="{{ route('vessels.edit', ['vessel' => $document->vessel_id]) }}" wire:navigate class="font-bold text-blue-600 hover:underline dark:text-blue-400">{{ $document->vessel->vessel_name }}</a> &middot; Berakhir {{ $document->expired_date->format('d M Y') }}</p>
                    </div>
                    <span @class(['rounded-full px-2.5 py-1 text-xs font-semibold', 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300' => $daysRemaining < 0, 'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300' => $daysRemaining >= 0])>{{ $daysRemaining < 0 ? 'Sudah kedaluwarsa' : ($daysRemaining === 0 ? 'Kedaluwarsa hari ini' : 'Kedaluwarsa dalam '.$daysRemaining.' hari') }}</span>
                </div>
            @empty
                <p class="px-4 py-6 text-sm text-slate-500 dark:text-slate-400 sm:px-5">Tidak ada dokumen yang kedaluwarsa atau akan kedaluwarsa dalam 20 hari.</p>
            @endforelse
        </div>
    </section>

    @if ($this->needAttentionCount >= 1)
    <div class="mt-6 flex flex-col gap-3 rounded-xl border border-blue-100 dark:border-blue-900 bg-blue-50/70 dark:bg-blue-950/70 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-start gap-3 sm:items-center"><span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-white dark:bg-slate-900 text-blue-600 dark:text-blue-400"><x-dashboard.icon name="alert" class="size-4" /></span><p class="text-xs leading-5 text-slate-600 dark:text-slate-300"><span class="font-semibold text-slate-800 dark:text-slate-100">Ada {{ $this->needAttentionCount }} item yang perlu perhatian.</span> Tinjau dokumen dan persetujuan agar operasional tetap lancar.</p></div>
        <a href="#attention" class="flex shrink-0 items-center gap-2 self-start text-xs font-semibold text-blue-600 dark:text-blue-400 sm:self-auto">Lihat ringkasan <x-dashboard.icon name="arrow" class="size-4" /></a>
    </div>
    @endif

    <livewire:dashboard.operational-calendar />

    <section aria-labelledby="departures-heading" class="mt-7 rounded-xl border border-slate-200/80 dark:border-slate-700/80 bg-white dark:bg-slate-900 p-5 shadow-xs">
        <h2 id="departures-heading" class="text-sm font-semibold">Laporan keberangkatan</h2>
        <p class="mt-2 text-xs leading-5 text-slate-500 dark:text-slate-400">SPB selesai berdasarkan tanggal perkiraan keberangkatan (WITA).</p>
        <label for="departure-report-date" class="mt-4 block text-xs font-medium text-slate-600 dark:text-slate-300">Tanggal laporan</label>
        <input id="departure-report-date" type="date" wire:model.live="departureReportDate" class="mt-2 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900 sm:max-w-60" />
        <p wire:loading wire:target="departureReportDate" class="mt-2 text-xs text-blue-600 dark:text-blue-400">Memuat laporan...</p>
        <div class="mt-4 max-h-96 space-y-3 overflow-y-auto" aria-live="polite">
            @forelse ($this->departureReports as $report)
                <article wire:key="departure-report-{{ $report->id }}" class="rounded-lg border border-slate-100 p-3 dark:border-slate-700">
                    <a href="{{ route('spb.show', $report) }}" class="text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">{{ $report->vessel->vessel_name }} &middot; {{ $report->service_number }}</a>
                    <p class="mt-2 text-xs font-semibold text-slate-700 dark:text-slate-200">{{ match ($report->sailing_result) { 'success' => 'Berangkat', 'not_sailing' => 'Tidak berlayar', 'failed' => 'Gagal berlayar', default => 'SPB selesai diarsipkan' } }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $report->departure_port }} &rarr; {{ $report->destination }} &middot; Perkiraan {{ $report->estimated_departure_at->timezone(config('spb.timezone'))->format('H:i') }} WITA</p>
                    @if ($report->sailing_result === 'success' && $report->actual_departure_at)<p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Berangkat: {{ $report->actual_departure_at->timezone(config('spb.timezone'))->format('d M Y H:i') }} WITA</p>@endif
                    @if ($report->sailing_result === 'failed' && $report->failure_reason)<p class="mt-1 text-xs text-rose-600 dark:text-rose-400">Alasan: {{ $report->failure_reason }}</p>@endif
                </article>
            @empty
                <p class="text-xs leading-5 text-slate-500 dark:text-slate-400">Tidak ada SPB selesai dengan jadwal keberangkatan pada tanggal ini.</p>
            @endforelse
        </div>
    </section>

    <div class="mt-7 grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1fr)_300px] 2xl:grid-cols-[minmax(0,1fr)_340px]">
        <section aria-labelledby="services-heading" class="min-w-0">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2"><h2 id="services-heading" class="text-sm font-semibold">Layanan aktif</h2><span class="rounded-md bg-slate-200/70 dark:bg-slate-700/70 px-1.5 py-0.5 text-[10px] font-medium text-slate-500 dark:text-slate-400">{{ $this->activeServicesCount }}</span></div>
                <span class="text-[11px] text-slate-400">Maksimal 5 terbaru per layanan</span>
            </div>

            <div class="flex flex-col gap-3">
                @forelse ($this->activeSpb as $service)
                    <article wire:key="service-{{ $service->id }}" class="space-y-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
                        <div class="flex flex-wrap justify-between gap-3"><a href="{{ route('spb.show', $service) }}" class="text-sm font-semibold text-blue-600 dark:text-blue-400">{{ $service->service_number }} · {{ $service->vessel->vessel_name }}</a><x-dashboard.spb-status :status="$service->status" /></div>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $service->departure_port }} → {{ $service->destination }} · {{ $service->estimated_departure_at->timezone(config('spb.timezone'))->format('d M Y H:i') }} WITA</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">PIC: {{ $service->assignedStaff?->name ?? 'Belum ditentukan' }}</p>
                    </article>
                @empty
                    <p class="rounded-xl border border-dashed border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 p-6 text-sm text-slate-500 dark:text-slate-400">Belum ada layanan SPB aktif.</p>
                @endforelse
                @forelse ($this->activeNationalityServices as $service)
                    <article wire:key="nationality-active-{{ $service->id }}" class="space-y-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
                        <div class="flex flex-wrap justify-between gap-3"><a href="{{ route('ship-nationality.show', $service) }}" class="text-sm font-semibold text-blue-600 dark:text-blue-400">{{ $service->service_number }} &middot; {{ $service->vessel->vessel_name }}</a><x-dashboard.ship-nationality-status :status="$service->status" /></div>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ \App\Models\ShipNationalityService::DOCUMENT_TYPES[$service->document_type] }} &middot; PIC: {{ $service->assignedStaff?->name ?? 'Belum ditentukan' }}</p>
                    </article>
                @empty<p class="rounded-xl border border-dashed border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 p-6 text-sm text-slate-500 dark:text-slate-400">Belum ada pengurusan dokumen kebangsaan aktif.</p>@endforelse
                @forelse ($this->activeSijilServices as $service)
                    <article wire:key="sijil-active-{{ $service->id }}" class="space-y-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
                        <div class="flex flex-wrap justify-between gap-3"><a href="{{ route('sijil.show', $service) }}" class="text-sm font-semibold text-blue-600 dark:text-blue-400">{{ $service->service_number }} &middot; {{ $service->vessel->vessel_name }}</a><x-dashboard.sijil-status :status="$service->status" /></div>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Sijil &middot; {{ \App\Models\SijilService::PROCESS_TYPES[$service->process_type] }}</p>
                    </article>
                @empty<p class="rounded-xl border border-dashed border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 p-6 text-sm text-slate-500 dark:text-slate-400">Belum ada pengurusan sijil aktif.</p>@endforelse
                @forelse ($this->activeBunkerServices as $service)
                    <article wire:key="bunker-active-{{ $service->id }}" class="space-y-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
                        <div class="flex flex-wrap justify-between gap-3"><a href="{{ route('bunker.show', $service) }}" class="text-sm font-semibold text-blue-600 dark:text-blue-400">{{ $service->service_number }} &middot; {{ $service->vessel->vessel_name }}</a><x-dashboard.bunker-status :status="$service->status" /></div>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Izin Bunker &middot; {{ $service->supplier ?? 'Pemasok belum diisi' }} &middot; {{ $service->scheduled_at?->timezone(config('bunker.timezone'))->format('d M Y H:i') ?? 'Jadwal belum diisi' }} WITA</p>
                    </article>
                @empty<p class="rounded-xl border border-dashed border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 p-6 text-sm text-slate-500 dark:text-slate-400">Belum ada pengurusan izin bunker aktif.</p>@endforelse
                @forelse ($this->activeSafetyServices as $service)
                    <article wire:key="safety-active-{{ $service->id }}" class="space-y-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
                        <div class="flex flex-wrap justify-between gap-3"><a href="{{ route('safety-certificates.show', $service) }}" class="text-sm font-semibold text-blue-600 dark:text-blue-400">{{ $service->service_number }} · {{ $service->vessel->vessel_name }}</a><x-dashboard.safety-status :status="$service->status" /></div>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Sertifikat Keselamatan · {{ \App\Models\SafetyCertificateService::REQUEST_TYPES[$service->request_type] }} · PIC: {{ $service->assignedStaff->name }}</p>
                    </article>
                @empty<p class="rounded-xl border border-dashed border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 p-6 text-sm text-slate-500 dark:text-slate-400">Belum ada pengurusan sertifikat keselamatan aktif.</p>@endforelse
            </div>
        </section>

        <div class="flex min-w-0 flex-col gap-5">
            <section id="attention" aria-labelledby="attention-heading" class="scroll-mt-5 rounded-xl border border-slate-200/80 dark:border-slate-700/80 bg-white dark:bg-slate-900 p-5 shadow-xs">
                <div class="flex items-center justify-between"><h2 id="attention-heading" class="text-sm font-semibold">Perlu perhatian</h2><span class="rounded-md bg-rose-50 dark:bg-rose-950/40 px-1.5 py-0.5 text-[10px] font-semibold text-rose-600 dark:text-rose-400">{{ $this->needAttentionCount }}</span></div>
                <div class="mt-5 flex gap-3"><span class="mt-0.5 text-rose-500 dark:text-rose-400"><x-dashboard.icon name="document" class="size-4" /></span><div><h3 class="text-[11px] font-medium">{{ $this->expiringDocumentsCount }} dokumen segera kedaluwarsa</h3><p class="mt-1 text-[10px] leading-5 text-slate-500 dark:text-slate-400">Periksa dokumen aktif dalam {{ config('vessels.expiration_warning_days') }} hari ke depan. {{ $this->expiredDocumentsCount }} dokumen sudah kedaluwarsa.</p></div></div>
                <div class="mt-4 flex gap-3 border-t border-slate-100 dark:border-slate-700 pt-4"><span class="mt-0.5 text-blue-500 dark:text-blue-400"><x-dashboard.icon name="clock" class="size-4" /></span><div><h3 class="text-[11px] font-medium">Revisi dan pemeriksaan layanan</h3><p class="mt-1 text-[10px] leading-5 text-slate-500 dark:text-slate-400">Lengkapi revisi serta tindak lanjuti inspection atau target yang terlewat.</p></div></div>
                @foreach ($this->nationalityAttention as $service)
                    <a wire:key="nationality-attention-{{ $service->id }}" href="{{ route('ship-nationality.show', $service) }}" class="mt-3 block text-xs text-blue-600 dark:text-blue-400">{{ $service->service_number }} &middot; {{ $service->vessel->vessel_name }} &middot; {{ $service->statusLabel() }}</a>
                @endforeach
                <a href="{{ route('ship-nationality.index', ['status' => 'attention']) }}" class="mt-4 block text-xs font-semibold text-blue-600 dark:text-blue-400">Tinjau dokumen kebangsaan kapal</a>
                @foreach ($this->sijilAttention->sortByDesc('id')->take(5) as $service)
                    <a wire:key="sijil-attention-{{ $service->id }}" href="{{ route('sijil.show', $service) }}" class="mt-3 block text-xs text-blue-600 dark:text-blue-400">{{ $service->service_number }} &middot; {{ $service->vessel->vessel_name }} &middot; {{ $service->statusLabel() }}</a>
                @endforeach
                <a href="{{ route('sijil.index', ['status' => 'attention']) }}" class="mt-4 block text-xs font-semibold text-blue-600 dark:text-blue-400">Tinjau sijil awak kapal</a>
                @foreach ($this->bunkerAttention as $service)
                    <a wire:key="bunker-attention-{{ $service->id }}" href="{{ route('bunker.show', $service) }}" class="mt-3 block text-xs text-blue-600 dark:text-blue-400">{{ $service->service_number }} &middot; {{ $service->vessel->vessel_name }} &middot; {{ $service->statusLabel() }}</a>
                @endforeach
                <a href="{{ route('bunker.index', ['status' => 'attention']) }}" class="mt-4 block text-xs font-semibold text-blue-600 dark:text-blue-400">Tinjau izin bunker</a>
                @foreach ($this->safetyAttention as $service)
                    <a wire:key="safety-attention-{{ $service->id }}" href="{{ route('safety-certificates.show', $service) }}" class="mt-3 block text-xs text-blue-600 dark:text-blue-400">{{ $service->service_number }} · {{ $service->vessel->vessel_name }} · {{ $service->statusLabel() }}</a>
                @endforeach
                <a href="{{ route('safety-certificates.index', ['status' => 'attention']) }}" class="mt-4 block text-xs font-semibold text-blue-600 dark:text-blue-400">Tinjau pengurusan sertifikat</a>
            </section>

            <div class="flex items-start gap-2 px-1 text-slate-400"><x-dashboard.icon name="help" class="mt-0.5 size-3.5" /><p class="text-[10px] leading-5">Ringkasan memakai data SPB, sertifikat keselamatan, bunker, sijil, dokumen kebangsaan, dan dokumen Vessel aktif. Perlu perhatian menghitung layanan dan dokumen secara terpisah.</p></div>
        </div>
    </div>
</x-dashboard.shell>
