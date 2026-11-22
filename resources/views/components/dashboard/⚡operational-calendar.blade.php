<?php

use App\Services\OperationalCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public bool $expanded = false;

    #[Locked]
    public string $month;

    #[Locked]
    public string $selectedDate;

    #[Locked]
    public string $view = 'month';

    #[Locked]
    public bool $overdueOnly = false;

    public function boot(): void
    {
        Gate::authorize('access-system');
    }

    public function mount(bool $expanded = false): void
    {
        $this->expanded = $expanded;
        $this->goToday();
    }

    public function goToday(): void
    {
        $today = CarbonImmutable::today(config('spb.timezone'));
        $this->month = $today->format('Y-m');
        $this->selectedDate = $today->toDateString();
        $this->overdueOnly = false;
        $this->view = 'month';
        $this->resetCalendarCache();
    }

    public function moveMonth(int $direction): void
    {
        abort_unless(in_array($direction, [-1, 1], true), 422);
        $next = $this->period->addMonthsNoOverflow($direction);
        if ($next->year < 2000 || $next->year > 2100) {
            return;
        }
        $this->month = $next->format('Y-m');
        $this->selectedDate = $next->toDateString();
        $this->resetCalendarCache();
    }

    public function selectDate(string $date): void
    {
        Validator::make(['date' => $date], [
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
        ], ['date.*' => 'Tanggal kalender tidak valid.'])->validate();
        $this->selectedDate = $date;
        $this->month = substr($date, 0, 7);
        $this->resetCalendarCache();
    }

    public function selectPeriod(string $month, string $year): void
    {
        $data = Validator::make(['month' => $month, 'year' => $year], [
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2000,2100'],
        ], ['month.*' => 'Pilih bulan yang valid.', 'year.*' => 'Pilih tahun antara 2000 dan 2100.'])->validate();
        $period = CarbonImmutable::create((int) $data['year'], (int) $data['month'], 1, 0, 0, 0, config('spb.timezone'));
        $selectedDay = CarbonImmutable::parse($this->selectedDate, config('spb.timezone'))->day;
        $this->month = $period->format('Y-m');
        $this->selectedDate = $period->setDay(min($selectedDay, $period->daysInMonth))->toDateString();
        $this->resetCalendarCache();
    }

    public function setView(string $view): void
    {
        abort_unless(in_array($view, ['month', 'agenda'], true), 422);
        $this->view = $view;
        $this->resetCalendarCache();
    }

    public function toggleOverdue(): void
    {
        $this->overdueOnly = ! $this->overdueOnly;
        if ($this->overdueOnly) {
            $this->view = 'agenda';
        }
        $this->resetCalendarCache();
    }

    private function resetCalendarCache(): void
    {
        unset($this->period, $this->events, $this->visibleEvents, $this->agenda, $this->days);
    }

    #[Computed]
    public function period(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->month, config('spb.timezone'));
    }

    /** @return Collection<int, array<string, mixed>> */
    #[Computed]
    public function events(): Collection
    {
        return app(OperationalCalendar::class)->events(auth()->user(),
            $this->period->startOfWeek(CarbonInterface::MONDAY),
            $this->period->endOfMonth()->endOfWeek(CarbonInterface::SUNDAY));
    }

    /** @return Collection<int, array<string, mixed>> */
    #[Computed]
    public function visibleEvents(): Collection
    {
        return $this->events->filter(fn (array $event): bool => ! $this->overdueOnly || $event['overdue']);
    }

    /** @return Collection<int, array<string, mixed>> */
    #[Computed]
    public function agenda(): Collection
    {
        return $this->visibleEvents->filter(fn (array $event): bool => $this->view === 'month'
            ? $event['date'] === $this->selectedDate
            : str_starts_with($event['date'], $this->month));
    }

    /** @return list<array{date: string, number: int, current: bool, today: bool, events: Collection<int, array<string, mixed>>}> */
    #[Computed]
    public function days(): array
    {
        $days = [];
        $grouped = $this->visibleEvents->groupBy('date');
        $today = CarbonImmutable::today(config('spb.timezone'))->toDateString();
        $end = $this->period->endOfMonth()->endOfWeek(CarbonInterface::SUNDAY);
        for ($day = $this->period->startOfWeek(CarbonInterface::MONDAY); $day->lte($end); $day = $day->addDay()) {
            $date = $day->toDateString();
            $days[] = ['date' => $date, 'number' => $day->day, 'current' => $day->format('Y-m') === $this->month,
                'today' => $date === $today, 'events' => $grouped->get($date, collect())];
        }

        return $days;
    }
};
?>

<section wire:poll.60s.visible aria-labelledby="operational-calendar-title" @class(['overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs dark:border-slate-700 dark:bg-slate-900', 'mt-6' => ! $expanded, 'operational-calendar-full flex min-h-[calc(100dvh-2rem)] flex-col sm:min-h-[calc(100dvh-3rem)] lg:min-h-0 lg:flex-1' => $expanded])>
    <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-slate-100 bg-linear-to-r from-blue-50/80 to-white p-4 dark:border-slate-800 dark:from-blue-950/40 dark:to-slate-900 sm:px-5">
        <div class="flex min-w-0 items-center gap-3"><span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-blue-100 text-blue-600 dark:bg-blue-500/15 dark:text-blue-300"><x-dashboard.icon name="calendar" class="size-5" /></span><div>@if ($expanded)<h1 id="operational-calendar-title" class="text-lg font-semibold sm:text-xl">Kalender operasional</h1>@else<h2 id="operational-calendar-title" class="text-sm font-semibold">Kalender operasional</h2>@endif<p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Pantau masa berlaku dokumen kapal.</p></div></div>
        <div class="flex flex-wrap items-center gap-3">
            <span wire:loading role="status" class="text-xs text-blue-600 dark:text-blue-300">Memperbarui kalender…</span>
            @if ($expanded)
                <x-dashboard.add-button :href="route('home').'#operational-calendar-title'" variant="soft" icon="arrow" class="whitespace-nowrap [&_svg]:size-3.5 [&_svg]:rotate-180">Kembali ke Overview</x-dashboard.add-button>
            @else
                <x-dashboard.add-button :href="route('dashboard.operational-calendar')" variant="soft" icon="arrow" class="whitespace-nowrap [&_svg]:size-3.5 [&_svg]:-rotate-45">Tampilkan kalender penuh</x-dashboard.add-button>
            @endif
        </div>
    </div>

    <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 p-4 sm:px-5">
        <div class="flex flex-wrap items-center gap-2">
            <div x-data="{ periodOpen: false }" x-on:keydown.escape.window="periodOpen = false" class="relative">
                <button type="button" x-on:click="periodOpen = !periodOpen" x-bind:aria-expanded="periodOpen" aria-controls="calendar-period-picker" aria-label="Pilih bulan dan tahun" class="mr-1 flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm font-semibold transition hover:bg-slate-100 dark:hover:bg-slate-800 sm:text-base">
                    {{ $this->period->locale('id')->translatedFormat('F Y') }}<x-dashboard.icon name="chevron" class="size-3 rotate-90" />
                </button>
                <div x-cloak x-show="periodOpen" x-transition.opacity.duration.150ms x-on:click.outside="periodOpen = false" id="calendar-period-picker" class="absolute top-full left-0 z-20 mt-2 w-64 rounded-xl border border-slate-200 bg-white p-3 shadow-lg dark:border-slate-700 dark:bg-slate-900">
                    <p class="mb-3 text-xs font-semibold">Pindah bulan dan tahun</p>
                    <div class="grid grid-cols-2 gap-2">
                        <div><label for="calendar-month-choice" class="mb-1.5 block text-[11px] font-medium text-slate-500 dark:text-slate-400">Bulan</label><select x-ref="calendarMonth" id="calendar-month-choice" class="w-full rounded-lg border border-slate-200 bg-white px-2 py-2 text-xs dark:border-slate-700 dark:bg-slate-800">
                            @foreach (range(1, 12) as $number)<option wire:key="calendar-month-choice-{{ $number }}" value="{{ $number }}" @selected($this->period->month === $number)>{{ $this->period->setMonth($number)->locale('id')->translatedFormat('F') }}</option>@endforeach
                        </select></div>
                        <div><label for="calendar-year-choice" class="mb-1.5 block text-[11px] font-medium text-slate-500 dark:text-slate-400">Tahun</label><select x-ref="calendarYear" id="calendar-year-choice" class="w-full rounded-lg border border-slate-200 bg-white px-2 py-2 text-xs dark:border-slate-700 dark:bg-slate-800">
                            @foreach (range(2000, 2100) as $year)<option wire:key="calendar-year-choice-{{ $year }}" value="{{ $year }}" @selected($this->period->year === $year)>{{ $year }}</option>@endforeach
                        </select></div>
                    </div>
                    <button type="button" x-on:click="$wire.selectPeriod($refs.calendarMonth.value, $refs.calendarYear.value).then(() => periodOpen = false)" wire:loading.attr="disabled" wire:target="selectPeriod" class="mt-3 w-full rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-blue-700 disabled:opacity-50 dark:bg-blue-500 dark:hover:bg-blue-400">Tampilkan</button>
                </div>
            </div>
            <button type="button" wire:click="moveMonth(-1)" wire:loading.attr="disabled" wire:target="moveMonth,selectPeriod,goToday" @disabled($month === '2000-01') aria-label="Bulan sebelumnya" class="flex size-8 items-center justify-center rounded-full bg-blue-50 text-blue-600 transition hover:bg-blue-100 active:scale-95 disabled:opacity-50 dark:bg-blue-500/10 dark:text-blue-300"><x-dashboard.icon name="chevron" class="size-3.5 rotate-180" /></button>
            <button type="button" wire:click="moveMonth(1)" wire:loading.attr="disabled" wire:target="moveMonth,selectPeriod,goToday" @disabled($month === '2100-12') aria-label="Bulan berikutnya" class="flex size-8 items-center justify-center rounded-full bg-blue-50 text-blue-600 transition hover:bg-blue-100 active:scale-95 disabled:opacity-50 dark:bg-blue-500/10 dark:text-blue-300"><x-dashboard.icon name="chevron" class="size-3.5" /></button>
            <button type="button" wire:click="goToday" class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">Hari ini</button>
        </div>
        <div class="flex items-center gap-1 rounded-lg bg-slate-100 p-1 dark:bg-slate-800">
            @foreach (['month' => 'Bulan', 'agenda' => 'Agenda'] as $value => $label)
                <button type="button" wire:key="calendar-view-{{ $value }}" wire:click="setView('{{ $value }}')" aria-pressed="{{ $view === $value ? 'true' : 'false' }}" @class(['rounded-md px-3 py-1.5 text-xs font-medium transition', 'bg-white text-blue-700 shadow-xs dark:bg-slate-700 dark:text-blue-200' => $view === $value, 'text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200' => $view !== $value])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 pb-4 dark:border-slate-800 sm:px-5">
        <p class="text-xs text-slate-500 dark:text-slate-400">Dokumen kapal bulan ini: {{ $this->events->filter(fn ($event) => str_starts_with($event['date'], $month))->count() }}</p>
        <button type="button" wire:click="toggleOverdue" aria-pressed="{{ $overdueOnly ? 'true' : 'false' }}" @class(['flex items-center gap-1.5 rounded-lg px-2 py-1 text-[11px] font-medium transition', 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300' => $overdueOnly, 'text-slate-500 hover:bg-rose-50 hover:text-rose-700 dark:text-slate-400 dark:hover:bg-rose-950/40 dark:hover:text-rose-300' => ! $overdueOnly])><x-dashboard.icon name="alert" class="size-3.5" />Lewat tenggat bulan ini <span class="font-semibold">{{ $this->events->filter(fn ($event) => str_starts_with($event['date'], $month) && $event['overdue'])->count() }}</span></button>
    </div>

    <div wire:offline class="mx-4 mt-3 shrink-0 rounded-lg bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">Koneksi terputus. Kalender akan diperbarui setelah terhubung kembali.</div>

    <div wire:key="calendar-period-{{ $month }}-{{ $view }}" @class(['calendar-reveal grid min-w-0', 'flex-1 lg:min-h-0 lg:grid-rows-[minmax(0,1fr)] lg:overflow-hidden' => $expanded, 'xl:grid-cols-[minmax(0,1fr)_280px]' => $view === 'month' && ! $expanded, 'lg:grid-cols-[minmax(0,1fr)_300px] xl:grid-cols-[minmax(0,1fr)_340px]' => $view === 'month' && $expanded])>
        @if ($view === 'month')
            <div @class(['min-w-0 p-3 sm:p-4', 'flex flex-col lg:min-h-0 lg:overflow-hidden' => $expanded])>
                <div class="grid shrink-0 grid-cols-7 text-center text-[10px] font-semibold text-slate-500 dark:text-slate-400 sm:text-xs">@foreach (['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $day)<span wire:key="calendar-weekday-{{ $day }}" @class(['py-2', 'text-red-600 dark:text-red-400' => $day === 'Min'])>{{ $day }}</span>@endforeach</div>
                <div @class(['grid grid-cols-7 gap-px overflow-hidden rounded-xl border border-slate-200 bg-slate-200 dark:border-slate-700 dark:bg-slate-700', 'flex-1 lg:min-h-0 lg:auto-rows-fr' => $expanded]) role="group" aria-label="Tanggal dan agenda bulan ini">
                    @foreach ($this->days as $day)
                        @php($isSunday = CarbonImmutable::parse($day['date'])->isSunday())
                        <button type="button" wire:key="calendar-day-{{ $day['date'] }}" wire:click="selectDate('{{ $day['date'] }}')" aria-label="{{ CarbonImmutable::parse($day['date'])->locale('id')->translatedFormat('l, j F Y') }}, {{ $day['events']->count() }} agenda" aria-pressed="{{ $selectedDate === $day['date'] ? 'true' : 'false' }}" @if ($day['today']) aria-current="date" @endif
                            @class(['flex min-h-18 min-w-0 flex-col gap-1 p-1.5 text-left transition-colors duration-200 sm:min-h-24 sm:p-2 motion-reduce:transition-none', 'lg:min-h-0 lg:overflow-hidden lg:gap-0.5 lg:p-1.5' => $expanded, 'bg-blue-50 ring-2 ring-blue-400 ring-inset dark:bg-blue-950/50 dark:ring-blue-500' => $selectedDate === $day['date'], 'bg-white hover:bg-blue-50/60 dark:bg-slate-900 dark:hover:bg-slate-800' => $selectedDate !== $day['date'] && $day['current'], 'bg-slate-50 dark:bg-slate-950/40' => $selectedDate !== $day['date'] && ! $day['current']])>
                            @if ($expanded)<div class="flex w-full shrink-0 items-center justify-between gap-1">@endif
                            <span @class(['flex size-6 items-center justify-center rounded-full text-[11px] font-semibold sm:text-xs', 'bg-blue-600 text-white dark:bg-blue-500' => $day['today'] && ! $isSunday, 'bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300' => $day['today'] && $isSunday, 'text-red-600 dark:text-red-400' => ! $day['today'] && $isSunday && $day['current'], 'text-red-400 dark:text-red-500' => ! $day['today'] && $isSunday && ! $day['current'], 'text-slate-800 dark:text-slate-200' => ! $day['today'] && ! $isSunday && $day['current'], 'text-slate-400' => ! $day['today'] && ! $isSunday && ! $day['current']])>{{ $day['number'] }}</span>
                            @if ($expanded)
                                @if ($day['events']->isNotEmpty())<span class="hidden items-center gap-1 text-[9px] font-semibold text-blue-600 lg:inline-flex dark:text-blue-300"><span class="size-1 rounded-full bg-current" aria-hidden="true"></span>{{ $day['events']->count() }}<span class="sr-only"> agenda</span></span>@endif
                            </div>@endif
                            <span class="mt-auto flex flex-wrap gap-1 sm:hidden">@foreach ($day['events']->take(3) as $event)<span wire:key="calendar-dot-{{ $event['id'] }}" @class(['size-1.5 rounded-full', 'ring-1 ring-rose-500 ring-offset-1 ring-offset-white dark:ring-offset-slate-900' => $event['overdue'], 'bg-amber-500' => $event['kind'] === 'document'])></span>@endforeach @if ($day['events']->count() > 3)<span class="text-[8px] leading-none text-slate-500">+{{ $day['events']->count() - 3 }}</span>@endif</span>
                            @foreach ($day['events']->take(2) as $event)
                                <span wire:key="calendar-pill-{{ $event['id'] }}" data-calendar-pill title="{{ $event['title'] }} · {{ $event['subtitle'] }}{{ $event['overdue'] ? ' · Lewat tenggat' : '' }}" @class(['hidden min-w-0 items-center gap-1 rounded-md px-1.5 py-1 text-[9px] font-medium sm:flex', 'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-200' => $event['kind'] === 'document'])>@if ($event['overdue'])<span class="size-1 shrink-0 rounded-full bg-rose-500"></span>@endif<span class="truncate">{{ $event['title'] }}</span></span>
                            @endforeach
                            @if ($day['events']->count() > 2)<span @class(['hidden px-1 text-[9px] font-medium text-blue-600 dark:text-blue-300 sm:block', 'lg:hidden' => $expanded])>+{{ $day['events']->count() - 2 }} lainnya</span>@endif
                        </button>
                    @endforeach
                </div>
                <p class="mt-3 shrink-0 text-[10px] leading-5 text-slate-500 dark:text-slate-400">Klik tanggal untuk membuka agenda. Titik merah menandai item yang telah melewati tenggat. Semua waktu dalam WITA.</p>
            </div>
        @endif

        <aside aria-label="Daftar agenda kalender" @class(['min-w-0 border-t border-slate-100 bg-slate-50/60 p-4 dark:border-slate-800 dark:bg-slate-950/20', 'lg:flex lg:min-h-0 lg:flex-col lg:overflow-hidden' => $expanded, 'xl:border-t-0 xl:border-l' => $view === 'month' && ! $expanded, 'lg:border-t-0 lg:border-l' => $view === 'month' && $expanded])>
            <div class="mb-4 shrink-0"><h3 @class(['text-sm font-semibold', 'text-red-600 dark:text-red-400' => $view === 'month' && CarbonImmutable::parse($selectedDate)->isSunday()])>{{ $view === 'month' ? CarbonImmutable::parse($selectedDate)->locale('id')->translatedFormat('l, j F') : 'Agenda '.$this->period->locale('id')->translatedFormat('F Y') }}</h3><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $this->agenda->count() }} agenda{{ $overdueOnly ? ' melewati tenggat' : '' }}</p></div>
            <div wire:key="calendar-agenda-{{ $selectedDate }}-{{ (int) $overdueOnly }}" @class(['calendar-reveal grid gap-3 overflow-y-auto pr-1', 'max-h-112' => ! $expanded, 'lg:min-h-0 lg:flex-1 lg:auto-rows-max lg:content-start' => $expanded, 'sm:grid-cols-2 xl:grid-cols-3' => $view === 'agenda'])>
                @forelse ($this->agenda as $event)
                    <article wire:key="calendar-event-{{ $event['id'] }}" class="min-w-0 rounded-xl border border-slate-200 bg-white p-3 transition hover:shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-1.5"><span @class(['rounded-full px-2 py-0.5 text-[9px] font-semibold', 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200' => $event['kind'] === 'document'])>Dokumen</span><time datetime="{{ $event['date'] }}{{ $event['time'] ? 'T'.$event['time'].':00+08:00' : '' }}" @class(['text-[10px]', 'text-red-600 dark:text-red-400' => CarbonImmutable::parse($event['date'])->isSunday(), 'text-slate-500 dark:text-slate-400' => ! CarbonImmutable::parse($event['date'])->isSunday()])>{{ CarbonImmutable::parse($event['date'])->locale('id')->translatedFormat('j M') }}{{ $event['time'] ? ' · '.$event['time'].' WITA' : '' }}</time></div>
                        <h4 class="text-xs leading-5 font-semibold wrap-break-word">{{ $event['title'] }}</h4><p class="mt-1 text-[11px] leading-5 text-slate-500 dark:text-slate-400 wrap-break-word">{{ $event['subtitle'] }}</p>
                        @if ($event['overdue'])<p class="mt-2 flex items-center gap-1 text-[10px] font-semibold text-rose-600 dark:text-rose-300"><x-dashboard.icon name="alert" class="size-3" />Sudah kedaluwarsa</p>@else<p class="mt-2 text-[10px] text-amber-700 dark:text-amber-300">{{ $event['date'] === now(config('spb.timezone'))->toDateString() ? 'Masa berlaku berakhir hari ini' : 'Tanggal berakhirnya masa berlaku' }}</p>@endif
                        <div class="mt-3"><a href="{{ $event['url'] }}" wire:navigate class="rounded-md px-1 py-1 text-[10px] font-semibold text-slate-600 hover:text-blue-700 dark:text-slate-300 dark:hover:text-blue-300">Lihat dokumen</a></div>
                    </article>
                @empty
                    <div class="col-span-full flex flex-col items-center gap-2 rounded-xl border border-dashed border-slate-200 px-3 py-7 text-center dark:border-slate-700"><x-dashboard.icon name="calendar" class="size-7 text-slate-400" /><p class="text-xs font-medium">Belum ada agenda yang sesuai.</p><p class="text-[11px] leading-5 text-slate-500 dark:text-slate-400">{{ $view === 'month' ? 'Pilih tanggal dengan penanda atau buka tampilan Agenda.' : 'Pindah bulan untuk melihat dokumen lainnya.' }}</p></div>
                @endforelse
            </div>
        </aside>
    </div>
</section>
