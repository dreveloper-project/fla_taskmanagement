<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SafetyCertificateDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $safety_certificate_service_id
 * @property string $document_type
 * @property string $file_path
 * @property string $original_filename
 * @property int $uploaded_by
 * @property CarbonImmutable $uploaded_at
 * @property array<string, mixed>|null $metadata
 * @property-read SafetyCertificateService $safetyCertificateService
 * @property-read User $uploader
 */
#[Fillable(['document_type', 'file_path', 'original_filename', 'uploaded_by', 'uploaded_at', 'metadata'])]
class SafetyCertificateDocument extends Model
{
    /** @use HasFactory<SafetyCertificateDocumentFactory> */
    use HasFactory;

    public const APPLICATION_TYPES = [
        'application_letter' => 'Surat Permohonan', 'inspection_document' => 'Dokumen Pemeriksaan',
        'technical_attachment' => 'Lampiran Teknis', 'payment_proof' => 'Bukti Pembayaran',
        'pnbp_proof' => 'Bukti PNBP', 'other' => 'Dokumen Tambahan',
    ];

    public const TYPES = self::APPLICATION_TYPES + [
        'previous_certificate' => 'Referensi Sertifikat Lama', 'vessel_reference' => 'Arsip Dokumen Vessel',
        'revision_attachment' => 'Lampiran Revisi', 'inspection_report' => 'Laporan Pemeriksaan',
        'issued_certificate' => 'Sertifikat Resmi', 'rejection_attachment' => 'Lampiran Penolakan',
        'handover_receipt' => 'Bukti Serah Terima',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['uploaded_at' => 'immutable_datetime', 'metadata' => 'array'];
    }

    /** @return BelongsTo<SafetyCertificateService, $this> */
    public function safetyCertificateService(): BelongsTo
    {
        return $this->belongsTo(SafetyCertificateService::class);
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
