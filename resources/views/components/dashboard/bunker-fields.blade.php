@props(['required' => false])
<div class="grid gap-4 sm:grid-cols-2">
    <x-auth.input name="supplier" label="Supplier *" wire:model="form.supplier" maxlength="255" :required="$required" />
    <x-dashboard.port-input name="bunker_location" label="Bunker Location *" wire:model="form.bunker_location" maxlength="255" :required="$required" />
    <x-auth.input name="scheduled_at" label="Scheduled Bunker Date & Time (WITA) *" type="datetime-local" wire:model="form.scheduled_at" :required="$required" />
    <x-auth.input name="quantity" label="Quantity *" type="number" step="0.001" min="0.001" max="999999999999.999" wire:model="form.quantity" :required="$required" />
    <div><label for="unit" class="mb-2 block text-sm font-medium">Unit *</label><select id="unit" wire:model.live="form.unit" @required($required) class="w-full rounded-xl border border-slate-200 dark:border-slate-700 px-3 py-3 text-sm"><option value="">Pilih satuan</option>@foreach (\App\Models\BunkerService::UNITS as $key => $label)<option wire:key="bunker-unit-{{ $key }}" value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
    <x-auth.input name="unit_other" label="Nama satuan jika Other" wire:model="form.unit_other" maxlength="50" />
</div>
