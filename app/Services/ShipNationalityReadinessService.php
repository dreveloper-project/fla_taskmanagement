<?php

namespace App\Services;

use App\Models\ShipNationalityDocument;
use App\Models\ShipNationalityService;
use App\Models\Vessel;
use App\Models\VesselDocument;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ShipNationalityReadinessService
{
    public function __construct(private VesselDocumentReadiness $vesselDocuments) {}

    /** @return list<array{label: string, status: string, complete: bool, required: bool, document: VesselDocument|null}> */
    public function vesselCheck(Vessel $vessel): array
    {
        $requirements = config('ship-nationality.vessel_documents', []);
        $requirements[] = [
            'label' => 'Bukti kepemilikan (alternatif lampiran layanan)',
            'types' => config('ship-nationality.ownership_vessel_document_types', []),
            'required' => false, 'must_be_valid' => false,
        ];

        return $this->vesselDocuments->check($vessel, $requirements);
    }

    /** @return array{complete: bool, missing: list<string>, vessel_checks: list<array{label: string, status: string, complete: bool, required: bool, document: VesselDocument|null}>} */
    public function check(ShipNationalityService $service): array
    {
        $service->loadMissing(['vessel.documents', 'documents']);
        $missing = [];
        if (! isset(ShipNationalityService::DOCUMENT_TYPES[$service->document_type])) {
            $missing[] = 'Jenis dokumen belum valid.';
        }
        $allowed = config('ship-nationality.eligibility.'.$service->document_type.'.allowed_vessel_types', []);
        if ($allowed !== [] && ! in_array($service->vessel->vessel_type, $allowed, true)) {
            $missing[] = 'Jenis kapal tidak memenuhi persyaratan dokumen ini.';
        }
        if (! isset(ShipNationalityService::REQUEST_TYPES[$service->request_type])) {
            $missing[] = 'Jenis pengurusan belum dipilih.';
        }
        $checks = $this->vesselCheck($service->vessel);
        foreach ($checks as $check) {
            if ($check['required'] && ! $check['complete']) {
                $missing[] = $check['label'].': '.$check['status'];
            }
        }
        if ($service->request_type === 'renewal') {
            $previous = $service->latestDocument('previous_document');
            $source = $service->vessel->documents->firstWhere('document_type', $service->document_type);
            $path = $previous->file_path ?? $source->file_path ?? null;
            if ($path === null || ! Storage::disk('local')->exists($path)) {
                $missing[] = 'Dokumen lama belum tersedia.';
            }
        }
        $ownershipTypes = config('ship-nationality.ownership_vessel_document_types', []);
        $ownership = $service->vessel->documents->whereIn('document_type', $ownershipTypes)
            ->contains(fn (VesselDocument $document): bool => Storage::disk('local')->exists($document->file_path));
        $evidence = $service->latestDocument(config('ship-nationality.ownership_service_document_type'));
        if (! $ownership && ($evidence === null || ! Storage::disk('local')->exists($evidence->file_path))) {
            $missing[] = 'Bukti pendaftaran / kepemilikan kapal belum tersedia.';
        }
        /** @var list<string> $types */
        $types = config('ship-nationality.required_service_documents', []);
        foreach ($types as $type) {
            $document = $service->latestDocument($type);
            if ($document === null || ! Storage::disk('local')->exists($document->file_path)) {
                $missing[] = ShipNationalityDocument::TYPES[$type] ?? $type;
            }
        }

        return ['complete' => $missing === [], 'missing' => $missing, 'vessel_checks' => $checks];
    }

    public function documentAvailable(?VesselDocument $document): bool
    {
        return $document !== null && Storage::disk('local')->exists($document->file_path);
    }

    public function ensureReady(ShipNationalityService $service): void
    {
        $result = $this->check($service);
        if (! $result['complete']) {
            throw ValidationException::withMessages(['readiness' => $result['missing']]);
        }
    }
}
