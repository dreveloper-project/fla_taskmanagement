<?php

use App\Models\Client;
use App\Models\SijilService;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Services\SijilWorkflow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function sijilPdf(string $name = 'sijil.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
}

/** @return array<string, mixed> */
function sijilInput(Vessel $vessel, string $type = 'sign_on'): array
{
    return ['vessel_id' => $vessel->id, 'process_type' => $type, 'priority' => 'medium'];
}

/** @return array<string, mixed> */
function sijilCrewInput(string $name = 'Andi', string $book = 'SB-001'): array
{
    return ['full_name' => $name, 'rank' => 'Master', 'seafarer_book_number' => $book, 'competency_certificate_number' => 'COC-001', 'action_type' => 'sign_on'];
}

/** @return array<string, UploadedFile> */
function sijilCrewFiles(): array
{
    return ['seafarer_book' => sijilPdf('book.pdf'), 'competency_certificate' => sijilPdf('competency.pdf'), 'pkl' => sijilPdf('pkl.pdf')];
}

function sijilVesselWithAppointment(): Vessel
{
    $vessel = Vessel::factory()->create();
    $appointment = VesselDocument::factory()->for($vessel)->create(['document_type' => 'surat_penunjukan_keagenan']);
    Storage::disk('local')->put($appointment->file_path, '%PDF-1.4 appointment');

    return $vessel;
}

function sijilDraft(): SijilService
{
    test()->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->for(Client::factory())->create();
    $appointment = VesselDocument::factory()->for($vessel)->create(['document_type' => 'surat_penunjukan_keagenan']);
    Storage::disk('local')->put($appointment->file_path, '%PDF-1.4 appointment');

    return app(SijilWorkflow::class)->saveDraft(null, sijilInput($vessel), [sijilCrewInput()], ['application_letter' => sijilPdf('application.pdf'), 'crew_list' => sijilPdf('list.pdf')], [sijilCrewFiles()]);
}

function sijilSubmitted(?SijilService $service = null): SijilService
{
    $workflow = app(SijilWorkflow::class);

    return $workflow->submit($workflow->ready($service ?? sijilDraft()), ['submitted_at' => '2026-10-06T09:00', 'submission_reference' => 'REF-SJL-001']);
}

function sijilApproved(?SijilService $service = null): SijilService
{
    return app(SijilWorkflow::class)->recordReview(sijilSubmitted($service), ['result' => 'approved', 'issued_at' => '2026-10-07', 'result_reference' => 'SIJIL-001'], ['official_result' => sijilPdf('result.pdf')]);
}

function sijilCompleted(): SijilService
{
    return app(SijilWorkflow::class)->applyCrewChanges(sijilApproved(), ['effective_at' => '2026-10-07']);
}

function sijilExistingDraft(SijilService $completed, string $action, string $process): SijilService
{
    $assignment = $completed->vessel->crewAssignments()->whereNotNull('active_crew_id')->sole();
    $row = $assignment->only(['crew_id', 'full_name', 'rank', 'seafarer_book_number', 'competency_certificate_number']) + ['action_type' => $action];

    return app(SijilWorkflow::class)->saveDraft(null, sijilInput($completed->vessel, $process), [$row], ['application_letter' => sijilPdf(), 'crew_list' => sijilPdf()]);
}
