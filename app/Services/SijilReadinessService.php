<?php

namespace App\Services;

use App\Models\Crew;
use App\Models\SijilDocument;
use App\Models\SijilService;
use App\Models\SijilServiceCrew;
use App\Models\VesselCrew;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SijilReadinessService
{
    public function __construct(private VesselDocumentReadiness $vesselDocuments) {}

    /** @return array{complete: bool, missing: list<string>, crew_checks: array<int, list<string>>, vessel_checks: array<int, mixed>} */
    public function check(SijilService $service): array
    {
        $service->loadMissing(['vessel.documents', 'crews.documents', 'crews.crew.documents', 'documents']);
        $missing = [];
        if (! isset(SijilService::PROCESS_TYPES[$service->process_type])) {
            $missing[] = 'Jenis proses belum valid.';
        }
        if ($service->crews->isEmpty()) {
            $missing[] = 'Minimal satu awak kapal diperlukan.';
        }
        if ($service->process_type === 'crew_change' && (! $service->crews->contains('action_type', 'sign_on') || ! $service->crews->contains('action_type', 'sign_off'))) {
            $missing[] = 'Pergantian awak kapal memerlukan Sign Off awak lama dan Sign On awak baru.';
        }
        $checks = [];
        $identities = [];
        foreach ($service->crews as $row) {
            $errors = $this->crewIssues($service, $row);
            $identity = $row->crew_id !== null ? 'crew:'.$row->crew_id : ($row->seafarer_book_number ? 'book:'.mb_strtoupper($row->seafarer_book_number) : null);
            if ($identity !== null && in_array($identity, $identities, true)) {
                $errors[] = 'Awak kapal yang sama tidak boleh dicantumkan dua kali.';
            }
            if ($identity !== null) {
                $identities[] = $identity;
            }
            if ($row->crew_id !== null) {
                foreach (config('sijil.required_crew_documents.'.$row->action_type, []) as $type) {
                    if ($this->crewDocument($row, $type) === null) {
                        $errors[] = SijilDocument::TYPES[$type].' belum tersedia.';
                    }
                }
            }
            $checks[$row->id] = $errors;
            foreach ($errors as $error) {
                $missing[] = ($row->full_name ?? 'Awak kapal #'.$row->id).': '.$error;
            }
        }
        $vesselChecks = $this->vesselDocuments->check($service->vessel, config('sijil.vessel_documents', []));
        foreach ($vesselChecks as $check) {
            if ($check['required'] && ! $check['complete']) {
                $missing[] = $check['label'].': '.$check['status'];
            }
        }

        return ['complete' => $missing === [], 'missing' => $missing, 'crew_checks' => $checks, 'vessel_checks' => $vesselChecks];
    }

    /** @return list<string> */
    public function crewIssues(SijilService $service, SijilServiceCrew $row): array
    {
        $errors = [];
        if (blank($row->full_name)) {
            $errors[] = 'Nama awak kapal wajib diisi.';
        }
        if (blank($row->rank)) {
            $errors[] = 'Jabatan awak kapal wajib diisi.';
        }
        if (! in_array($row->action_type, SijilService::ALLOWED_ACTIONS[$service->process_type] ?? [], true)) {
            $errors[] = 'Tindakan awak kapal tidak sesuai jenis proses.';
        }
        if ($row->seafarer_book_number && Crew::where('seafarer_book_number', $row->seafarer_book_number)->when($row->crew_id, fn ($query) => $query->where('id', '!=', $row->crew_id))->exists()) {
            $errors[] = 'Nomor Buku Pelaut sudah terdaftar. Pilih awak kapal yang sudah terdaftar.';
        }
        $active = $row->crew_id ? VesselCrew::where('active_crew_id', $row->crew_id)->first() : null;
        if ($row->action_type === 'sign_on') {
            if ($active) {
                $errors[] = 'Awak kapal masih aktif di kapal; lakukan Sign Off terlebih dahulu.';
            }
        } elseif ($row->crew_id === null || $active === null || $active->vessel_id !== $service->vessel_id) {
            $errors[] = 'Sign Off atau pembaruan harus memilih awak yang aktif pada kapal ini.';
        } elseif ($active->id !== $row->vessel_crew_id || $active->revision !== $row->expected_revision) {
            $errors[] = $service->isEditable() ? 'Data penempatan awak kapal telah berubah. Hapus baris lalu pilih ulang awak kapal sebelum mengajukan.' : 'Data penempatan awak kapal telah berubah. Tinjau perubahan terbaru dan buat pengurusan ulang.';
        }

        return $errors;
    }

    public function crewDocument(SijilServiceCrew $row, string $type): ?SijilDocument
    {
        $own = $row->documents->where('document_type', $type)->sortByDesc('id')->first();
        $document = $own ?? $row->crew?->documents->where('document_type', $type)->sortByDesc('id')->first();

        return $document && Storage::disk('local')->exists($document->file_path) ? $document : null;
    }

    public function ensureReady(SijilService $service): void
    {
        $check = $this->check($service);
        if (! $check['complete']) {
            throw ValidationException::withMessages(['readiness' => $check['missing']]);
        }
    }

    /** @return Collection<int, SijilService> */
    public function needingAttention(): Collection
    {
        return SijilService::with(['vessel.documents', 'assignedStaff', 'documents', 'crews.documents', 'crews.crew.documents'])
            ->whereIn('status', SijilService::ACTIVE)->get()->filter(fn (SijilService $service): bool => $this->needsAttention($service));
    }

    public function needsAttention(SijilService $service): bool
    {
        return in_array($service->status, SijilService::ACTIVE, true)
            && ($service->status === 'revision_required' || ! $this->check($service)['complete']);
    }
}
