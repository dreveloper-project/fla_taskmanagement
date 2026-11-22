<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SpbDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $spb_service_id
 * @property string $document_type
 * @property string|null $source_document_type
 * @property string $file_path
 * @property string $original_filename
 * @property int $uploaded_by
 * @property CarbonImmutable $uploaded_at
 * @property array<string, mixed>|null $metadata
 * @property-read SpbService $spbService
 * @property-read User $uploader
 */
#[Fillable(['document_type', 'source_document_type', 'file_path', 'original_filename', 'uploaded_by', 'uploaded_at', 'metadata'])]
class SpbDocument extends Model
{
    /** @use HasFactory<SpbDocumentFactory> */
    use HasFactory;

    public const APPLICATION_TYPES = [
        'spb_file' => 'File SPB',
        'lk3_nakhoda' => 'LK3 Nakhoda',
        'spb_request_letter' => 'Surat Permohonan SPB',
        'master_sailing_declaration' => 'Master Sailing Declaration',
        'cargo_manifest' => 'Cargo Manifest',
        'crew_list' => 'Crew List',
        'passenger_manifest' => 'Passenger Manifest',
        'rkbm' => 'RKBM',
        'pbm' => 'PBM',
        'warta_kapal' => 'Warta Kapal',
        'payment_proof' => 'Bukti Pembayaran',
        'pnbp_proof' => 'Bukti PNBP',
    ];

    public const PNBP_TYPES = [
        'pnbp_labuh_transfer' => 'Bukti transfer PNBP labuh',
        'pnbp_tambat_transfer' => 'Bukti transfer PNBP rambu',
        'pnbp_mastercable_transfer' => 'Bukti transfer PNBP mastercable',
    ];

    public const ARRIVAL_APPLICATION_TYPES = [
        'arrival_spb' => 'SPB kedatangan',
        'arrival_entry_approval' => 'Surat Persetujuan Masuk',
        'arrival_pkk' => 'PKK',
    ];

    public const TYPES = self::APPLICATION_TYPES + self::PNBP_TYPES + self::ARRIVAL_APPLICATION_TYPES + [
        'issued_spb' => 'SPB resmi', 'revision_attachment' => 'Bukti revisi Inaportnet',
        'rejection_attachment' => 'Bukti penolakan Inaportnet',
        'sailing_failure_attachment' => 'Bukti gagal berlayar',
        'arrival_pnbp_labuh_transfer' => 'Bukti transfer PNBP labuh kedatangan',
        'arrival_pnbp_mastercable_transfer' => 'Bukti transfer PNBP mastercable kedatangan',
        'arrival_lk3' => 'LK3 kedatangan',
        'vessel_reference' => 'Arsip dokumen vessel saat pengajuan',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['uploaded_at' => 'immutable_datetime', 'metadata' => 'array'];
    }

    /** @return BelongsTo<SpbService, $this> */
    public function spbService(): BelongsTo
    {
        return $this->belongsTo(SpbService::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function label(): string
    {
        return $this->document_type === 'vessel_reference'
            ? 'Arsip vessel: '.(VesselDocument::TYPES[$this->source_document_type] ?? $this->source_document_type)
            : self::TYPES[$this->document_type];
    }
}
