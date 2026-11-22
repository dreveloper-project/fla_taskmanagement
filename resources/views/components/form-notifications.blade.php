@php
    $successMessages = collect([
        'auth_status', 'profile_status', 'password_status', 'client_status', 'vessel_status',
        'spb_status', 'safety_status', 'bunker_status', 'sijil_status', 'nationality_status',
        'staff-success', 'task-success',
    ])->map(fn (string $key): mixed => session($key))->filter(fn (mixed $value): bool => is_string($value) && $value !== '')->values()->all();
    $initialNotification = $errors->any()
        ? ['type' => 'error', 'messages' => $errors->all()]
        : ($successMessages !== [] ? ['type' => 'success', 'messages' => $successMessages] : null);
@endphp

<dialog data-form-popup data-initial-notification="{{ json_encode($initialNotification) }}"
    aria-labelledby="form-popup-title" aria-describedby="form-popup-messages"
    class="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border-0 bg-white dark:bg-slate-900 p-6 text-slate-800 dark:text-slate-100 shadow-xl backdrop:bg-slate-950/40 backdrop:backdrop-blur-xs">
    <div class="flex flex-col items-center gap-4 text-center">
        <span data-popup-icon class="flex size-16 items-center justify-center rounded-full text-3xl font-semibold" aria-hidden="true"></span>
        <h2 id="form-popup-title" class="text-xl font-semibold"></h2>
        <ul id="form-popup-messages" class="max-h-56 w-full space-y-2 overflow-y-auto text-sm leading-6 text-slate-600 dark:text-slate-300"></ul>
        <button type="button" data-popup-close class="mt-1 w-full rounded-xl bg-blue-500 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-600">Mengerti</button>
    </div>
</dialog>
