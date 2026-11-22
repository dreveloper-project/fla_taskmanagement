@props(['checks', 'vessel', 'serviceType' => null, 'serviceId' => null, 'requestType' => 'new_issue'])
<section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
    <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-base font-semibold">Vessel Document Check</h2><a href="{{ route('vessels.edit', ['vessel' => $vessel, 'service_type' => $serviceId || $serviceType === 'safety-certificates' ? $serviceType : null, 'service_id' => $serviceId, 'request_type' => ! $serviceId && $serviceType === 'safety-certificates' ? $requestType : null]).'#vessel-documents' }}" class="text-xs font-semibold text-blue-600 dark:text-blue-400">Lengkapi di Vessel</a></div>
    @if (collect($checks)->contains(fn ($check) => $check['required'] && ! $check['complete']))<p class="mt-3 text-sm text-rose-700 dark:text-rose-300">Dokumen kapal belum lengkap.</p>@endif
    <dl class="mt-4 flex flex-col gap-3">
        @foreach ($checks as $check)
            <div wire:key="service-vessel-check-{{ $loop->index }}" class="flex flex-wrap justify-between gap-2 border-b border-slate-100 dark:border-slate-700 pb-3 text-xs"><dt>{{ $check['label'] }} · {{ $check['required'] ? 'Wajib' : 'Opsional' }}</dt><dd @class(['font-medium', 'text-emerald-700 dark:text-emerald-300' => $check['complete'], 'text-amber-700 dark:text-amber-300' => ! $check['complete']])>{{ $check['status'] }}</dd></div>
        @endforeach
    </dl>
    <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">Dokumen dibaca dari Vessel. Hanya persyaratan wajib yang menghalangi pengajuan; dokumen opsional tetap dapat dilengkapi.</p>
</section>

