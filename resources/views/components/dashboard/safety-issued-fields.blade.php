<div class="grid gap-4 sm:grid-cols-2">
    <x-auth.input name="certificate_number" label="Certificate Number *" wire:model="form.certificate_number" required maxlength="255" />
    <x-auth.input name="certificate_issued_at" label="Issued Date *" type="date" wire:model="form.certificate_issued_at" required />
    <x-auth.input name="certificate_expired_at" :label="config('safety-certificates.certificate_requires_expiry') ? 'Expired Date *' : 'Expired Date (opsional)'" type="date" wire:model="form.certificate_expired_at" :required="config('safety-certificates.certificate_requires_expiry')" />
    <x-auth.input name="issuing_authority" label="Issuing Authority (opsional)" wire:model="form.issuing_authority" maxlength="255" />
</div>
<x-dashboard.service-uploads :max-kb="config('safety-certificates.document_max_kb')" :types="['issued_certificate' => 'Certificate File resmi (wajib)']" />
