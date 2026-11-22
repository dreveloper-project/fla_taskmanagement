<?php

use App\Models\Client;
use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Services\FinanceInvoicing;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    config([
        'spb.required_spb_documents' => [],
        'spb.safety_equipment_documents' => [],
        'spb.required_vessel_documents' => [
            ['label' => 'Surat Penunjukan Keagenan', 'types' => ['surat_penunjukan_keagenan'], 'requires_expiry' => false],
        ],
    ]);
    $this->travelTo(now('Asia/Makassar')->setDate(2026, 10, 6)->setTime(12, 0));
    $this->staff = User::factory()->create();
    $vessel = Vessel::factory()->create(['vessel_type' => 'cargo']);
    $this->letter = VesselDocument::factory()->for($vessel)->create(['document_type' => 'surat_penunjukan_keagenan']);
    Storage::disk('local')->put($this->letter->file_path, 'letter');
    $this->spb = SpbService::factory()->for($vessel)->create([
        'status' => 'ready_to_apply', 'assigned_staff_id' => $this->staff->id,
        'total_passengers' => 0, 'cargo_status' => 'not_applicable',
        'submitted_at' => null, 'resubmitted_at' => null,
    ]);
});

test('reply can be recorded directly after readiness', function (string $result, array $fields, string $status) {
    $page = Livewire::actingAs($this->staff)->test('pages::services.spb-detail', ['spb' => $this->spb])
        ->assertSee('Reply From Inaportnet')->assertDontSee('Mark as Applied to Inaportnet')
        ->set('form.result', $result);
    foreach ($fields as $field => $value) {
        $page->set('form.'.$field, $value);
    }
    if ($result === 'approved') {
        $page->set('documents.issued_spb', UploadedFile::fake()->create('spb.pdf', 10, 'application/pdf'));
    }
    $page->call('recordResult')->assertHasNoErrors();
    $spb = $this->spb->fresh();
    expect($spb->status)->toBe($status)->and($spb->submitted_at)->toBeNull()
        ->and($spb->documents()->where('source_document_type', 'surat_penunjukan_keagenan')->count())->toBe(1);
})->with([
    'rejected' => ['rejected', ['rejection_reason' => 'Dokumen ditolak'], 'rejected'],
    'revision' => ['revision_required', ['revision_notes' => 'Perbaiki', 'revision_items' => 'Manifest'], 'revision_required'],
    'approved' => ['approved', ['spb_number' => 'SPB-123', 'spb_issued_at' => '2026-10-06T11:00'], 'ready_to_sail'],
]);

test('revision requires readiness again before recording the next reply', function () {
    $page = Livewire::actingAs($this->staff)->test('pages::services.spb-detail', ['spb' => $this->spb])
        ->set('form.result', 'revision_required')->set('form.revision_notes', 'Perbaiki')
        ->set('form.revision_items', 'Manifest')->call('recordResult')->assertHasNoErrors()
        ->assertDontSee('Reply From Inaportnet')
        ->set('form.result', 'rejected')->set('form.rejection_reason', 'Ditolak')
        ->call('recordResult')->assertHasErrors('state')
        ->call('ready')->assertHasNoErrors()->assertSee('Reply From Inaportnet')
        ->assertDontSee('Mark as Resubmitted')
        ->set('form.result', 'rejected')->set('form.rejection_reason', 'Ditolak')
        ->call('recordResult')->assertHasNoErrors();
    expect($this->spb->fresh()->status)->toBe('rejected')->and($this->spb->fresh()->revision_ready_at)->toBeNull();
});

test('an approved reply can include the optional LK3 Nakhoda document', function () {
    Livewire::actingAs($this->staff)->test('pages::services.spb-detail', ['spb' => $this->spb])
        ->assertDontSee('LK3 Nakhoda (opsional)')
        ->set('form.result', 'approved')->assertSee('LK3 Nakhoda (opsional)')
        ->set('form.spb_number', 'SPB-123')->set('form.spb_issued_at', '2026-10-06T11:00')
        ->set('documents.issued_spb', UploadedFile::fake()->create('spb.pdf', 10, 'application/pdf'))
        ->set('documents.lk3_nakhoda', UploadedFile::fake()->create('lk3.pdf', 10, 'application/pdf'))
        ->call('recordResult')->assertHasNoErrors()->assertSee('LK3 Nakhoda');

    $document = $this->spb->fresh()->documents()->where('document_type', 'lk3_nakhoda')->sole();
    Storage::disk('local')->assertExists($document->file_path);
    $this->get(route('spb.documents.download', [$this->spb, $document]))->assertOk();
});

test('an approved reply can be recorded without an SPB number', function () {
    Livewire::actingAs($this->staff)->test('pages::services.spb-detail', ['spb' => $this->spb])
        ->set('form.result', 'approved')->assertSee('SPB Number (opsional)')
        ->set('form.spb_issued_at', '2026-10-06T11:00')
        ->set('documents.issued_spb', UploadedFile::fake()->create('spb.pdf', 10, 'application/pdf'))
        ->call('recordResult')->assertHasNoErrors()->assertSee('SPB Number: Belum dicatat');

    expect($this->spb->fresh()->status)->toBe('ready_to_sail')
        ->and($this->spb->fresh()->spb_number)->toBeNull();
});

test('missing vessel documents prevent a direct reply and leave the service unchanged', function () {
    Storage::disk('local')->delete($this->letter->file_path);
    Livewire::actingAs($this->staff)->test('pages::services.spb-detail', ['spb' => $this->spb])
        ->set('form.result', 'rejected')->set('form.rejection_reason', 'Ditolak')
        ->call('recordResult')->assertHasErrors('readiness');
    expect($this->spb->fresh()->status)->toBe('ready_to_apply');
});

test('approved SPB can be marked not sailing and paid when billing is selected', function () {
    $client = Client::factory()->create();
    $this->spb->forceFill(['client_id' => $client->id, 'agent_fee' => '100000'])->save();
    Livewire::actingAs($this->staff)->test('pages::services.spb-detail', ['spb' => $this->spb])
        ->set('form.result', 'approved')->set('form.spb_number', 'SPB-123')->set('form.spb_issued_at', '2026-10-06T11:00')
        ->set('documents.issued_spb', UploadedFile::fake()->create('spb.pdf', 10, 'application/pdf'))
        ->call('recordResult')->assertHasNoErrors()->assertSee('Not Sailing')
        ->set('form.sailing_result', 'not_sailing')->set('form.billable', '1')
        ->call('finish')->assertHasNoErrors();
    expect($this->spb->fresh()->status)->toBe('completed')->and($this->spb->fresh()->billable)->toBeTrue()
        ->and($this->spb->tasks()->active()->count())->toBe(0);

    $this->actingAs(User::factory()->admin()->create());
    $invoicing = app(FinanceInvoicing::class);
    $invoice = $invoicing->createManual($client->id, ['spb:'.$this->spb->id], '2026-10-06');
    $invoicing->recordPayment($invoice->id, '100000', '2026-10-06', UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'), (string) Str::uuid());
    Livewire::test('pages::dashboard.finance')->call('setServiceBillTab', 'paid')->assertSee($this->spb->service_number);
});
