<div class="grid gap-4 sm:grid-cols-2">
    <x-auth.input name="inspection_scheduled_at" label="Inspection Date & Time (WITA) *" type="datetime-local" wire:model="form.inspection_scheduled_at" required />
    <x-dashboard.port-input name="inspection_location" label="Inspection Location *" wire:model="form.inspection_location" required maxlength="255" />
    <x-auth.input name="inspector_name" label="Inspector / Authority Name (opsional)" wire:model="form.inspector_name" maxlength="255" />
    <x-auth.input name="inspection_notes" label="Inspection Notes (opsional)" wire:model="form.inspection_notes" maxlength="5000" />
</div>

