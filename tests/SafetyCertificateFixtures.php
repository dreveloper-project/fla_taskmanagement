<?php

use App\Models\Client;
use App\Models\SafetyCertificateService;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Services\SafetyCertificateWorkflow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function safetyPdf(string $name = 'certificate.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
}

/** @return array<string, mixed> */
function safetyInput(Vessel $vessel, User $actor, string $type = 'new_issue'): array
{
    return ['vessel_id' => $vessel->id, 'request_type' => $type, 'assigned_staff_id' => $actor->id, 'priority' => 'high', 'notes' => 'Pengurusan keselamatan'];
}

function safetyDraft(string $type = 'new_issue'): SafetyCertificateService
{
    $staff = User::factory()->create();
    test()->actingAs($staff);
    $vessel = Vessel::factory()->for(Client::factory())->create();
    foreach (['surat_penunjukan_keagenan', 'surat_ukur', 'surat_laut', 'general_arrangement'] as $documentType) {
        $document = VesselDocument::factory()->for($vessel)->create(['document_type' => $documentType]);
        Storage::disk('local')->put($document->file_path, '%PDF-1.4 vessel');
    }
    if ($type === 'renewal') {
        $previous = VesselDocument::factory()->for($vessel)->create([
            'document_type' => 'safety_certificate', 'certificate_number' => 'OLD-2025-001',
            'issued_date' => '2025-10-01', 'expired_date' => '2026-10-01',
        ]);
        Storage::disk('local')->put($previous->file_path, '%PDF-1.4 old certificate');
    }

    foreach (['liferaft', 'fire_fighting', 'life_jacket', 'life_buoy', 'emergency_equipment'] as $equipmentType) {
        $document = VesselDocument::factory()->for($vessel)->readyEquipment($equipmentType)->create();
        Storage::disk('local')->put($document->file_path, '%PDF-1.4 equipment');
    }

    return app(SafetyCertificateWorkflow::class)->saveDraft(null, safetyInput($vessel, $staff, $type), [
        'application_letter' => safetyPdf('application.pdf'), 'technical_attachment' => safetyPdf('technical.pdf'),
    ]);
}

function safetyApplied(string $type = 'new_issue'): SafetyCertificateService
{
    $workflow = app(SafetyCertificateWorkflow::class);

    return $workflow->apply($workflow->ready(safetyDraft($type)), [
        'submitted_at' => '2026-10-06T09:00', 'submission_reference' => 'REF-001', 'authority' => 'Kantor Syahbandar',
    ]);
}

/** @return array<string, string> */
function safetyIssueInput(): array
{
    return ['certificate_number' => 'CERT-2026-0034', 'certificate_issued_at' => '2026-10-06', 'certificate_expired_at' => '2027-10-06', 'issuing_authority' => 'Instansi Berwenang'];
}

function safetyIssued(string $type = 'new_issue'): SafetyCertificateService
{
    return app(SafetyCertificateWorkflow::class)->issueCertificate(safetyApplied($type), safetyIssueInput(), ['issued_certificate' => safetyPdf()]);
}
