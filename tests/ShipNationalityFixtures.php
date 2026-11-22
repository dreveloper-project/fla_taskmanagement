<?php

use App\Models\Client;
use App\Models\ShipNationalityService;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Services\ShipNationalityWorkflow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function nationalityPdf(string $name = 'document.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
}

/** @return array<string, mixed> */
function nationalityInput(Vessel $vessel, string $type = 'surat_laut', string $request = 'new_issue'): array
{
    return ['vessel_id' => $vessel->id, 'document_type' => $type, 'request_type' => $request, 'priority' => 'medium', 'notes' => 'Pengurusan dokumen kebangsaan'];
}

function nationalityDraft(string $request = 'new_issue', string $type = 'surat_laut'): ShipNationalityService
{
    test()->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->for(Client::factory())->create(['vessel_type' => 'cargo']);
    foreach (['surat_penunjukan_keagenan', 'surat_ukur', 'grosse_akta'] as $documentType) {
        $document = VesselDocument::factory()->for($vessel)->create(['document_type' => $documentType]);
        Storage::disk('local')->put($document->file_path, '%PDF-1.4 vessel');
    }
    if ($request === 'renewal') {
        $previous = VesselDocument::factory()->for($vessel)->create([
            'document_type' => $type, 'certificate_number' => 'OLD-NATIONALITY',
            'issued_date' => '2025-10-01', 'expired_date' => '2026-10-01',
        ]);
        Storage::disk('local')->put($previous->file_path, '%PDF-1.4 old document');
    }

    return app(ShipNationalityWorkflow::class)->saveDraft(null, nationalityInput($vessel, $type, $request), ['application_letter' => nationalityPdf('application.pdf')]);
}

function nationalityApplied(string $request = 'new_issue', string $type = 'surat_laut'): ShipNationalityService
{
    $workflow = app(ShipNationalityWorkflow::class);

    return $workflow->apply($workflow->ready(nationalityDraft($request, $type)), ['submitted_at' => '2026-10-06T09:00', 'submission_reference' => 'REF-NATIONALITY']);
}

/** @return array<string, string> */
function nationalityIssueInput(): array
{
    return ['issued_document_number' => 'NEW-NATIONALITY', 'issued_at' => '2026-10-06', 'expired_at' => '2027-10-06', 'issuing_authority' => 'Kantor penerbit'];
}

function nationalityIssued(string $request = 'new_issue', string $type = 'surat_laut'): ShipNationalityService
{
    return app(ShipNationalityWorkflow::class)->issueDocument(nationalityApplied($request, $type), nationalityIssueInput(), ['issued_document' => nationalityPdf()]);
}
