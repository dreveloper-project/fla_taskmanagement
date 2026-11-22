<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BunkerDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $bunker_service_id
 * @property string $document_type
 * @property string $file_path
 * @property string $original_filename
 * @property int $uploaded_by
 * @property CarbonImmutable $uploaded_at
 * @property array<string, mixed>|null $metadata
 * @property-read BunkerService $bunkerService
 * @property-read User $uploader
 */
#[Fillable(['document_type', 'file_path', 'original_filename', 'uploaded_by', 'uploaded_at', 'metadata'])]
class BunkerDocument extends Model
{
    /** @use HasFactory<BunkerDocumentFactory> */
    use HasFactory;

    public const APPLICATION_TYPES = [
        'application_letter' => 'Surat Permohonan Izin Bunker', 'bunker_request' => 'Bunker Request',
        'supplier_document' => 'Supplier Document', 'bunker_plan' => 'Delivery / Bunker Plan', 'payment_proof' => 'Bukti Pembayaran',
        'pnbp_proof' => 'Bukti PNBP', 'other' => 'Dokumen Tambahan',
    ];

    public const TYPES = self::APPLICATION_TYPES + [
        'vessel_reference' => 'Arsip Dokumen Vessel', 'revision_attachment' => 'Lampiran Revisi',
        'approval_document' => 'Approval / Permit Document', 'rejection_attachment' => 'Lampiran Penolakan',
        'activity_proof' => 'Bukti Kegiatan Bunker',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['uploaded_at' => 'immutable_datetime', 'metadata' => 'array'];
    }

    /** @return BelongsTo<BunkerService, $this> */
    public function bunkerService(): BelongsTo
    {
        return $this->belongsTo(BunkerService::class);
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
