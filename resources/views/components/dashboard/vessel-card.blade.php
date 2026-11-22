@props(['vessel'])

@php($condition = $vessel->documentCondition())
@php($captain = $vessel->currentCaptain())
@php($safetyCertificate = $vessel->documents->firstWhere('document_type', 'safety_certificate'))
<article {{ $attributes->class(['min-w-0 overflow-hidden rounded-xl border border-slate-200/80 dark:border-slate-700/80 bg-white dark:bg-slate-900 shadow-xs transition-shadow hover:shadow-md motion-reduce:transition-none']) }}>
    <div class="px-4 pt-4">
        <div class="flex items-center justify-between gap-2">
            <span class="text-[10px] font-medium tracking-wider text-slate-400">VSL-{{ str_pad($vessel->id, 3, '0', STR_PAD_LEFT) }}</span>
            <span @class(['inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-medium', 'bg-blue-50 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300' => $vessel->operational_status === 'sailing', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' => $vessel->operational_status === 'berthed', 'bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300' => $vessel->operational_status === 'maintenance'])><span class="size-1.5 rounded-full bg-current"></span>{{ \App\Models\Vessel::STATUSES[$vessel->operational_status] }}</span>
        </div>
        <div class="mt-3 flex items-center gap-3">
            <div @class(['flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-xl', 'bg-blue-50 text-blue-500 dark:bg-blue-950/70 dark:text-blue-400' => $vessel->vessel_type === 'passenger', 'bg-teal-50 text-teal-600 dark:bg-teal-950/50 dark:text-teal-400' => $vessel->vessel_type === 'tanker', 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' => ! in_array($vessel->vessel_type, ['passenger', 'tanker'])])>
                @if ($vessel->profile_picture_path)
                    <img src="{{ route('vessels.picture', $vessel) }}" alt="Foto {{ $vessel->vessel_name }}" class="aspect-square size-full object-cover" loading="lazy" />
                @else
                    <x-dashboard.vessel-illustration :type="$vessel->vessel_type" class="max-h-full" />
                @endif
            </div>
            <div class="min-w-0">
                <p class="text-[10px] tracking-wider text-slate-400 uppercase">{{ $vessel->typeLabel() }}</p>
                <h2 class="mt-1 text-base leading-snug font-semibold tracking-tight wrap-break-word">{{ $vessel->vessel_name }}</h2>
                <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400 wrap-break-word">{{ $vessel->ownerName() }}</p>
            </div>
        </div>
    </div>
    <div class="px-4 py-4">
        <dl class="grid grid-cols-2 gap-x-3 gap-y-3 border-t border-slate-100 pt-3 dark:border-slate-700">
            <div><dt class="text-[10px] text-slate-400">Nakhoda</dt><dd class="mt-1 text-xs font-medium wrap-break-word">{{ $captain?->full_name ?? 'Belum tersedia' }}</dd></div>
            <div><dt class="text-[10px] text-slate-400">Total crew</dt><dd class="mt-1 text-xs font-medium">{{ $vessel->currentCrewCount() }} orang</dd></div>
            <div><dt class="text-[10px] text-slate-400">Kapasitas penumpang</dt><dd class="mt-1 text-xs font-medium">{{ $vessel->passenger_capacity !== null ? $vessel->passenger_capacity.' orang' : 'Belum tersedia' }}</dd></div>
            <div><dt class="text-[10px] text-slate-400">Kedaluwarsa sertifikat keselamatan kapal penumpang</dt><dd class="mt-1 text-xs font-medium">{{ $safetyCertificate?->expired_date?->format('d M Y') ?? 'Belum tersedia' }}</dd></div>
        </dl>
        <div @class(['mt-4 flex items-center gap-2 rounded-lg px-3 py-2 text-[10px]', 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300' => $condition['tone'] === 'valid', 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300' => $condition['tone'] === 'warning', 'bg-slate-50 dark:bg-slate-800 text-slate-500 dark:text-slate-400' => $condition['tone'] === 'neutral'])>
            <x-dashboard.icon :name="$condition['tone'] === 'valid' ? 'check' : 'document'" class="size-3.5" />{{ $condition['label'] }}
        </div>
        @if ($vessel->operational_status === 'berthed')
            @php($departureSpb = $vessel->spbServices->where('status', 'ready_to_sail')->whereNull('actual_departure_at')->sortByDesc('id')->first())
            @if ($departureSpb)
                @can('work', $departureSpb)
                    <div class="mt-4 rounded-lg border border-blue-100 bg-blue-50/60 p-3 dark:border-blue-900 dark:bg-blue-950/30">
                        <p class="text-xs font-medium text-blue-800 dark:text-blue-200">SPB terbit · Siap berlayar</p>
                        <p class="mt-1 break-all text-[11px] text-slate-500 dark:text-slate-400">{{ $departureSpb->service_number }}</p>
                        <a href="{{ route('spb.show', $departureSpb) }}" class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700 dark:bg-blue-500 dark:hover:bg-blue-400"><x-dashboard.icon name="ship" class="size-4" />Catat hasil SPB</a>
                    </div>
                @endcan
            @endif
        @endif
    </div>
    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 dark:border-slate-700 px-5 py-3">
        <span class="text-[10px] text-slate-400">Bendera {{ $vessel->flag }}</span>
        <div class="flex gap-2">
@can('manage-vessels')            <a href="{{ route('vessels.edit', $vessel) }}" wire:navigate aria-label="Edit {{ $vessel->vessel_name }}" class="rounded-lg px-2 py-1.5 text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800">Edit</a>@endcan
            <button type="button" wire:click="detail({{ $vessel->id }})" wire:loading.attr="disabled" aria-haspopup="dialog" aria-label="Detail {{ $vessel->vessel_name }}" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-xs font-semibold text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-950/40">Detail <x-dashboard.icon name="arrow" class="size-3.5" /></button>
        </div>
    </div>
</article>
