@props(['spb'])

<div class="grid gap-4 sm:grid-cols-2">
    <x-auth.input name="actual_departure_at" label="Keberangkatan aktual (WITA) *" type="datetime-local" wire:model="departure.actual_departure_at" :min="$spb->spb_issued_at?->timezone(config('spb.timezone'))->format('Y-m-d\TH:i')" max="now" required />
    <x-auth.input name="expected_arrival_at" label="Perkiraan waktu tiba / ETA (WITA) *" type="datetime-local" wire:model="departure.expected_arrival_at" required />
    <div class="sm:col-span-2">
        <x-auth.input name="departure_notes" label="Catatan keberangkatan (opsional)" wire:model="departure.notes" maxlength="5000" />
    </div>
</div>
