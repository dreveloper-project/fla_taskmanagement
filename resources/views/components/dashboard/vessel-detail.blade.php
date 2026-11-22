@props(['vessel'])

<div>
    <div class="flex items-center justify-between gap-4 border-b border-slate-100 dark:border-slate-700 px-5 py-4 sm:px-6">
        <div><p class="text-[10px] tracking-wider text-slate-400 uppercase">Detail kapal</p><h2 id="vessel-detail-title" class="mt-1 text-lg font-semibold">{{ $vessel->vessel_name }}</h2></div>
        <button type="button" wire:click="closeDetail" aria-label="Tutup detail kapal" class="rounded-lg p-2 text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800"><x-dashboard.icon name="close" /></button>
    </div>
    <div class="flex flex-col gap-6 px-5 py-5 sm:px-6">
        <section class="space-y-4">
            @foreach (['active' => 'Active SPB', 'history' => 'SPB History'] as $group => $title)
                <h3 class="text-sm font-semibold">{{ $title }}</h3>
                @forelse ($vessel->spbServices->sortByDesc('id')->filter(fn ($spb) => ($group === 'active') === in_array($spb->status, \App\Models\SpbService::ACTIVE)) as $spb)
                    <div wire:key="vessel-spb-{{ $spb->id }}" class="space-y-3 rounded-xl border border-blue-100 dark:border-blue-900 bg-blue-50/50 dark:bg-blue-950/50 p-4">
                        <div class="flex flex-wrap justify-between gap-2"><a href="{{ route('spb.show', $spb) }}" class="text-sm font-semibold text-blue-600 dark:text-blue-400">{{ $spb->service_number }}</a><x-dashboard.spb-status :status="$spb->status" /></div>
                        <p class="text-xs text-slate-600 dark:text-slate-300">{{ $spb->departure_port }} → {{ $spb->destination }} · {{ $spb->estimated_departure_at->timezone(config('spb.timezone'))->format('d M Y H:i') }} WITA</p>
                        @if ($spb->spb_number)<p class="text-xs text-slate-600 dark:text-slate-300">SPB Number: {{ $spb->spb_number }}</p>@endif
                        @if ($spb->status === 'ready_to_sail' && $spb->actual_departure_at === null)
                            <p class="text-xs font-semibold text-emerald-700 dark:text-emerald-300">SPB terbit. Catat hasil SPB pada halaman detail.</p>
                        @elseif ($spb->actual_departure_at)
                            <p class="text-xs text-slate-600 dark:text-slate-300">Keberangkatan aktual: {{ $spb->actual_departure_at->timezone(config('spb.timezone'))->format('d M Y H:i') }} WITA</p>
                        @endif
                        <a href="{{ route('spb.show', $spb) }}" class="block text-xs font-medium text-blue-600 dark:text-blue-400">Lihat detail / catat hasil berlayar</a>
                    </div>
                @empty
                    <p class="text-xs text-slate-500 dark:text-slate-400">Belum ada {{ $title }}.</p>
                @endforelse
            @endforeach
        </section>
        @if ($vessel->profile_picture_path)
            <img src="{{ route('vessels.picture', $vessel) }}" alt="Foto {{ $vessel->vessel_name }}" class="h-48 w-full rounded-xl object-cover" />
        @else
        <div class="rounded-xl bg-blue-50 dark:bg-blue-950/40 text-blue-500 dark:text-blue-400"><x-dashboard.vessel-illustration :type="$vessel->vessel_type" class="h-36" /></div>
        @endif
        <section aria-labelledby="vessel-information-heading">
            <h3 id="vessel-information-heading" class="text-sm font-semibold">Vessel Information</h3>
            <dl class="mt-4 grid grid-cols-1 gap-5 min-[420px]:grid-cols-2">
                @foreach ([
                    'Nama kapal' => $vessel->vessel_name,
                    'Owner / Operator' => $vessel->ownerName(),
                    'Nakhoda (Sijil)' => $vessel->currentCaptain()?->full_name,
                    'Total crew (Sijil)' => $vessel->currentCrewCount().' orang',
                    'Kapasitas penumpang' => $vessel->passenger_capacity !== null ? $vessel->passenger_capacity.' orang' : null,
                    'Jenis kapal' => $vessel->typeLabel(),
                    'Flag / Bendera' => $vessel->flag,
                    'Port of Registry' => $vessel->port_of_registry,
                    'Registry Number' => $vessel->registry_number,
                    'Call sign' => $vessel->call_sign,
                    'Gross Tonnage (GT)' => $vessel->gross_tonnage,
                    'Net Tonnage (NT)' => $vessel->net_tonnage,
                    'LOA / Panjang (m)' => $vessel->loa,
                    'Breadth / Lebar (m)' => $vessel->breadth,
                    'Draft (m)' => $vessel->draft,
                    'Tahun pembuatan' => $vessel->build_year,
                    'Status kapal' => \App\Models\Vessel::STATUSES[$vessel->operational_status],
                ] as $label => $value)
                    <div wire:key="vessel-info-{{ $vessel->id }}-{{ $loop->index }}"><dt class="text-[11px] text-slate-400">{{ $label }}</dt><dd class="mt-1 text-xs leading-5 font-medium wrap-break-word">{{ $value ?? 'Belum diisi' }}</dd></div>
                @endforeach
            </dl>
            <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">Nakhoda mengikuti awak aktif dengan jabatan nakhoda yang diterapkan melalui Sijil.</p>
            @can('manage-services')<a href="{{ route('sijil.create', ['vessel_id' => $vessel->id]) }}" class="mt-2 inline-block text-xs font-semibold text-blue-600 dark:text-blue-400">Buat pengurusan Sijil</a>@endcan
        </section>

        <section aria-labelledby="safety-services-heading">
            <h3 id="safety-services-heading" class="text-sm font-semibold">Pengurusan Sertifikat Keselamatan</h3>
            <div class="mt-4 flex flex-col gap-3">
                @forelse ($vessel->safetyCertificateServices->sortByDesc('id') as $safetyService)
                    <div wire:key="vessel-safety-service-{{ $safetyService->id }}" class="rounded-xl border border-slate-200 dark:border-slate-700 p-4">
                        <div class="flex flex-wrap justify-between gap-2"><a href="{{ route('safety-certificates.show', $safetyService) }}" class="text-xs font-semibold text-blue-600 dark:text-blue-400">{{ $safetyService->service_number }}</a><x-dashboard.safety-status :status="$safetyService->status" /></div>
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ \App\Models\SafetyCertificateService::REQUEST_TYPES[$safetyService->request_type] }} · {{ $safetyService->certificate_number ?? 'Sertifikat belum terbit' }}</p>
                    </div>
                @empty<p class="text-xs text-slate-500 dark:text-slate-400">Belum ada pengurusan sertifikat keselamatan.</p>@endforelse
            </div>
        </section>
        <section aria-labelledby="bunker-services-heading">
            <div class="flex flex-wrap items-center justify-between gap-3"><h3 id="bunker-services-heading" class="text-sm font-semibold">Pengurusan Izin Bunker</h3>@can('manage-services')<x-dashboard.add-button :href="route('bunker.create', ['vessel_id' => $vessel->id])" compact variant="soft">Buat Izin Bunker</x-dashboard.add-button>@endcan</div>
            <div class="mt-4 flex flex-col gap-3">
                @forelse ($vessel->bunkerServices->sortByDesc('id') as $bunkerService)
                    <div wire:key="vessel-bunker-service-{{ $bunkerService->id }}" class="rounded-xl border border-slate-200 dark:border-slate-700 p-4">
                        <div class="flex flex-wrap justify-between gap-2"><a href="{{ route('bunker.show', $bunkerService) }}" class="text-xs font-semibold text-blue-600 dark:text-blue-400">{{ $bunkerService->service_number }}</a><x-dashboard.bunker-status :status="$bunkerService->status" /></div>
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ $bunkerService->supplier ?? 'Pemasok belum diisi' }} · {{ $bunkerService->scheduled_at?->timezone(config('bunker.timezone'))->format('d M Y H:i') ?? 'Jadwal belum diisi' }} WITA</p>
                    </div>
                @empty<p class="text-xs text-slate-500 dark:text-slate-400">Belum ada pengurusan izin bunker.</p>@endforelse
            </div>
        </section>
        <section aria-labelledby="nationality-services-heading">
            <div class="flex flex-wrap items-center justify-between gap-3"><h3 id="nationality-services-heading" class="text-sm font-semibold">Surat Laut / Pas Besar / Pas Kecil</h3>@can('manage-services')<x-dashboard.add-button :href="route('ship-nationality.create', ['vessel_id' => $vessel->id])" compact variant="soft">Buat pengurusan</x-dashboard.add-button>@endcan</div>
            @forelse ($vessel->shipNationalityServices->sortByDesc('id')->take(5) as $nationality)
                <div wire:key="vessel-nationality-{{ $nationality->id }}" class="mt-3 rounded-xl border border-slate-200 dark:border-slate-700 p-4"><a href="{{ route('ship-nationality.show', $nationality) }}" class="text-xs font-semibold text-blue-600 dark:text-blue-400">{{ $nationality->service_number }} &middot; {{ \App\Models\ShipNationalityService::DOCUMENT_TYPES[$nationality->document_type] }}</a><div class="mt-2"><x-dashboard.ship-nationality-status :status="$nationality->status" /></div></div>
            @empty<p class="mt-3 text-xs text-slate-500 dark:text-slate-400">Belum ada pengurusan dokumen kebangsaan.</p>@endforelse
        </section>
        <section aria-labelledby="sijil-services-heading">
            <div class="flex flex-wrap items-center justify-between gap-3"><h3 id="sijil-services-heading" class="text-sm font-semibold">Recent Sijil Services</h3>@can('manage-services')<x-dashboard.add-button :href="route('sijil.create', ['vessel_id' => $vessel->id])" compact variant="soft">Buat Sijil Awak Kapal</x-dashboard.add-button>@endcan</div>
            @forelse ($vessel->sijilServices->sortByDesc('id')->take(5) as $sijil)
                <div wire:key="vessel-sijil-{{ $sijil->id }}" class="mt-3 rounded-xl border border-slate-200 dark:border-slate-700 p-4"><a href="{{ route('sijil.show', $sijil) }}" class="text-xs font-semibold text-blue-600 dark:text-blue-400">{{ $sijil->service_number }}</a><div class="mt-2"><x-dashboard.sijil-status :status="$sijil->status" /></div></div>
            @empty<p class="mt-3 text-xs text-slate-500 dark:text-slate-400">Belum ada pengurusan sijil.</p>@endforelse
        </section>
        <section aria-labelledby="completed-sijil-heading">
            <h3 id="completed-sijil-heading" class="text-sm font-semibold">Sijil Awak Kapal Selesai</h3>
            <div class="mt-4 flex flex-col gap-3">
                @forelse ($vessel->sijilServices->where('status', 'completed')->sortByDesc('id') as $sijil)
                    <article wire:key="vessel-completed-sijil-{{ $sijil->id }}" class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <a href="{{ route('sijil.show', $sijil) }}" class="text-xs font-semibold text-blue-600 dark:text-blue-400">{{ $sijil->service_number }}</a>
                            <x-dashboard.sijil-status :status="$sijil->status" />
                        </div>
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Nomor sijil: {{ $sijil->result_reference ?? 'Belum diisi' }} · Tanggal terbit: {{ $sijil->issued_at?->format('d M Y') ?? 'Belum diisi' }}</p>
                        <div class="mt-3 flex flex-col items-start gap-2">
                            @forelse ($sijil->documents as $document)
                                <x-dashboard.download-button wire:key="vessel-sijil-result-{{ $document->id }}" :href="route('sijil.documents.download', [$sijil, $document])">{{ \App\Services\DocumentFilename::forService($document, $vessel) }}</x-dashboard.download-button>
                            @empty
                                <p class="text-xs text-slate-500 dark:text-slate-400">Dokumen hasil sijil belum tersedia.</p>
                            @endforelse
                        </div>
                    </article>
                @empty
                    <p class="text-xs text-slate-500 dark:text-slate-400">Belum ada sijil awak kapal yang selesai.</p>
                @endforelse
            </div>
        </section>
        @foreach (['active' => 'Awak aktif', 'history' => 'Crew History'] as $group => $title)
            <section wire:key="vessel-crew-section-{{ $group }}">
                <h3 class="text-sm font-semibold">{{ $title }}</h3>
                @forelse ($vessel->crewAssignments->sortByDesc('id')->filter(fn ($assignment) => ($group === 'active') === ($assignment->sign_off_at === null)) as $assignment)
                    <div wire:key="vessel-crew-{{ $assignment->id }}" class="mt-3 rounded-xl border border-slate-200 dark:border-slate-700 p-4">
                        <p class="text-sm font-semibold">{{ $assignment->full_name }} · {{ $assignment->rank }}</p>
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Sign On: {{ $assignment->sign_on_at->format('d M Y') }} · {{ $assignment->sign_off_at ? 'Sign Off: '.$assignment->sign_off_at->format('d M Y') : 'Active' }}</p>
                        <a href="{{ route('sijil.show', $assignment->sign_on_service_id) }}" class="mt-2 inline-block text-xs text-blue-600 dark:text-blue-400">Service Sign On</a>
                        @if ($assignment->sign_off_service_id)<a href="{{ route('sijil.show', $assignment->sign_off_service_id) }}" class="ml-3 text-xs text-blue-600 dark:text-blue-400">Service Sign Off</a>@endif
                    </div>
                @empty<p class="mt-3 text-xs text-slate-500 dark:text-slate-400">Belum ada {{ $title }}.</p>@endforelse
                @if ($group === 'history')
                    @foreach ($vessel->sijilServices->where('status', 'completed')->sortByDesc('id') as $sijil)
                        @foreach ($sijil->crews->where('action_type', 'update') as $row)
                            <div wire:key="crew-update-history-{{ $row->id }}" class="mt-3 rounded-xl border border-slate-200 dark:border-slate-700 p-4"><p class="text-xs font-semibold">Update: {{ $row->before_snapshot['full_name'] ?? $row->full_name }} · {{ $row->before_snapshot['rank'] ?? '-' }} → {{ $row->after_snapshot['rank'] ?? '-' }}</p><a href="{{ route('sijil.show', $sijil) }}" class="mt-2 inline-block text-xs text-blue-600 dark:text-blue-400">{{ $sijil->service_number }} · {{ $sijil->effective_at?->format('d M Y') }}</a></div>
                        @endforeach
                    @endforeach
                @endif
            </section>
        @endforeach
        <section aria-labelledby="document-alerts-heading">
            <h3 id="document-alerts-heading" class="text-sm font-semibold">Peringatan dokumen</h3>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Peringatan hingga {{ config('vessels.expiration_warning_days') }} hari sebelum kedaluwarsa, termasuk dokumen yang berakhir hari ini.</p>
            <div class="mt-4 flex flex-col gap-3">
                @forelse ($vessel->documents->sortBy(fn ($document) => $document->statusPriority())->filter(fn ($document) => in_array($document->status(), ['expired', 'expiring_soon'])) as $document)
                    <div wire:key="document-alert-{{ $document->id }}" class="rounded-xl border border-rose-100 dark:border-rose-900 bg-rose-50 dark:bg-rose-950/40 p-4">
                        <p class="text-xs font-semibold text-rose-700 dark:text-rose-300">{{ $document->statusLabel() }}</p>
                        <p class="mt-1 text-sm font-medium">{{ \App\Models\VesselDocument::TYPES[$document->document_type] }}</p>
                        <p class="mt-1 text-xs text-slate-600 dark:text-slate-300">Kedaluwarsa: {{ $document->expired_date->format('d M Y') }}</p>
                        @if ($document->document_type === 'safety_certificate')<p class="mt-2 text-xs text-amber-800 dark:text-amber-300">Sertifikat Keselamatan Kapal Penumpang akan / sudah kedaluwarsa.</p>@endif
                        @if ($document->needsService())
                            <div class="mt-3 flex flex-col items-start gap-2">
                                <p class="text-xs text-slate-600 dark:text-slate-300">Layanan: {{ \App\Models\VesselDocument::SERVICE_LABELS[\App\Models\VesselDocument::SERVICE_TYPES[$document->document_type]] }}</p>
                                @if ($serviceUrl = $document->serviceUrl())
                                    @can('manage-services')<x-dashboard.add-button :href="$serviceUrl" compact>Create Service</x-dashboard.add-button>@endcan
                                @else
                                    @can('manage-services')<x-dashboard.add-button disabled aria-describedby="service-unavailable-{{ $document->id }}" compact>Create Service</x-dashboard.add-button>@endcan
                                    <p id="service-unavailable-{{ $document->id }}" class="text-xs text-slate-500 dark:text-slate-400">Modul layanan belum tersedia.</p>
                                @endif
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="rounded-lg bg-slate-50 dark:bg-slate-800 p-3 text-xs text-slate-500 dark:text-slate-400">Tidak ada peringatan berdasarkan tanggal kedaluwarsa yang tersedia.</p>
                @endforelse
            </div>
        </section>

        <section aria-labelledby="documents-heading">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 id="documents-heading" class="text-sm font-semibold">Documents</h3>
                @can('manage-vessels')<x-dashboard.add-button wire:click="manageDocuments({{ $vessel->id }})" compact variant="soft">Tambah / Edit Dokumen</x-dashboard.add-button>@endcan
            </div>
            <div class="mt-4 flex flex-col gap-3">
                @forelse ($vessel->documents->sortBy(fn ($document) => $document->statusPriority()) as $document)
                    <article wire:key="vessel-document-{{ $document->id }}" class="rounded-xl border border-slate-200 dark:border-slate-700 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h4 class="text-sm font-medium">{{ \App\Models\VesselDocument::TYPES[$document->document_type] }}</h4>
                            <span @class(['rounded-full px-2.5 py-1 text-[10px] font-medium', 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300' => $document->status() === 'valid', 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300' => in_array($document->status(), ['expired', 'expiring_soon']), 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400' => $document->status() === 'unknown'])>{{ $document->statusLabel() }}</span>
                        </div>
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400 wrap-break-word">{{ \App\Services\DocumentFilename::forVessel($document, $vessel, $document->document_type) }}</p>
                        @if (in_array($document->document_type, ['safety_certificate', 'surat_laut', 'pas_besar', 'pas_kecil']))
                            <p class="mt-2 text-xs font-semibold">Current: {{ $document->certificate_number ?? 'Nomor belum diisi' }}</p>
                            @if ($document->source_ship_nationality_service_id)<a href="{{ route('ship-nationality.show', $document->source_ship_nationality_service_id) }}" class="mt-2 inline-block text-xs text-blue-600 dark:text-blue-400">Lihat sumber service</a>@endif
                            @if ($document->source_safety_certificate_service_id)<a href="{{ route('safety-certificates.show', $document->source_safety_certificate_service_id) }}" class="mt-2 inline-block text-xs text-blue-600 dark:text-blue-400">Lihat sumber service</a>@endif
                        @endif
                        <dl class="mt-3 grid gap-3 text-xs sm:grid-cols-2">
                            <div><dt class="text-slate-400">{{ $document->document_type === 'registry_endorsement' ? 'Tanggal pengukuhan' : 'Issued Date' }}</dt><dd class="mt-1">{{ $document->issued_date?->format('d M Y') ?? 'Belum diisi' }}</dd></div>
                            <div><dt class="text-slate-400">{{ $document->document_type === 'registry_endorsement' ? 'Pengukuhan berikutnya' : 'Expired Date' }}</dt><dd class="mt-1">{{ $document->expired_date?->format('d M Y') ?? ($document->document_type === 'registry_endorsement' ? 'Jadwal belum diisi' : 'Masa berlaku belum diketahui') }}</dd></div>
                        </dl>
                        @if ($document->isSafetyEquipment())
                            <div class="mt-3 rounded-lg bg-slate-50 p-3 text-xs dark:bg-slate-800">
                                <p class="font-semibold">{{ \App\Models\VesselDocument::EQUIPMENT_CONDITIONS[$document->equipment['condition'] ?? ''] ?? 'Belum diperiksa' }}</p>
                                <p class="mt-1">Jumlah: {{ $document->equipment['quantity'] ?? '-' }} unit @if ($document->document_type === 'liferaft') · Kapasitas: {{ $document->equipment['capacity'] ?? '-' }} orang @endif</p>
                                @if (filled($document->equipment['notes'] ?? null))<p class="mt-1">{{ $document->equipment['notes'] }}</p>@endif
                            </div>
                        @endif
                        <x-dashboard.download-button :href="route('vessel-documents.download', [$vessel->id, $document->id])" class="mt-3">Unduh dokumen</x-dashboard.download-button>
                        @if ($document->versions->isNotEmpty())
                            <details class="mt-4 border-t border-slate-100 dark:border-slate-700 pt-3"><summary class="cursor-pointer text-xs font-semibold text-slate-600 dark:text-slate-300">Previous Documents / Arsip</summary>
                                @foreach ($document->versions->sortByDesc('id') as $version)
                                    <div wire:key="vessel-document-version-{{ $version->id }}" class="mt-3 rounded-lg bg-slate-50 dark:bg-slate-800 p-3 text-xs">
                                        <p class="font-semibold">{{ $version->certificate_number ?? 'Nomor belum diisi' }} · Superseded</p>
                                        <p class="mt-1">Tanggal terbit: {{ $version->issued_date?->format('d M Y') ?? '-' }} · Tanggal kedaluwarsa: {{ $version->expired_date?->format('d M Y') ?? '-' }}</p>
                                        <p class="mt-1">Diarsipkan: {{ $version->archived_at->timezone(config('safety-certificates.timezone'))->format('d M Y H:i') }} WITA</p>
                                        <x-dashboard.download-button :href="route('vessel-document-versions.download', [$vessel->id, $version->id])" class="mt-2">{{ \App\Services\DocumentFilename::forVessel($version, $vessel, $document->document_type) }}</x-dashboard.download-button>
                                        @if ($version->source_ship_nationality_service_id)<a href="{{ route('ship-nationality.show', $version->source_ship_nationality_service_id) }}" class="ml-3 text-blue-600 dark:text-blue-400">Sumber service</a>@endif
                                        @if ($version->source_safety_certificate_service_id)<a href="{{ route('safety-certificates.show', $version->source_safety_certificate_service_id) }}" class="ml-3 text-blue-600 dark:text-blue-400">Sumber service</a>@endif
                                    </div>
                                @endforeach
                            </details>
                        @endif
                    </article>
                @empty
                    <p class="rounded-lg bg-slate-50 dark:bg-slate-800 p-5 text-center text-sm text-slate-500 dark:text-slate-400">Belum ada dokumen yang diunggah.</p>
                @endforelse
            </div>
        </section>
    </div>
    <div class="flex justify-end gap-2 border-t border-slate-100 dark:border-slate-700 px-5 py-4 sm:px-6">
        @can('manage-vessels')<a href="{{ route('vessels.edit', $vessel) }}" wire:navigate class="rounded-lg border border-slate-200 dark:border-slate-700 px-4 py-2 text-xs font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800">Edit vessel</a>@endcan
        <button type="button" wire:click="closeDetail" class="rounded-lg bg-blue-500 px-4 py-2 text-xs font-medium text-white hover:bg-blue-600">Tutup</button>
    </div>
</div>
