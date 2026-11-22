<?php

use App\Models\BunkerService;
use App\Models\Client;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Services\BunkerWorkflow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function bunkerPdf(string $name = 'bunker.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
}

/** @return array<string, mixed> */
function bunkerInput(Vessel $vessel): array
{
    return ['vessel_id' => $vessel->id, 'supplier' => 'Supplier Flores', 'bunker_location' => 'Labuan Bajo', 'bunker_type' => 'HSD', 'quantity' => '1500.125', 'unit' => 'liter', 'scheduled_at' => '2026-10-07T12:00', 'priority' => 'high'];
}

function bunkerDraft(): BunkerService
{
    test()->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->for(Client::factory())->create(['vessel_type' => 'cargo']);
    $appointment = VesselDocument::factory()->for($vessel)->create(['document_type' => 'surat_penunjukan_keagenan']);
    Storage::disk('local')->put($appointment->file_path, '%PDF-1.4 appointment');
    foreach (['fire_fighting', 'sopep_equipment'] as $type) {
        $document = VesselDocument::factory()->for($vessel)->readyEquipment($type)->create();
        Storage::disk('local')->put($document->file_path, '%PDF-1.4 equipment');
    }

    return app(BunkerWorkflow::class)->saveDraft(null, bunkerInput($vessel), ['application_letter' => bunkerPdf('application.pdf'), 'bunker_request' => bunkerPdf('request.pdf')]);
}

function bunkerSubmitted(): BunkerService
{
    $workflow = app(BunkerWorkflow::class);

    return $workflow->submit($workflow->ready(bunkerDraft()), ['submitted_at' => '2026-10-06T09:00', 'submission_reference' => 'REF-BKR-001']);
}

function bunkerApproved(): BunkerService
{
    return app(BunkerWorkflow::class)->recordReview(bunkerSubmitted(), ['result' => 'approved', 'approved_at' => '2026-10-07T09:00', 'approval_number' => 'PERMIT-001'], ['approval_document' => bunkerPdf('permit.pdf')]);
}

function bunkerScheduled(): BunkerService
{
    $service = bunkerApproved();

    return app(BunkerWorkflow::class)->schedule($service, bunkerInput($service->vessel));
}

/** @return array<string, string> */
function bunkerActual(): array
{
    return ['actual_bunker_at' => '2026-10-07T13:00', 'actual_quantity' => '1400.250', 'actual_unit' => 'liter', 'activity_notes' => 'Kegiatan selesai'];
}
