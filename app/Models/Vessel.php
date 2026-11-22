<?php

namespace App\Models;

use App\Services\ImageOptimizer;
use Carbon\CarbonImmutable;
use Database\Factories\VesselFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * @property string|null $profile_picture_path
 * @property int $id
 * @property int|null $client_id
 * @property string $vessel_name
 * @property string $vessel_type
 * @property string|null $owner
 * @property string|null $captain_name
 * @property int|null $total_crew
 * @property int|null $passenger_capacity
 * @property string $flag
 * @property string|null $port_of_registry
 * @property string|null $registry_number
 * @property string|null $call_sign
 * @property string|null $gross_tonnage
 * @property string|null $net_tonnage
 * @property string|null $loa
 * @property string|null $breadth
 * @property string|null $draft
 * @property int|null $build_year
 * @property string $operational_status
 * @property-read Client|null $client
 * @property-read Collection<int, VesselDocument> $documents
 * @property-read Collection<int, SpbService> $spbServices
 * @property-read Collection<int, SafetyCertificateService> $safetyCertificateServices
 * @property-read Collection<int, BunkerService> $bunkerServices
 * @property-read Collection<int, SijilService> $sijilServices
 * @property-read Collection<int, VesselCrew> $crewAssignments
 * @property CarbonImmutable|null $sailed_at
 * @property int|null $confirmed_by
 */
#[Fillable(['client_id', 'vessel_name', 'vessel_type', 'owner', 'passenger_capacity', 'flag', 'port_of_registry', 'registry_number', 'call_sign', 'gross_tonnage', 'net_tonnage', 'loa', 'breadth', 'draft', 'build_year', 'operational_status'])]
class Vessel extends Model
{
    /** @use HasFactory<VesselFactory> */
    use HasFactory;

    public const TYPES = [
        'cargo' => 'Kapal Barang',
        'passenger' => 'Kapal Penumpang',
    ];

    public const STATUSES = [
        'berthed' => 'Sandar',
        'sailing' => 'Berlayar',
        'maintenance' => 'Perawatan',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'gross_tonnage' => 'decimal:2',
            'net_tonnage' => 'decimal:2',
            'loa' => 'decimal:2',
            'breadth' => 'decimal:2',
            'draft' => 'decimal:2',
            'build_year' => 'integer',
            'total_crew' => 'integer',
            'passenger_capacity' => 'integer',
            'sailed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return HasMany<VesselDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(VesselDocument::class)->chaperone();
    }

    public function currentCrewCount(): int
    {
        return $this->relationLoaded('crewAssignments')
            ? $this->crewAssignments->whereNull('sign_off_at')->count()
            : $this->crewAssignments()->whereNull('sign_off_at')->count();
    }

    public function currentCaptain(): ?VesselCrew
    {
        return $this->crewAssignments
            ->whereNull('sign_off_at')
            ->sortByDesc('id')
            ->first(fn (VesselCrew $assignment): bool => in_array(mb_strtolower(trim($assignment->rank)), ['master', 'captain', 'nakhoda', 'nahkoda'], true));
    }

    /** @return HasMany<SpbService, $this> */
    public function spbServices(): HasMany
    {
        return $this->hasMany(SpbService::class);
    }

    /** @return HasMany<ShipNationalityService, $this> */
    public function shipNationalityServices(): HasMany
    {
        return $this->hasMany(ShipNationalityService::class);
    }

    public function ownerName(): string
    {
        return $this->owner ?? 'Belum diisi';
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->vessel_type] ?? match ($this->vessel_type) {
            'fishing' => 'Fishing / Penangkap ikan (jenis lama)',
            'tanker' => 'Tanker (jenis lama)',
            'tugboat' => 'Tugboat (jenis lama)',
            'other' => 'Lainnya (jenis lama)',
            default => 'Jenis kapal belum dikenali',
        };
    }

    /** @return HasMany<SafetyCertificateService, $this> */
    public function safetyCertificateServices(): HasMany
    {
        return $this->hasMany(SafetyCertificateService::class);
    }

    /** @return HasMany<BunkerService, $this> */
    public function bunkerServices(): HasMany
    {
        return $this->hasMany(BunkerService::class);
    }

    /** @return HasMany<SijilService, $this> */
    public function sijilServices(): HasMany
    {
        return $this->hasMany(SijilService::class);
    }

    /** @return HasMany<VesselCrew, $this> */
    public function crewAssignments(): HasMany
    {
        return $this->hasMany(VesselCrew::class);
    }

    /** @return array{label: string, tone: string} */
    public function documentCondition(): array
    {
        if ($this->documents->isEmpty()) {
            return ['label' => 'Belum ada dokumen yang diunggah.', 'tone' => 'neutral'];
        }

        $counts = $this->documents->countBy(fn (VesselDocument $document): string => $document->status());
        $parts = [];

        if ($counts->get('expired', 0) > 0) {
            $parts[] = 'Dokumen kedaluwarsa: '.$counts->get('expired');
        }
        if ($counts->get('expiring_soon', 0) > 0) {
            $parts[] = 'Dokumen segera kedaluwarsa: '.$counts->get('expiring_soon');
        }
        if ($parts !== []) {
            return ['label' => implode(' · ', $parts), 'tone' => 'warning'];
        }
        if ($counts->get('unknown', 0) > 0) {
            return ['label' => 'Masa berlaku belum diketahui: '.$counts->get('unknown'), 'tone' => 'neutral'];
        }

        return ['label' => 'Semua dokumen masih berlaku', 'tone' => 'valid'];
    }

    /**
     * Save the vessel and current documents atomically, compensating filesystem writes on failure.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array{file?: UploadedFile|null, issued_date?: string|null, expired_date?: string|null, equipment?: array{condition?: string|null, quantity?: int|null, capacity?: int|null, notes?: string|null}}>  $documents
     */
    public function saveWithDocuments(array $attributes, array $documents): void
    {
        $newPaths = [];
        $oldPaths = [];
        $actor = auth()->user();
        $actorId = $actor instanceof User ? $actor->id : null;

        try {
            DB::transaction(function () use ($attributes, $documents, $actorId, &$newPaths, &$oldPaths): void {
                if ($this->exists) {
                    $current = static::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();
                    if (isset($attributes['vessel_type']) && $attributes['vessel_type'] !== $current->vessel_type
                        && $current->safetyCertificateServices()->whereIn('status', SafetyCertificateService::ACTIVE)->exists()) {
                        throw ValidationException::withMessages(['form.vessel_type' => 'Jenis kapal tidak dapat diganti selama pengurusan sertifikat keselamatan aktif.']);
                    }
                    if (isset($attributes['vessel_type']) && $attributes['vessel_type'] !== $current->vessel_type
                        && $current->spbServices()->whereIn('status', SpbService::ACTIVE)->exists()) {
                        throw ValidationException::withMessages(['form.vessel_type' => 'Jenis kapal tidak dapat diganti selama SPB aktif.']);
                    }
                    if (isset($attributes['operational_status']) && $attributes['operational_status'] !== $current->operational_status
                        && $current->spbServices()->where('status', 'sailing')->exists()) {
                        throw ValidationException::withMessages(['form.operational_status' => 'Selesaikan SPB yang masih berstatus sailing sebelum mengubah status kapal.']);
                    }
                }

                $this->fill($attributes)->save();
                $existingDocuments = $this->documents()->get()->keyBy('document_type');

                foreach ($documents as $type => $data) {
                    $document = $existingDocuments->get($type);
                    $file = $data['file'] ?? null;

                    if ($file === null && $document === null) {
                        continue;
                    }

                    $document ??= $this->documents()->make(['document_type' => $type]);
                    $archive = $document->exists && (in_array($type, ['safety_certificate', 'surat_laut', 'pas_besar', 'pas_kecil', 'registry_endorsement'], true) || $document->isSafetyEquipment())
                        && ($file !== null || $document->issued_date?->toDateString() !== ($data['issued_date'] ?? null)
                            || $document->expired_date?->toDateString() !== ($data['expired_date'] ?? null)
                            || (array_key_exists('equipment', $data) && $document->equipment != $data['equipment']));
                    if ($archive) {
                        $document->archiveCurrent($actorId);
                    }
                    $document->fill([
                        'issued_date' => $data['issued_date'] ?? null,
                        'expired_date' => $data['expired_date'] ?? null,
                    ]);
                    if ($document->isSafetyEquipment() && array_key_exists('equipment', $data)) {
                        $document->equipment = $data['equipment'];
                    }

                    if ($file !== null) {
                        $path = app(ImageOptimizer::class)->store($file, 'vessels/'.$this->id);
                        if ($path === false) {
                            throw new RuntimeException('Dokumen vessel gagal disimpan.');
                        }
                        $newPaths[] = $path;

                        if ($document->exists && ! $archive) {
                            $oldPaths[] = $document->file_path;
                        }

                        $document->file_path = $path;
                        if (in_array($type, ['safety_certificate', 'surat_laut', 'pas_besar', 'pas_kecil'], true)) {
                            $document->certificate_number = null;
                            $document->source_safety_certificate_service_id = null;
                            $document->source_ship_nationality_service_id = null;
                        }
                        $document->original_filename = mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255);
                    }

                    $document->save();
                }
            });
        } catch (Throwable $exception) {
            $this->deleteDocumentFiles($newPaths);
            throw $exception;
        }

        $this->deleteDocumentFiles($oldPaths);
        $this->unsetRelation('documents');
    }

    /** @param list<string> $paths */
    private function deleteDocumentFiles(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                if (! Storage::disk('local')->delete($path)) {
                    report(new RuntimeException('Gagal membersihkan file vessel: '.$path));
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
