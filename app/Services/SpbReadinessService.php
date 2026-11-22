<?php

namespace App\Services;

use App\Models\SpbDocument;
use App\Models\SpbService;
use App\Models\Vessel;
use App\Models\VesselDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SpbReadinessService
{
    public function __construct(private VesselDocumentReadiness $vesselDocuments) {}

    /**
     * @return list<array{label: string, types: list<string>, status: string, complete: bool, required: bool, document: VesselDocument|null}>
     */
    public function vesselCheck(Vessel $vessel, ?CarbonImmutable $departure = null, int $minimumPersons = 0): array
    {
        $vessel->loadMissing('documents');
        $today = CarbonImmutable::today(config('spb.timezone'));
        $checkDate = $departure?->setTimezone(config('spb.timezone'))->startOfDay();
        if ($checkDate === null || $checkDate->lt($today)) {
            $checkDate = $today;
        }
        $rows = [];

        /** @var list<array{label: string, types: list<string>, requires_expiry: bool, required?: bool}> $requirements */
        $requirements = config('spb.required_vessel_documents', []);
        foreach ($requirements as $requirement) {
            $candidates = $vessel->documents->whereIn('document_type', $requirement['types']);
            $selected = null;
            $status = 'Belum tersedia';
            $complete = false;
            foreach ($candidates as $document) {
                $selected = $document;
                if (! Storage::disk('local')->exists($document->file_path)) {
                    $status = 'File tidak tersedia';
                } elseif ($document->expired_date !== null && $document->expired_date->toDateString() < $checkDate->toDateString()) {
                    $status = $document->expired_date->toDateString() < $today->toDateString()
                        ? 'Kedaluwarsa' : 'Kedaluwarsa sebelum keberangkatan';
                } elseif ($document->expired_date === null && $requirement['requires_expiry']) {
                    $status = 'Tanggal kedaluwarsa belum diisi';
                } else {
                    $status = $document->status() === 'expiring_soon' ? 'Tersedia · Segera kedaluwarsa' : 'Tersedia';
                    $complete = true;
                    break;
                }
            }
            $rows[] = ['label' => $requirement['label'], 'types' => $requirement['types'], 'status' => $status, 'complete' => $complete, 'required' => $requirement['required'] ?? true, 'document' => $selected];
        }

        return array_merge($rows, $this->equipmentCheck($vessel, $checkDate, $minimumPersons));
    }

    /** @return list<array{label: string, types: list<string>, status: string, complete: bool, required: bool, document: VesselDocument|null}> */
    public function equipmentCheck(Vessel $vessel, ?CarbonImmutable $checkDate = null, int $minimumPersons = 0): array
    {
        $requirements = array_values(array_filter(config('spb.safety_equipment_documents', []),
            fn (array $requirement): bool => ! ($requirement['cargo_only'] ?? false) || $vessel->vessel_type === 'cargo'));

        return $this->vesselDocuments->check($vessel, $requirements, $checkDate, $vessel->vessel_type === 'cargo', $minimumPersons);
    }

    /**
     * @return array{complete: bool, missing: list<string>, vessel_checks: list<array{label: string, types: list<string>, status: string, complete: bool, required: bool, document: VesselDocument|null}>}
     */
    public function check(SpbService $spb): array
    {
        $spb->loadMissing(['vessel.documents', 'documents']);
        $missing = [];
        if ($spb->cargo_status === 'carrying_cargo' && blank($spb->cargo_description)) {
            $missing[] = 'Deskripsi muatan';
        }

        foreach ($this->requiredDocumentTypes($spb) as $type) {
            $document = $spb->latestDocument($type);
            if ($document === null || ! Storage::disk('local')->exists($document->file_path)) {
                $missing[] = SpbDocument::APPLICATION_TYPES[$type] ?? $type;
            }
        }

        $checks = $this->vesselCheck($spb->vessel, $spb->estimated_departure_at, $spb->vessel->currentCrewCount() + (int) $spb->total_passengers);

        return ['complete' => $missing === [], 'missing' => $missing, 'vessel_checks' => $checks];
    }

    public function ensureReady(SpbService $spb): void
    {
        $result = $this->check($spb);
        if (! $result['complete']) {
            throw ValidationException::withMessages(['readiness' => $result['missing']]);
        }
    }

    /** @return list<string> */
    public function requiredDocumentTypes(SpbService $spb): array
    {
        $types = config('spb.required_spb_documents', []);
        if ($spb->hasPassengerInformation() && (! $spb->passengers_none || $spb->total_passengers > 0)) {
            $types[] = 'passenger_manifest';
        }

        return array_values(array_unique($types));
    }
}
