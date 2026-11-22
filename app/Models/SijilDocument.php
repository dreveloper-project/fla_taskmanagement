<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SijilDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $sijil_service_crew_id
 * @property int|null $crew_id
 * @property int $sijil_service_id
 * @property string $document_type
 * @property string $file_path
 * @property string $original_filename
 * @property int $uploaded_by
 * @property CarbonImmutable $uploaded_at
 * @property array<string, mixed>|null $metadata
 * @property-read SijilService $sijilService
 * @property-read User $uploader
 */
#[Fillable(['sijil_service_crew_id', 'crew_id', 'document_type', 'file_path', 'original_filename', 'uploaded_by', 'uploaded_at', 'metadata'])]
class SijilDocument extends Model
{
    /** @use HasFactory<SijilDocumentFactory> */
    use HasFactory;

    public const APPLICATION_TYPES = ['application_letter' => 'Surat Permohonan', 'crew_list' => 'Crew List', 'payment_proof' => 'Bukti Pembayaran', 'pnbp_proof' => 'Bukti PNBP', 'other' => 'Lainnya'];

    public const CREW_TYPES = ['seafarer_book' => 'Buku Pelaut', 'competency_certificate' => 'Sertifikat Kompetensi', 'pkl' => 'PKL', 'identity_document' => 'Dokumen Identitas'];

    public const TYPES = self::APPLICATION_TYPES + self::CREW_TYPES + ['revision_attachment' => 'Lampiran Revisi', 'rejection_attachment' => 'Lampiran Penolakan', 'official_result' => 'Sijil Result / Official Document', 'vessel_reference' => 'Arsip Dokumen Vessel'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['uploaded_at' => 'immutable_datetime', 'metadata' => 'array'];
    }

    /** @return BelongsTo<SijilService, $this> */
    public function sijilService(): BelongsTo
    {
        return $this->belongsTo(SijilService::class);
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
