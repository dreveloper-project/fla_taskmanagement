<?php

namespace App\Services;

use App\Models\BunkerDocument;
use App\Models\BunkerService;
use App\Models\Vessel;
use App\Models\VesselDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class BunkerReadinessService
{
    public function __construct(private VesselDocumentReadiness $vesselDocuments) {}

    /** @return list<array{label: string, status: string, complete: bool, required: bool, document: VesselDocument|null}> */
    public function vesselCheck(Vessel $vessel, ?CarbonImmutable $checkDate = null): array
    {
        return $this->vesselDocuments->check($vessel, config('bunker.vessel_documents', []), $checkDate);
    }

    /** @return array{complete: bool, missing: list<string>, vessel_checks: list<array{label: string, status: string, complete: bool, required: bool, document: VesselDocument|null}>} */
    public function check(BunkerService $service, ?CarbonImmutable $checkDate = null): array
    {
        $service->loadMissing(['vessel.documents', 'documents']);
        $missing = [];
        foreach (['vessel_id' => 'Vessel', 'supplier' => 'Pemasok', 'bunker_location' => 'Lokasi Bunker', 'scheduled_at' => 'Jadwal Bunker', 'bunker_type' => 'Jenis Bahan Bakar', 'quantity' => 'Jumlah', 'unit' => 'Satuan'] as $field => $label) {
            if (blank($service->$field)) {
                $missing[] = $label.' belum diisi.';
            }
        }
        if ($service->quantity !== null && (float) $service->quantity <= 0) {
            $missing[] = 'Jumlah harus lebih dari 0.';
        }
        if (! isset(BunkerService::UNITS[$service->unit ?? '']) || ($service->unit === 'other' && blank($service->unit_other))) {
            $missing[] = 'Satuan atau nama satuan belum valid.';
        }
        $checks = $this->vesselCheck($service->vessel, $checkDate ?? $service->scheduled_at);
        foreach ($checks as $check) {
            if ($check['required'] && ! $check['complete']) {
                $missing[] = $check['label'].': '.$check['status'];
            }
        }
        foreach (config('bunker.required_service_documents', []) as $type) {
            $document = $service->latestDocument($type);
            if ($document === null || ! Storage::disk('local')->exists($document->file_path)) {
                $missing[] = BunkerDocument::TYPES[$type] ?? $type;
            }
        }

        return ['complete' => $missing === [], 'missing' => $missing, 'vessel_checks' => $checks];
    }

    public function ensureReady(BunkerService $service, ?CarbonImmutable $checkDate = null): void
    {
        $check = $this->check($service, $checkDate);
        if (! $check['complete']) {
            throw ValidationException::withMessages(['readiness' => $check['missing']]);
        }
    }
}
