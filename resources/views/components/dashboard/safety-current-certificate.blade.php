@props(['certificate', 'vessel', 'renewal' => false, 'serviceId' => null])
<section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
    <h2 class="text-base font-semibold">Current Certificate</h2>
    @if ($certificate)
        <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
            <div><dt class="text-xs text-slate-400">Certificate Number</dt><dd>{{ $certificate->certificate_number ?? 'Belum diisi' }}</dd></div>
            <div><dt class="text-xs text-slate-400">Status</dt><dd>{{ $certificate->statusLabel() }}</dd></div>
            <div><dt class="text-xs text-slate-400">Issued Date</dt><dd>{{ $certificate->issued_date?->format('d M Y') ?? '-' }}</dd></div>
            <div><dt class="text-xs text-slate-400">Expired Date</dt><dd>{{ $certificate->expired_date?->format('d M Y') ?? '-' }}</dd></div>
        </dl>
        @if (in_array($certificate->status(), ['expired', 'expiring_soon']))
            <p class="mt-4 rounded-lg bg-amber-50 dark:bg-amber-950/40 p-3 text-sm text-amber-800 dark:text-amber-300">Sertifikat Keselamatan Kapal Penumpang akan / sudah kedaluwarsa.</p>
        @endif
        <x-dashboard.download-button :href="route('vessel-documents.download', [$vessel->id, $certificate->id])" class="mt-4">Unduh sertifikat aktif</x-dashboard.download-button>
    @else
        <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">Belum ada Sertifikat Keselamatan yang tersimpan pada Vessel.</p>
        @if ($renewal)<p class="mt-3 text-sm text-amber-700 dark:text-amber-300">Sertifikat lama belum tersedia pada data Vessel.</p>@endif
    @endif
    <a href="{{ route('vessels.edit', ['vessel' => $vessel, 'service_type' => 'safety-certificates', 'service_id' => $serviceId, 'request_type' => ! $serviceId ? ($renewal ? 'renewal' : 'new_issue') : null]).'#vessel-documents' }}" class="mt-4 block text-sm text-blue-600 dark:text-blue-400">Lengkapi di Vessel</a>
</section>

