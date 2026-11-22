<?php

namespace App\Services;

use App\Models\BunkerDocument;
use App\Models\SafetyCertificateDocument;
use App\Models\ShipNationalityDocument;
use App\Models\SijilDocument;
use App\Models\SpbDocument;
use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Models\VesselDocumentVersion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class DocumentFilename
{
    public static function forService(
        SpbDocument|SafetyCertificateDocument|BunkerDocument|SijilDocument|ShipNationalityDocument $document,
        Vessel $vessel,
        ?string $nationalityType = null,
    ): string {
        [$module, $issuedType] = match (true) {
            $document instanceof SpbDocument => ['SPB', 'issued_spb'],
            $document instanceof SafetyCertificateDocument => ['Sertifikat-Keselamatan', 'issued_certificate'],
            $document instanceof BunkerDocument => ['Izin-Bunker', 'approval_document'],
            $document instanceof SijilDocument => ['Sijil-Awak-Kapal', 'official_result'],
            $document instanceof ShipNationalityDocument => [VesselDocument::TYPES[$nationalityType] ?? 'Surat-Kebangsaan', 'issued_document'],
        };
        $type = $document->document_type === $issuedType
            ? $module
            : $module.'-'.($document::TYPES[$document->document_type] ?? $document->document_type);

        return self::format($type, $vessel->vessel_name, $document->uploaded_at ?? $document->created_at,
            (string) $document->id, $document->file_path, $document->original_filename);
    }

    public static function forVessel(VesselDocument|VesselDocumentVersion $document, Vessel $vessel, string $type): string
    {
        $date = $document->issued_date?->format('Y-m-d')
            ?? self::localDate($document->created_at ?? ($document instanceof VesselDocumentVersion ? $document->archived_at : null));
        $id = ($document instanceof VesselDocumentVersion ? 'V' : '').$document->id;

        return self::format(VesselDocument::TYPES[$type] ?? $type, $vessel->vessel_name,
            $date, $id, $document->file_path, $document->original_filename);
    }

    private static function format(string $type, string $vesselName, CarbonInterface|string|null $date, string $id, string $path, string $original): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'doc', 'docx'], true)) {
            $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        }
        if (! in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'doc', 'docx'], true)) {
            $extension = 'bin';
        }

        return self::segment($type, 'Dokumen', 80).'_'.self::segment($vesselName, 'Kapal', 100).'_'
            .(is_string($date) ? $date : self::localDate($date)).'_'.$id.'.'.$extension;
    }

    private static function localDate(?CarbonInterface $date): string
    {
        return $date === null ? 'Tanpa-Tanggal' : CarbonImmutable::instance($date)->timezone('Asia/Makassar')->format('Y-m-d');
    }

    private static function segment(string $value, string $fallback, int $limit): string
    {
        $safe = preg_replace('/[^A-Za-z0-9]+/', '-', Str::ascii($value)) ?? '';

        return trim(substr(trim($safe, '-'), 0, $limit), '-') ?: $fallback;
    }
}
