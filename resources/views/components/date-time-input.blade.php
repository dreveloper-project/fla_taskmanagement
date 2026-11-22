@props(['name', 'label' => null, 'type' => 'date', 'value' => null, 'compact' => false])

@php
    $inputId = $attributes->get('id', $name);
    $initialValue = old($name, $value) ?? '';
    $initialDate = is_string($initialValue) ? substr($initialValue, 0, 10) : '';
    $displayDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $initialDate) ? implode('/', array_reverse(explode('-', $initialDate))) : '';
    $controlClasses = 'block w-full min-w-0 rounded-lg border bg-white text-sm text-slate-800 shadow-xs outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-slate-900 dark:text-slate-100';
    $spacingClasses = $compact ? 'h-10 px-3 text-xs' : 'h-12 px-3.5';
    $inputAttributes = $attributes->whereDoesntStartWith(['wire:model', 'x-model'])->except(['id', 'name', 'type', 'value', 'class', 'min', 'max', 'wire:key', 'shortcut']);
@endphp

<div
    x-data="dateTimeInput(@js(['type' => $type]))"
    x-modelable="value"
    x-id="['date-calendar', 'date-format', 'date-error', 'date-heading', 'time-options']"
    data-date-time-input
    data-initial-value="{{ is_string($initialValue) ? $initialValue : '' }}"
    data-min="{{ $attributes->get('min', '') }}"
    data-max="{{ $attributes->get('max', '') }}"
    data-required="{{ $attributes->get('required') ? 'true' : 'false' }}"
    {{ $attributes->whereStartsWith(['wire:model', 'x-model']) }}
    {{ $attributes->only(['wire:key'])->class(['min-w-0', $attributes->get('class', '')]) }}
>
    <input type="hidden" name="{{ $name }}" value="{{ is_string($initialValue) ? $initialValue : '' }}" x-bind:value="value">
    <div @class(['grid gap-2', 'grid-cols-[minmax(0,1fr)_7.5rem]' => $type === 'datetime-local'])>
        <div class="relative min-w-0">
            <input
                id="{{ $inputId }}" x-ref="date" data-date-text type="text" inputmode="numeric" autocomplete="off"
                placeholder="DD/MM/YYYY" value="{{ $displayDate }}" maxlength="10"
                x-model="dateText" x-on:input="syncValue()" x-on:change="commitDate()" x-on:blur="commitDate()"
                x-on:keydown.alt.arrow-down.prevent="openCalendar()"
                x-bind:aria-invalid="Boolean(error) || @js($attributes->get('aria-invalid') === 'true')"
                x-bind:class="error || @js($attributes->get('aria-invalid') === 'true') ? 'border-rose-400 dark:border-rose-500' : 'border-slate-200 dark:border-slate-700'"
                x-bind:aria-describedby="[$id('date-format'), error ? $id('date-error') : '', @js($attributes->get('aria-describedby', ''))].filter(Boolean).join(' ')"
                {{ $inputAttributes->class([$controlClasses, $spacingClasses, 'pr-11']) }}
            >
            <button
                type="button" x-ref="trigger" x-on:click="openCalendar()" aria-label="Pilih tanggal{{ $label ? ' · '.$label : '' }}" aria-haspopup="dialog"
                x-bind:aria-expanded="open" x-bind:aria-controls="$id('date-calendar')"
                @disabled($attributes->get('disabled') || $attributes->get('readonly'))
                class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-lg text-slate-500 hover:bg-blue-50 hover:text-blue-600 disabled:pointer-events-none disabled:opacity-40 dark:text-slate-400 dark:hover:bg-blue-950/40 dark:hover:text-blue-300"
            ><x-dashboard.icon name="calendar" class="size-4" /></button>
        </div>
        @if ($type === 'datetime-local')
            <div class="relative min-w-0">
                <input
                    id="{{ $inputId }}-time" x-ref="time" type="text" inputmode="numeric" autocomplete="off" placeholder="HH:MM" maxlength="5"
                    aria-label="Jam{{ $label ? ' · '.$label : '' }} (24 jam, WITA)" x-bind:list="$id('time-options')"
                    x-model="timeText" x-on:input="syncValue()" x-on:change="commitTime()" x-on:blur="commitTime()"
                    x-bind:aria-invalid="Boolean(error) || @js($attributes->get('aria-invalid') === 'true')" x-bind:aria-describedby="error ? $id('date-error') : $id('date-format')"
                    x-bind:class="error || @js($attributes->get('aria-invalid') === 'true') ? 'border-rose-400 dark:border-rose-500' : 'border-slate-200 dark:border-slate-700'"
                    @required($attributes->get('required')) @disabled($attributes->get('disabled')) @readonly($attributes->get('readonly'))
                    class="{{ $controlClasses }} {{ $spacingClasses }} pr-9 tabular-nums"
                >
                <span class="pointer-events-none absolute inset-y-0 right-2 flex items-center text-[9px] font-semibold text-slate-400">WITA</span>
                <datalist x-bind:id="$id('time-options')">
                    @for ($hour = 0; $hour < 24; $hour++)
                        <option value="{{ sprintf('%02d:00', $hour) }}"></option>
                        <option value="{{ sprintf('%02d:30', $hour) }}"></option>
                    @endfor
                </datalist>
            </div>
        @endif
    </div>
    <div class="mt-1.5 flex min-h-5 flex-wrap items-center justify-between gap-x-3 gap-y-1 text-[11px]">
        <span x-bind:id="$id('date-format')" class="text-slate-500 dark:text-slate-400">DD/MM/YYYY{{ $type === 'datetime-local' ? ' · Jam 24 jam' : '' }}</span>
        @unless ($attributes->get('disabled') || $attributes->get('readonly'))
            <div class="flex items-center gap-3">
                @if ($attributes->get('shortcut') === 'tomorrow-morning')
                    <button type="button" x-on:click="useTomorrowMorning()" x-bind:disabled="!tomorrowMorningAllowed" x-bind:title="tomorrowMorningAllowed ? null : 'Di luar rentang tanggal yang diizinkan'" class="py-1 font-medium text-blue-600 hover:underline disabled:cursor-not-allowed disabled:opacity-40 dark:text-blue-400">Besok jam 8 pagi</button>
                @else
                    <button type="button" x-on:click="useNow()" x-bind:disabled="!nowAllowed" x-bind:title="nowAllowed ? null : 'Di luar rentang tanggal yang diizinkan'" class="py-1 font-medium text-blue-600 hover:underline disabled:cursor-not-allowed disabled:opacity-40 dark:text-blue-400">{{ $type === 'datetime-local' ? 'Sekarang' : 'Hari ini' }}</button>
                @endif
                <button type="button" x-cloak x-show="dateText || timeText" x-on:click="clear()" aria-label="Kosongkan tanggal{{ $label ? ' · '.$label : '' }}" class="py-1 text-slate-500 hover:text-rose-600 dark:text-slate-400">Kosongkan</button>
            </div>
        @endunless
    </div>
    <p x-cloak x-show="error" x-text="error" x-bind:id="$id('date-error')" aria-live="polite" class="mt-1 text-xs leading-5 text-rose-600 dark:text-rose-400"></p>
    <div
        popover="auto" x-ref="calendar" x-bind:id="$id('date-calendar')" role="dialog" aria-label="Pilih tanggal"
        x-on:toggle="open = $event.newState === 'open'"
        x-on:keydown.escape.stop.prevent="closeCalendar()"
        class="date-picker-panel rounded-2xl border border-slate-200 bg-white p-4 text-slate-800 shadow-xl dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
    >
        <template x-if="open">
            <div>
                <div class="flex items-center gap-2">
                    <button type="button" x-on:click="changeMonth(-1)" aria-label="Bulan sebelumnya" class="flex size-10 shrink-0 items-center justify-center rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800"><x-dashboard.icon name="chevron" class="size-4 rotate-180" /></button>
                    <select aria-label="Bulan" x-model.number="month" x-on:change="changeMonth(0)" class="h-10 min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-2 text-sm font-semibold dark:border-slate-700 dark:bg-slate-900">
                        <template x-for="(label, index) in months" :key="index"><option x-bind:value="index" x-text="label"></option></template>
                    </select>
                    <input type="number" aria-label="Tahun" min="1" max="9999" inputmode="numeric" x-bind:value="year" x-on:change="changeYear($event.target.value); $event.target.value = year" x-on:keydown.enter.prevent="$event.target.blur()" class="h-10 w-[4.5rem] rounded-lg border border-slate-200 bg-white px-2 text-sm font-semibold tabular-nums dark:border-slate-700 dark:bg-slate-900">
                    <button type="button" x-on:click="changeMonth(1)" aria-label="Bulan berikutnya" class="flex size-10 shrink-0 items-center justify-center rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800"><x-dashboard.icon name="chevron" class="size-4" /></button>
                </div>
                <p class="sr-only" x-bind:id="$id('date-heading')" aria-live="polite" x-text="monthLabel"></p>
                <div role="grid" x-bind:aria-labelledby="$id('date-heading')" class="mt-3">
                    <div role="row" class="grid grid-cols-7">
                        <template x-for="day in weekdays" :key="day"><span role="columnheader" x-text="day" class="py-2 text-center text-[11px] font-medium text-slate-500 dark:text-slate-400"></span></template>
                    </div>
                    <template x-for="(week, index) in weeks" :key="index">
                        <div role="row" class="grid grid-cols-7 gap-y-0.5">
                            <template x-for="day in week" :key="day.iso">
                                <div role="gridcell" x-bind:aria-selected="day.iso === selectedDate" class="flex justify-center">
                                    <button
                                        type="button" x-text="day.day" x-bind:data-date="day.iso" x-bind:aria-label="fullDateLabel(day.iso)"
                                        x-bind:aria-current="day.iso === today ? 'date' : null"
                                        x-bind:disabled="!dateAllowed(day.iso)" x-bind:tabindex="day.iso === focusedDate ? 0 : -1"
                                        x-on:focus="focusedDate = day.iso" x-on:click="chooseDate(day.iso)" x-on:keydown="navigateDay($event, day.iso)"
                                        x-bind:class="day.iso === selectedDate ? 'bg-blue-600 font-semibold text-white hover:bg-blue-700' : (day.currentMonth ? 'text-slate-800 hover:bg-blue-50 dark:text-slate-100 dark:hover:bg-slate-800' : 'text-slate-400 hover:bg-slate-100 dark:text-slate-500 dark:hover:bg-slate-800')"
                                        class="flex aspect-square w-full max-w-10 items-center justify-center rounded-lg text-sm tabular-nums disabled:cursor-not-allowed disabled:opacity-25"
                                    ></button>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
                <div class="mt-3 flex items-center justify-between border-t border-slate-100 pt-3 dark:border-slate-800">
                    <button type="button" x-on:click="chooseDate(today)" x-bind:disabled="!dateAllowed(today)" class="rounded-lg px-3 py-2 text-xs font-medium text-blue-600 hover:bg-blue-50 disabled:opacity-40 dark:text-blue-400 dark:hover:bg-blue-950/40">Hari ini</button>
                    <button type="button" x-on:click="closeCalendar()" class="rounded-lg px-3 py-2 text-xs text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">Tutup</button>
                </div>
                <p class="mt-2 text-[10px] text-slate-400">Tombol panah untuk pindah hari · Esc untuk tutup</p>
            </div>
        </template>
    </div>
</div>
