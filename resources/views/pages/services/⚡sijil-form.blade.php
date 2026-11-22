<?php

use App\Models\Crew;
use App\Models\SijilDocument;
use App\Models\SijilService;
use App\Models\Vessel;
use App\Services\SijilReadinessService;
use App\Services\SijilWorkflow;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Form Sijil Awak Kapal')] class extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $serviceId = null;

    /** @var array<string, mixed> */
    public array $form = ['vessel_id' => '', 'process_type' => 'sign_on', 'notes' => ''];


    /** @var array<int, array<string, mixed>> */
    public array $crews = [];

    /** @var array<int, array<string, mixed>> */
    public array $crewDocuments = [];

    public string $selectedCrewId = '';

    #[Computed]
    public function crewOptions(): Collection
    {
        return \App\Models\Crew::with('assignments')->orderBy('full_name')->get();
    }

    public function addCrew(): void
    {
        $this->validate(['selectedCrewId' => ['nullable', 'integer', 'exists:crews,id']]);
        $crew = $this->selectedCrewId !== '' ? $this->crewOptions->firstWhere('id', (int) $this->selectedCrewId) : null;
        $assignment = $crew?->assignments->whereNull('sign_off_at')->firstWhere('vessel_id', $this->form['vessel_id']);
        $this->crews[] = ['id' => null, 'crew_id' => $crew?->id, 'full_name' => $crew?->full_name ?? '', 'rank' => $assignment?->rank ?? '', 'seafarer_book_number' => $crew?->seafarer_book_number ?? '', 'competency_certificate_number' => $crew?->competency_certificate_number ?? '', 'action_type' => ($this->form['process_type'] === 'sign_off' || ($assignment && $this->form['process_type'] === 'crew_change')) ? 'sign_off' : ($assignment && $this->form['process_type'] === 'registration_update' ? 'update' : 'sign_on'), 'notes' => ''];
        $this->selectedCrewId = '';
        unset($this->existingCrewDocuments);
    }

    public function removeCrew(int $index): void
    {
        unset($this->crews[$index], $this->crewDocuments[$index]);
        unset($this->existingCrewDocuments);
    }

    public function boot(): void
    {
        Gate::authorize('access-system');
    }

    public function mount(?SijilService $service = null): void
    {
        if ($service?->exists) {
            Gate::authorize('work', $service);
            abort_unless($service->isEditable(), 403);
            $this->serviceId = $service->id;
            $this->form = $service->only(array_keys($this->form));
            $this->crews = $service->crews->map(fn ($row) => $row->only(['id', 'crew_id', 'full_name', 'rank', 'seafarer_book_number', 'competency_certificate_number', 'action_type', 'notes']))->all();
        } elseif (request()->filled('vessel_id')) {
            $data = Validator::make(request()->query(), ['vessel_id' => ['required', 'integer', 'exists:vessels,id']])->validate();
            $this->form['vessel_id'] = $data['vessel_id'];
        }
    }

    public function updatedFormVesselId(): void
    {
        unset($this->selectedVessel, $this->checks);
    }

    public function save(SijilWorkflow $workflow): void
    {
        try {
            $service = $workflow->saveDraft($this->serviceId ? SijilService::findOrFail($this->serviceId) : null, $this->form, $this->crews, [], $this->crewDocuments, applyImmediately: true);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($exception instanceof \Illuminate\Auth\Access\AuthorizationException || $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $exception;
            }
            report($exception);
            $this->addError('save', 'Penyimpanan gagal. Perubahan belum tersimpan. Silakan coba kembali.');

            return;
        }
        session()->flash('sijil_status', 'Perubahan awak berhasil diterapkan ke data vessel.');
        $this->redirectRoute('sijil.show', ['service' => $service->id]);
    }

    /** @return Collection<int, Vessel> */
    #[Computed]
    public function vessels(): Collection
    {
        return Vessel::query()->orderBy('vessel_name')->get();
    }

    #[Computed]
    public function selectedVessel(): ?Vessel
    {
        return $this->vessels->firstWhere('id', $this->form['vessel_id'] ?? null);
    }

    #[Computed]
    public function checks(): array
    {
        return $this->selectedVessel ? app(\App\Services\VesselDocumentReadiness::class)->check($this->selectedVessel, config('sijil.vessel_documents')) : [];
    }

    #[Computed]
    public function editingService(): ?SijilService
    {
        if ($this->serviceId === null) {
            return null;
        }

        $service = SijilService::with('crews.documents')->findOrFail($this->serviceId);
        Gate::authorize('work', $service);
        abort_unless($service->isEditable(), 403);

        return $service;
    }

    /** @return array<int, array<string, array{filename: string, url: string, available: bool}>> */
    #[Computed]
    public function existingCrewDocuments(): array
    {
        $service = $this->editingService;
        $crewIds = array_values(array_filter(array_column($this->crews, 'crew_id')));
        $crews = Crew::with('documents')->whereKey($crewIds)->get()->keyBy('id');
        $documents = [];
        foreach ($this->crews as $index => $row) {
            $serviceCrew = isset($row['id']) ? $service?->crews->firstWhere('id', $row['id']) : null;
            $crew = $crews->get($row['crew_id'] ?? null);
            foreach (array_keys(SijilDocument::CREW_TYPES) as $type) {
                $document = $serviceCrew?->documents->where('document_type', $type)->sortByDesc('id')->first()
                    ?? $crew?->documents->where('document_type', $type)->sortByDesc('id')->first();
                if ($document !== null) {
                    $documents[$index][$type] = $this->documentUploadMetadata($document);
                }
            }
        }

        return $documents;
    }

    /** @return array{filename: string, url: string, available: bool} */
    private function documentUploadMetadata(SijilDocument $document): array
    {
        return [
            'filename' => $document->original_filename,
            'url' => route('sijil.documents.download', [$document->sijil_service_id, $document->id]),
            'available' => Storage::disk('local')->exists($document->file_path),
        ];
    }

};
?>

<x-dashboard.shell page="Sijil Awak Kapal" :demo="false">
    <div class="flex flex-wrap items-center justify-between gap-4"><div><p class="text-xs text-slate-500 dark:text-slate-400">Sijil Awak Kapal</p><h1 class="mt-2 text-2xl font-semibold">{{ $serviceId ? 'Edit Service' : 'Buat Service Baru' }}</h1></div><x-dashboard.add-button :href="route('sijil.index')" variant="soft" icon="arrow" class="whitespace-nowrap [&_svg]:size-3.5 [&_svg]:rotate-180">Kembali ke monitoring</x-dashboard.add-button></div>
    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Perubahan awak langsung diterapkan ke data vessel setelah disimpan, dengan tanggal efektif hari ini (WITA).</p>
    <form novalidate wire:key="sijil-service-form" wire:submit="save" class="mt-6 flex flex-col gap-6" x-data="{ uploading: 0 }" x-on:livewire-upload-start="uploading++" x-on:livewire-upload-finish="uploading = Math.max(0, uploading-1)" x-on:livewire-upload-error="uploading = Math.max(0, uploading-1)" x-on:livewire-upload-cancel="uploading = Math.max(0, uploading-1)">
        <x-form-errors id="sijil-form-1-errors" />
        <section wire:key="sijil-service-information" class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
            <h2 class="text-base font-semibold">Service Information</h2>
            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <x-dashboard.vessel-selector :vessels="$this->vessels" :selected-vessel="$this->selectedVessel" :disabled="(bool) $serviceId" />
            </div>
        </section>
        <section wire:key="sijil-service-process" class="flex flex-col gap-5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
            <h2 class="text-base font-semibold">Pengurusan Sijil</h2>
            <div><label for="process_type" class="mb-2 block text-sm font-medium">Process Type *</label><select id="process_type" wire:model.live="form.process_type" class="w-full rounded-xl border border-slate-200 dark:border-slate-700 px-3 py-3 text-sm">@foreach (\App\Models\SijilService::PROCESS_TYPES as $key => $label)<option wire:key="sijil-process-{{ $key }}" value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
            <x-auth.input name="notes" label="Notes (opsional)" wire:model="form.notes" :value="$form['notes'] ?? ''" maxlength="5000" />
        </section>
        <section wire:key="sijil-service-crews" class="flex flex-col gap-5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
            <h2 class="text-base font-semibold">Crew Changes &amp; Documents</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400">Pilih awak kapal yang sudah terdaftar agar dokumen tersimpan digunakan kembali. Perubahan awak diterapkan saat disimpan.</p>
            <div class="flex flex-wrap gap-3"><select aria-label="Pilih crew existing" wire:model="selectedCrewId" class="min-w-0 flex-1 rounded-xl border border-slate-200 dark:border-slate-700 px-3 py-3 text-sm"><option value="">Crew baru</option>@foreach ($this->crewOptions as $crew)<option wire:key="crew-option-{{ $crew->id }}" value="{{ $crew->id }}">{{ $crew->full_name }} - {{ $crew->seafarer_book_number ?? 'Nomor belum diisi' }}</option>@endforeach</select><x-dashboard.add-button type="button" wire:click="addCrew" wire:loading.attr="disabled">Tambah Crew</x-dashboard.add-button></div>
            @foreach ($crews as $index => $row)
                <div wire:key="sijil-edit-crew-{{ $index }}" class="space-y-4 rounded-xl border border-slate-200 dark:border-slate-700 p-4">
                    <div class="flex justify-between gap-3"><p class="text-sm font-semibold">Crew {{ $loop->iteration }} {{ $row['crew_id'] ? '(existing)' : '(baru)' }}</p><button type="button" wire:click="removeCrew({{ $index }})" class="text-xs text-rose-600 dark:text-rose-400">Hapus dari pengajuan</button></div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach (['full_name' => 'Nama Lengkap *', 'rank' => 'Position / Rank *', 'seafarer_book_number' => 'Nomor Buku Pelaut', 'competency_certificate_number' => 'Nomor Sertifikat Kompetensi', 'notes' => 'Notes'] as $field => $label)
                            @if ($field === 'rank')
                                <div wire:key="crew-input-{{ $index }}-rank" class="relative"
                                    x-data="{
                                        positions: @js(collect(config('sijil.crew_positions'))->map(fn ($category, $position) => ['name' => $position, 'category' => $category])->values()->all()),
                                        query: @js($row['rank'] ?? ''), open: false, activeIndex: -1,
                                        get matches() { const term = this.query.trim().toLocaleLowerCase(); return this.positions.filter(item => (item.name + ' ' + item.category).toLocaleLowerCase().includes(term)); },
                                        choose(item) { if (!item) return; this.query = item.name; this.open = false; this.activeIndex = -1; $wire.$set('crews.{{ $index }}.rank', item.name, false); }
                                    }"
                                    @click.outside="open = false" @focusout="if (!$el.contains($event.relatedTarget)) open = false">
                                    <label for="crew-{{ $index }}-rank" class="mb-2 block text-sm font-medium">{{ $label }}</label>
                                    <input id="crew-{{ $index }}-rank" type="text" x-model="query" value="{{ $row['rank'] ?? '' }}"
                                        @focus="open = true" @input="open = true; activeIndex = -1; $wire.$set('crews.{{ $index }}.rank', query, false)"
                                        @keydown.escape.prevent="open = false" @keydown.arrow-down.prevent="open = true; activeIndex = Math.min(activeIndex + 1, matches.length - 1)"
                                        @keydown.arrow-up.prevent="open = true; activeIndex = Math.max(activeIndex - 1, 0)"
                                        @keydown.enter="if (open && matches.length) { $event.preventDefault(); choose(matches[activeIndex < 0 ? 0 : activeIndex]); }"
                                        role="combobox" aria-autocomplete="list" aria-controls="crew-{{ $index }}-positions" :aria-expanded="open.toString()"
                                        :aria-activedescendant="open && activeIndex >= 0 && matches[activeIndex] ? 'crew-{{ $index }}-position-' + positions.indexOf(matches[activeIndex]) : null"
                                        maxlength="255" autocomplete="off" placeholder="Cari atau ketik jabatan"
                                        class="w-full rounded-xl border border-slate-200 dark:border-slate-700 px-3 py-3 text-sm">
                                    <div x-cloak x-show="open" id="crew-{{ $index }}-positions" role="listbox" class="absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white py-1 shadow-lg dark:border-slate-700 dark:bg-slate-900">
                                        <template x-for="(position, positionIndex) in matches" :key="position.name">
                                            <button type="button" :id="'crew-{{ $index }}-position-' + positions.indexOf(position)" role="option" :aria-selected="query === position.name" @mousedown.prevent @click="choose(position)" @mousemove="activeIndex = positionIndex" :class="activeIndex === positionIndex ? 'bg-blue-50 dark:bg-slate-800' : ''" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-blue-50 dark:hover:bg-slate-800"><span x-text="position.name"></span><span class="text-xs text-slate-500 dark:text-slate-400" x-text="position.category"></span></button>
                                        </template>
                                        <p x-show="matches.length === 0" class="px-3 py-2 text-sm text-slate-500 dark:text-slate-400">Jabatan tidak ditemukan. Anda dapat memasukkan jabatan lain.</p>
                                    </div>
                                </div>
                            @else
                                <div wire:key="crew-input-{{ $index }}-{{ $field }}"><label for="crew-{{ $index }}-{{ $field }}" class="mb-2 block text-sm font-medium">{{ $label }}</label><input id="crew-{{ $index }}-{{ $field }}" wire:model="crews.{{ $index }}.{{ $field }}" value="{{ $row[$field] ?? '' }}" maxlength="{{ $field === 'notes' ? 5000 : 255 }}" class="w-full rounded-xl border border-slate-200 dark:border-slate-700 px-3 py-3 text-sm"></div>
                            @endif
                        @endforeach
                        <div><label for="action-{{ $index }}" class="mb-2 block text-sm font-medium">Action *</label><select id="action-{{ $index }}" wire:model="crews.{{ $index }}.action_type" @disabled($row['id']) class="w-full rounded-xl border border-slate-200 dark:border-slate-700 px-3 py-3 text-sm">@foreach (\App\Models\SijilService::ACTIONS as $key => $label)<option wire:key="crew-action-{{ $index }}-{{ $key }}" value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
                    </div>
                    @if (! $row['crew_id'])<p class="text-xs text-slate-500 dark:text-slate-400">Semua dokumen untuk crew baru bersifat opsional.</p>@endif
                    <x-dashboard.service-uploads :max-kb="config('sijil.document_max_kb')" :types="\App\Models\SijilDocument::CREW_TYPES" :prefix="'sijil-crew-'.$index" :model="'crewDocuments.'.$index" :existing="$this->existingCrewDocuments[$index] ?? []" :selected="$crewDocuments[$index] ?? []" />
                </div>
            @endforeach
        </section>
        <div wire:key="sijil-vessel-check">@if ($this->selectedVessel)<x-dashboard.service-vessel-check :checks="$this->checks" :vessel="$this->selectedVessel" service-type="sijil" :service-id="$serviceId" />@endif</div>
        <div wire:key="sijil-save-actions" class="flex justify-end gap-3"><button type="submit" wire:loading.attr="disabled" x-bind:disabled="uploading > 0" class="rounded-lg bg-blue-500 px-5 py-3 text-sm font-semibold text-white disabled:opacity-50"><span wire:loading.remove wire:target="save">Simpan Perubahan</span><span wire:loading.inline-flex wire:target="save" class="items-center gap-2"><span aria-hidden="true" class="size-3.5 animate-spin rounded-full border-2 border-current border-t-transparent motion-reduce:animate-none"></span>Menyimpan...</span></button></div>
    </form>
</x-dashboard.shell>
