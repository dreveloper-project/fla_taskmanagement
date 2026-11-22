<?php

namespace App\Services;

use App\Models\SafetyCertificateDocument;
use App\Models\SafetyCertificateService;
use App\Models\Vessel;
use App\Models\VesselDocument;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SafetyCertificateReadinessService
{
    public function __construct(private VesselDocumentReadiness $vesselDocuments) {}

    /** @return list<array{label: string, status: string, complete: bool, required: bool, document: VesselDocument|null}> */
    public function vesselCheck(Vessel $vessel): array
    {
        return $this->vesselDocuments->check($vessel, config('safety-certificates.vessel_documents', []));
    }

    /** @return array{complete: bool, missing: list<string>, vessel_checks: list<array{label: string, status: string, complete: bool, required: bool, document: VesselDocument|null}>} */
    public function check(SafetyCertificateService $service): array
    {
        $service->loadMissing(['vessel.documents', 'documents']);
        $missing = [];
        if ($service->vessel->vessel_type !== 'passenger') {
            $missing[] = 'Kapal harus bertipe penumpang.';
        }
        if (! isset(SafetyCertificateService::REQUEST_TYPES[$service->request_type])) {
            $missing[] = 'Jenis pengurusan belum dipilih.';
        }
        $checks = $this->vesselCheck($service->vessel);
        foreach ($checks as $check) {
            if ($check['required'] && ! $check['complete']) {
                $missing[] = $check['label'].': '.$check['status'];
            }
        }
        if ($service->request_type === 'renewal') {
            $previous = $service->latestDocument('previous_certificate');
            $source = $service->vessel->documents->firstWhere('document_type', 'safety_certificate');
            $path = $previous->file_path ?? $source->file_path ?? null;
            if ($path === null || ! Storage::disk('local')->exists($path)) {
                $missing[] = 'Sertifikat lama belum tersedia pada data Vessel.';
            }
        }
        /** @var list<string> $types */
        $types = config('safety-certificates.required_service_documents', []);
        foreach ($types as $type) {
            $document = $service->latestDocument($type);
            if ($document === null || ! Storage::disk('local')->exists($document->file_path)) {
                $missing[] = SafetyCertificateDocument::TYPES[$type] ?? $type;
            }
        }

        return ['complete' => $missing === [], 'missing' => $missing, 'vessel_checks' => $checks];
    }

    public function ensureReady(SafetyCertificateService $service): void
    {
        $result = $this->check($service);
        if (! $result['complete']) {
            throw ValidationException::withMessages(['readiness' => $result['missing']]);
        }
    }
}
