@props(['checks', 'vessel', 'serviceId' => null])
<section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
    <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-sm font-semibold">Vessel Document Check</h2><a href="{{ route('vessels.edit', ['vessel' => $vessel, 'service_type' => $serviceId ? 'spb' : null, 'service_id' => $serviceId]).'#vessel-documents' }}" class="text-xs font-semibold text-blue-600 dark:text-blue-400">Lengkapi di Vessel →</a></div>
    @if (collect($checks)->contains(fn ($check) => $check['required'] && ! $check['complete']))<p class="mt-3 text-sm text-amber-700 dark:text-amber-300">Peringatan: dokumen atau perlengkapan kapal belum lengkap. Proses SPB tetap dapat dilanjutkan.</p>@endif
    <dl class="mt-4 flex flex-col gap-3">
        @foreach ($checks as $check)
            <div wire:key="vessel-readiness-{{ $loop->index }}" class="flex flex-wrap justify-between gap-2 border-b border-slate-100 dark:border-slate-700 pb-3 text-xs"><dt>{{ $check['label'] }} · {{ $check['required'] ? 'Perlu diperhatikan' : 'Opsional' }}</dt><dd @class(['font-medium', 'text-emerald-700 dark:text-emerald-300' => $check['complete'], 'text-amber-700 dark:text-amber-300' => ! $check['complete']])>{{ $check['status'] }}</dd></div>
        @endforeach
    </dl>
    <p class="mt-3 text-xs leading-5 text-slate-500 dark:text-slate-400">Dokumen diambil dari Vessel. Pemeriksaan sertifikat memperhatikan masa berlaku hingga jadwal keberangkatan. Kelengkapan dokumen dan perlengkapan kapal ditampilkan sebagai peringatan dan tidak menghalangi proses SPB.</p>
</section>
