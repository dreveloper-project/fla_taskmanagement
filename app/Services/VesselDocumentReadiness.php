<?php

namespace App\Services;

use App\Models\Vessel;
use App\Models\VesselDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

class VesselDocumentReadiness
{
    /** @param list<array{label: string, types: list<string>, required: bool, must_be_valid: bool, equipment_ready?: bool}> $requirements
     * @return list<array{label: string, types: list<string>, status: string, complete: bool, required: bool, document: VesselDocument|null}> */
    public function check(Vessel $vessel, array $requirements, ?CarbonImmutable $checkDate = null, bool $allowNotApplicable = false, int $minimumPersons = 0): array
    {
        $vessel->loadMissing('documents');
        $today = CarbonImmutable::today(config('app.timezone'));
        $checkDate = $checkDate?->setTimezone(config('app.timezone'))->startOfDay();
        $checkDate = $checkDate === null || $checkDate->lt($today) ? $today : $checkDate;
        $rows = [];
        foreach ($requirements as $requirement) {
            $selected = null;
            $status = 'Belum tersedia';
            $complete = false;
            foreach ($vessel->documents->whereIn('document_type', $requirement['types']) as $document) {
                $selected = $document;
                if (! Storage::disk('local')->exists($document->file_path)) {
                    $status = 'File tidak tersedia';
                } elseif (($requirement['equipment_ready'] ?? false) && $allowNotApplicable && ($document->equipment['condition'] ?? null) === 'not_applicable' && filled($document->equipment['notes'] ?? null)) {
                    $status = 'Tidak berlaku: '.$document->equipment['notes'];
                    $complete = true;
                    break;
                } elseif (($requirement['equipment_ready'] ?? false) && $document->issued_date !== null && $document->issued_date->toDateString() > $checkDate->toDateString()) {
                    $status = 'Dokumen belum berlaku pada tanggal pemeriksaan / pelaksanaan';
                } elseif (($requirement['must_be_valid'] || (($requirement['equipment_ready'] ?? false) && $document->expired_date !== null)) && ($document->expired_date === null || $document->expired_date->toDateString() < $checkDate->toDateString())) {
                    $status = $document->expired_date === null ? 'Tanggal kedaluwarsa belum diisi' : 'Kedaluwarsa pada tanggal pemeriksaan / pelaksanaan';
                } elseif (($requirement['equipment_ready'] ?? false) && ($issue = $document->equipmentReadinessIssue(false, $minimumPersons)) !== null) {
                    $status = $issue;
                } else {
                    $status = ($requirement['equipment_ready'] ?? false) ? 'Siap · Bukti pemeriksaan tersedia' : ($document->expired_date === null ? 'Tersedia' : 'Tersedia · '.$document->statusLabel());
                    $complete = true;
                    break;
                }
            }
            $rows[] = ['label' => $requirement['label'], 'types' => $requirement['types'], 'status' => $status, 'complete' => $complete, 'required' => $requirement['required'], 'document' => $selected];
        }

        return $rows;
    }
}
