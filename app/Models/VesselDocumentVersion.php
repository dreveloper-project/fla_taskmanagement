<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\VesselDocumentVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $vessel_document_id
 * @property string|null $certificate_number
 * @property array{condition?: string|null, quantity?: int|null, capacity?: int|null, notes?: string|null}|null $equipment
 * @property string $file_path
 * @property string $original_filename
 * @property CarbonImmutable|null $issued_date
 * @property CarbonImmutable|null $expired_date
 * @property int|null $source_ship_nationality_service_id
 * @property int|null $source_safety_certificate_service_id
 * @property string $status
 * @property CarbonImmutable $archived_at
 * @property int|null $archived_by
 * @property-read VesselDocument $vesselDocument
 */
#[Fillable(['certificate_number', 'file_path', 'original_filename', 'issued_date', 'expired_date', 'source_safety_certificate_service_id', 'source_ship_nationality_service_id', 'status', 'archived_at', 'archived_by', 'equipment'])]
class VesselDocumentVersion extends Model
{
    /** @use HasFactory<VesselDocumentVersionFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['issued_date' => 'immutable_date', 'expired_date' => 'immutable_date', 'archived_at' => 'immutable_datetime', 'equipment' => 'array'];
    }

    /** @return BelongsTo<VesselDocument, $this> */
    public function vesselDocument(): BelongsTo
    {
        return $this->belongsTo(VesselDocument::class);
    }

    /** @return BelongsTo<SafetyCertificateService, $this> */
    public function sourceService(): BelongsTo
    {
        return $this->belongsTo(SafetyCertificateService::class, 'source_safety_certificate_service_id');
    }

    /** @return BelongsTo<ShipNationalityService, $this> */
    public function shipNationalitySource(): BelongsTo
    {
        return $this->belongsTo(ShipNationalityService::class, 'source_ship_nationality_service_id');
    }
}
