<?php

use App\Models\Client;
use App\Models\FinanceEntry;
use App\Models\SpbService;
use App\Models\Task;
use App\Models\User;
use App\Models\Vessel;
use App\Services\DocumentFilename;
use App\Services\TaskWorkflow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function wordDocumentUpload(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'word');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>');
    $zip->close();
    $content = file_get_contents($path);
    unlink($path);

    return UploadedFile::fake()->createWithContent('surat.docx', $content);
}

test('word documents can be uploaded and downloaded as vessel documents', function () {
    Storage::fake('local');
    $vessel = Vessel::factory()->create();
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('manageDocuments', $vessel->id)
        ->set('documents.surat_laut.file', wordDocumentUpload())
        ->call('save')->assertHasNoErrors();
    $document = $vessel->documents()->sole();
    Storage::disk('local')->assertExists($document->file_path);
    $this->get(route('vessel-documents.download', [$vessel, $document]))
        ->assertDownload(DocumentFilename::forVessel($document, $vessel, 'surat_laut'));
    expect(DocumentFilename::forVessel($document, $vessel, 'surat_laut'))->toEndWith('.docx');
});

test('finance accepts a Word payment proof', function () {
    Storage::fake('local');
    $spb = SpbService::factory()->create(['status' => 'completed', 'agent_fee' => '12000']);
    Livewire::actingAs(User::factory()->admin()->create())->test('pages::dashboard.finance')
        ->call('startPayment', $spb->id)->set('paymentDate', '2026-11-01')
        ->set('paymentProof', wordDocumentUpload())->call('savePayment')->assertHasNoErrors();
    $entry = FinanceEntry::firstOrFail();
    Storage::disk('local')->assertExists($entry->proof_path);
    $this->get(route('finance.proof', $entry))->assertDownload('surat.docx');
});

test('SPB accepts a Word file as a PNBP transfer proof', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $vessel = Vessel::factory()->for(Client::factory())->create(['vessel_type' => 'cargo']);
    Livewire::actingAs($staff)->test('pages::services.spb-form')->set('form', [
        'vessel_id' => $vessel->id, 'assigned_staff_id' => $staff->id, 'priority' => 'medium',
        'total_crew' => 4, 'total_passengers' => 0, 'cargo_status' => 'not_applicable',
        'port_cargo_activity_applicable' => false, 'departure_port' => 'Makassar', 'destination' => 'Kupang',
        'estimated_departure_at' => '2026-12-01T10:00', 'estimated_port_activity_completion_at' => '2026-12-01T09:00',
        'agent_fee' => '1500000', 'pnbp_labuh' => '100000', 'pnbp_tambat' => '', 'pnbp_mastercable' => '1000',
    ])->set('documents.pnbp_labuh_transfer', wordDocumentUpload())->call('save')->assertHasNoErrors();
    $spb = SpbService::firstOrFail();
    $document = $spb->latestDocument('pnbp_labuh_transfer');
    $this->get(route('spb.documents.download', [$spb, $document]))
        ->assertDownload(DocumentFilename::forService($document, $vessel));
    expect(DocumentFilename::forService($document, $vessel))->toEndWith('.docx');
});

test('task attachments accept supported formats and reject executable extensions', function (string $name, string $mime) {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $task = Task::factory()->create();
    $file = UploadedFile::fake()->create($name, 10, $mime);
    $attachment = app(TaskWorkflow::class)->attach($task, $file);
    $this->get(route('tasks.attachments.download', [$task, $attachment]))->assertDownload($name);
    expect(fn () => app(TaskWorkflow::class)->attach($task, UploadedFile::fake()->create('unsafe.exe', 10, $mime)))->toThrow(ValidationException::class);
})->with([
    ['surat.doc', 'application/msword'],
    ['foto.webp', 'image/webp'],
    ['gambar.gif', 'image/gif'],
]);
