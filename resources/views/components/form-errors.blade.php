@props(['fields' => [], 'exclude' => [], 'show' => true, 'id' => 'form-errors'])

@php
    $messages = collect($errors->getMessages())->filter(
        fn (array $fieldMessages, string $field): bool => ($fields === [] || \Illuminate\Support\Str::is($fields, $field))
            && ($exclude === [] || ! \Illuminate\Support\Str::is($exclude, $field))
    );
@endphp

@if ($show && $messages->isNotEmpty())
    <div data-form-error id="{{ $id }}" role="alert" aria-labelledby="{{ $id }}-title" tabindex="-1"
        x-data x-init="let parent = $el.parentElement; while (parent) { if (parent.tagName === 'DETAILS') parent.open = true; parent = parent.parentElement; } $el.focus({ preventScroll: true }); $el.scrollIntoView({ block: 'nearest' });"
        {{ $attributes->class(['col-span-full w-full rounded-lg border border-rose-200 dark:border-rose-900 bg-rose-50 dark:bg-rose-950/40 p-4 text-sm text-rose-700 dark:text-rose-300']) }}>
        <p id="{{ $id }}-title" class="font-semibold">Form belum dapat diproses. Periksa pesan berikut:</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            @foreach ($messages as $field => $fieldMessages)
                <li id="{{ $field }}-error" wire:key="{{ $id }}-{{ $field }}">
                    @foreach ($fieldMessages as $message)
                        <p wire:key="{{ $id }}-{{ $field }}-{{ $loop->index }}">{{ $message }}</p>
                    @endforeach
                </li>
            @endforeach
        </ul>
    </div>
@endif

<div data-file-upload-errors wire:ignore hidden tabindex="-1" class="col-span-full w-full rounded-lg border border-rose-200 dark:border-rose-900 bg-rose-50 dark:bg-rose-950/40 p-4 text-sm text-rose-700 dark:text-rose-300"></div>
