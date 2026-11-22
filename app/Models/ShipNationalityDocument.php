<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ShipNationalityDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ship_nationality_service_id
 * @property string $document_type
 * @property string $file_path
 * @property string $original_filename
 * @property int $uploaded_by
 * @property CarbonImmutable $uploaded_at
 * @property array<string, mixed>|null $metadata
 * @property-read ShipNationalityService $shipNationalityService
 * @property-read User $uploader
 */
#[Fillable(['document_type', 'file_path', 'original_filename', 'uploaded_by', 'uploaded_at', 'metadata'])]
class ShipNationalityDocument extends Model
{
    /** @use HasFactory<ShipNationalityDocumentFactory> */
    use HasFactory;

    public const APPLICATION_TYPES = [
        'application_letter' => 'Surat Permohonan', 'ownership_evidence' => 'Bukti Kepemilikan / Pendaftaran',
        'payment_proof' => 'Bukti Pembayaran', 'pnbp_proof' => 'Bukti PNBP', 'other' => 'Lainnya',
    ];

    public const TYPES = self::APPLICATION_TYPES + [
        'previous_document' => 'Referensi Dokumen Lama', 'vessel_reference' => 'Arsip Dokumen Vessel',
        'revision_attachment' => 'Lampiran Revisi', 'rejection_attachment' => 'Lampiran Penolakan',
        'issued_document' => 'Dokumen Terbit', 'handover_receipt' => 'Bukti Serah Terima',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['uploaded_at' => 'immutable_datetime', 'metadata' => 'array'];
    }

    /** @return BelongsTo<ShipNationalityService, $this> */
    public function shipNationalityService(): BelongsTo
    {
        return $this->belongsTo(ShipNationalityService::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function label(): string
    {
        return self::TYPES[$this->document_type];
    }
}
