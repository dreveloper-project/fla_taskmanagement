<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\VesselDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Route;

/**
 * @property int $id
 * @property int $vessel_id
 * @property string $document_type
 * @property string $file_path
 * @property string $original_filename
 * @property CarbonImmutable|null $issued_date
 * @property CarbonImmutable|null $expired_date
 * @property string|null $certificate_number
 * @property array{condition?: string|null, quantity?: int|null, capacity?: int|null, notes?: string|null}|null $equipment
 * @property int|null $source_ship_nationality_service_id
 * @property int|null $source_safety_certificate_service_id
 * @property-read Collection<int, VesselDocumentVersion> $versions
 * @property-read Vessel $vessel
 */
#[Fillable(['document_type', 'file_path', 'original_filename', 'issued_date', 'expired_date', 'certificate_number', 'source_safety_certificate_service_id', 'source_ship_nationality_service_id', 'equipment'])]
class VesselDocument extends Model
{
    /** @use HasFactory<VesselDocumentFactory> */
    use HasFactory;

    public const TYPES = [
        'surat_penunjukan_keagenan' => 'Surat Penunjukan Keagenan',
        'grosse_akta' => 'Grosse Akta',
        'surat_ukur' => 'Surat Ukur',
        'surat_laut' => 'Surat Laut',
        'pas_besar' => 'Pas Besar',
        'pas_kecil' => 'Pas Kecil',
        'registry_endorsement' => 'Endorsement Port of Registry / Pengukuhan Kebangsaan',
        'ship_particulars' => 'Ship Particulars',
        'surat_moring' => 'Surat Moring',
        'rpk' => 'RPK',
        'general_arrangement' => 'General Arrangement',
        'safety_certificate' => 'Sertifikat Keselamatan Kapal Penumpang',
        'safe_manning' => 'Safe Manning',
        'load_line' => 'Garis Muat (Load Line Certificate)',
        'radio_certificate' => 'Radio Certificate',
        'classification_certificate' => 'Classification Certificate',
        'smc' => 'SMC',
        'sscc' => 'SSCC',
        'k3' => 'Sertifikat Kesehatan Kapal (P3k)',
        'insurance' => 'Asuransi Kapal',
        'sijil_awak_kapal' => 'Sijil Awak Kapal',
        'liferaft' => 'Liferaft / ILR — Sertifikat Service',
        'fire_fighting' => 'Sistem Pemadam PMK / APAR — Sertifikat Pemeriksaan',
        'life_jacket' => 'Life Jacket Info — Record Kondisi & Jumlah',
        'life_buoy' => 'Life Buoy — Record Kondisi & Jumlah',
        'emergency_equipment' => 'Emergency Equipment — Record / Checklist Kesiapan',
        'sopep_equipment' => 'SOPEP Equipment — Checklist Kesiapan',
    ];

    public const EQUIPMENT_TYPES = ['liferaft', 'fire_fighting', 'life_jacket', 'life_buoy', 'emergency_equipment', 'sopep_equipment'];

    public const EQUIPMENT_CONDITIONS = ['ready' => 'Siap / kondisi baik', 'not_ready' => 'Belum siap / perlu perbaikan', 'not_applicable' => 'Tidak berlaku (khusus SPB kapal barang)'];

    public function isSafetyEquipment(): bool
    {
        return in_array($this->document_type, self::EQUIPMENT_TYPES, true);
    }

    public function equipmentReadinessIssue(bool $allowNotApplicable = false, int $minimumPersons = 0): ?string
    {
        $condition = $this->equipment['condition'] ?? null;
        if ($condition === 'not_applicable' && $allowNotApplicable && filled($this->equipment['notes'] ?? null)) {
            return null;
        }
        if ($condition !== 'ready') {
            return $condition === 'not_applicable' ? 'Tidak berlaku belum dapat diterima untuk layanan ini' : 'Kondisi alat belum dinyatakan siap';
        }
        if (in_array($this->document_type, ['liferaft', 'fire_fighting', 'life_jacket', 'life_buoy'], true) && ($this->equipment['quantity'] ?? 0) < 1) {
            return 'Jumlah alat belum diisi atau tidak mencukupi';
        }
        if ($this->document_type === 'liferaft' && ($this->equipment['capacity'] ?? 0) < max(1, $minimumPersons)) {
            return 'Kapasitas Liferaft / ILR tidak mencukupi jumlah orang di kapal';
        }
        if ($this->document_type === 'life_jacket' && ($this->equipment['quantity'] ?? 0) < $minimumPersons) {
            return 'Jumlah Life Jacket tidak mencukupi jumlah orang di kapal';
        }

        return null;
    }

    public const SERVICE_TYPES = [
        'safety_certificate' => 'safety_certificate',
        'surat_laut' => 'ship_nationality',
        'pas_besar' => 'ship_nationality',
        'pas_kecil' => 'ship_nationality',
        'sijil_awak_kapal' => 'crew_sign_on',
    ];

    public const SERVICE_LABELS = [
        'safety_certificate' => 'Sertifikat Keselamatan Kapal Penumpang',
        'ship_nationality' => 'Surat Laut / Pas Besar / Pas Kecil',
        'crew_sign_on' => 'Sijil Awak Kapal',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['issued_date' => 'immutable_date', 'expired_date' => 'immutable_date', 'equipment' => 'array'];
    }

    /** @return BelongsTo<Vessel, $this> */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    public function status(): string
    {
        if ($this->expired_date === null) {
            return 'unknown';
        }
        $date = $this->expired_date->toDateString();
        foreach (self::expirationRanges() as $status => $range) {
            if (($range['from'] === null || $date >= $range['from']) && ($range['to'] === null || $date <= $range['to'])) {
                return $status;
            }
        }

        return 'valid';
    }

    /** @return array<string, array{from: string|null, to: string|null}> */
    public static function expirationRanges(): array
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        $warning = $today->addDays((int) config('vessels.expiration_warning_days', 30));

        return [
            'expired' => ['from' => null, 'to' => $today->subDay()->toDateString()],
            'expiring_soon' => ['from' => $today->toDateString(), 'to' => $warning->toDateString()],
            'valid' => ['from' => $warning->addDay()->toDateString(), 'to' => null],
        ];
    }

    /** @return HasMany<VesselDocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(VesselDocumentVersion::class);
    }

    /** @return BelongsTo<SafetyCertificateService, $this> */
    public function sourceService(): BelongsTo
    {
        return $this->belongsTo(SafetyCertificateService::class, 'source_safety_certificate_service_id');
    }

    public function archiveCurrent(?int $actorId): VesselDocumentVersion
    {
        return $this->versions()->create($this->only([
            'certificate_number', 'file_path', 'original_filename', 'issued_date', 'expired_date', 'source_safety_certificate_service_id', 'source_ship_nationality_service_id', 'equipment',
        ]) + ['status' => 'superseded', 'archived_at' => now(), 'archived_by' => $actorId]);
    }

    public function statusLabel(): string
    {
        if ($this->document_type === 'registry_endorsement') {
            return match ($this->status()) {
                'expired' => 'Pengukuhan lewat jatuh tempo',
                'expiring_soon' => 'Pengukuhan segera jatuh tempo',
                'valid' => 'Pengukuhan belum jatuh tempo',
                default => 'Jadwal pengukuhan berikutnya belum diisi',
            };
        }

        return match ($this->status()) {
            'expired' => 'Kedaluwarsa',
            'expiring_soon' => 'Segera kedaluwarsa',
            'valid' => 'Valid',
            default => 'Masa berlaku belum diketahui',
        };
    }

    public function statusPriority(): int
    {
        return match ($this->status()) {
            'expired' => 0,
            'expiring_soon' => 1,
            'valid' => 2,
            default => 3,
        };
    }

    public function needsService(): bool
    {
        return isset(self::SERVICE_TYPES[$this->document_type])
            && in_array($this->status(), ['expired', 'expiring_soon'], true);
    }

    /** @return array{vessel_id: int, document_type: string, document_id: int, service_type: string|null} */
    public function serviceParameters(): array
    {
        return [
            'vessel_id' => $this->vessel_id,
            'document_type' => $this->document_type,
            'document_id' => $this->id,
            'service_type' => self::SERVICE_TYPES[$this->document_type] ?? null,
        ];
    }

    public function serviceUrl(): ?string
    {
        if (! $this->needsService()) {
            return null;
        }

        if ($this->document_type === 'safety_certificate' && $this->vessel->vessel_type !== 'passenger') {
            return null;
        }

        $route = config('vessels.service_routes.'.self::SERVICE_TYPES[$this->document_type]);

        $parameters = $this->serviceParameters();
        if (in_array($this->document_type, ['safety_certificate', 'surat_laut', 'pas_besar', 'pas_kecil'], true)) {
            $parameters += ['request_type' => 'renewal', 'previous_document_id' => $this->id];
        }

        return is_string($route) && Route::has($route)
            ? route($route, $parameters)
            : null;
    }

    /** @return BelongsTo<ShipNationalityService, $this> */
    public function shipNationalitySource(): BelongsTo
    {
        return $this->belongsTo(ShipNationalityService::class, 'source_ship_nationality_service_id');
    }
}
