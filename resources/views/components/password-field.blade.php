@props(['enabled' => true, 'inputId' => null])

@if ($enabled)
    <div {{ $attributes->class(['relative']) }} x-data="{ passwordVisible: false }">
        {{ $slot }}
        <button
            type="button"
            aria-label="Tampilkan password"
            x-bind:aria-label="passwordVisible ? 'Sembunyikan password' : 'Tampilkan password'"
            x-bind:title="passwordVisible ? 'Sembunyikan password' : 'Tampilkan password'"
            x-bind:aria-pressed="passwordVisible"
            @if ($inputId) aria-controls="{{ $inputId }}" @endif
            x-on:click="passwordVisible = !passwordVisible"
            class="absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r-lg text-slate-500 dark:text-slate-400 transition-colors hover:text-blue-600 dark:hover:text-blue-400 focus-visible:outline-2 focus-visible:outline-blue-500"
        >
            <svg x-show="!passwordVisible" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" /><circle cx="12" cy="12" r="3" />
            </svg>
            <svg x-cloak x-show="passwordVisible" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m3 3 18 18M10.6 5.1A12 12 0 0 1 12 5c6.5 0 10 7 10 7a18 18 0 0 1-3.1 4.1M6.1 6.1A19 19 0 0 0 2 12s3.5 7 10 7a12 12 0 0 0 5.9-1.9M9.9 9.9a3 3 0 0 0 4.2 4.2" />
            </svg>
        </button>
    </div>
@else
    {{ $slot }}
@endif
