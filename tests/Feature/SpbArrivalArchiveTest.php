<?php

use App\Models\SpbDocument;
use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
});

function arrivalArchiveInput(Vessel $vessel): array
{
    return [
        'vessel_id' => $vessel->id,
        'total_passengers' => 0,
        'passengers_none' => true,
        'cargo_status' => 'not_applicable',
        'port_cargo_activity_applicable' => false,
        'departure_port' => 'Makassar',
        'destination' => 'Surabaya',
        'estimated_departure_at' => now(config('spb.timezone'))->addDay()->format('Y-m-d\TH:i'),
    ];
}

test('arrival fields are shown only when selected and are optional', function () {
    $vessel = Vessel::factory()->create();
    Livewire::test('pages::services.spb-form')
        ->assertSee('Tambahkan SPB kedatangan')
        ->assertDontSee('Kode billing labuh kedatangan')
        ->set('form', arrivalArchiveInput($vessel))
        ->set('form.arrival_spb_enabled', true)
        ->assertSee('Kode billing labuh kedatangan')
        ->assertSee('Kode billing rambu kedatangan')
        ->assertSee('Kode billing master cable kedatangan')
        ->assertSee('File SPB kedatangan (opsional)')
        ->assertSee('Surat Persetujuan Masuk (opsional)')
        ->assertSee('PKK (opsional)')
        ->assertDontSee('LK3 kedatangan (opsional)')
        ->set('documents.spb_file', UploadedFile::fake()->create('spb.pdf', 10, 'application/pdf'))
        ->call('save')->assertHasNoErrors();

    expect(SpbService::query()->sole()->arrival_spb_enabled)->toBeTrue();
});

test('arrival billing codes and files are archived separately and can be edited', function () {
    $vessel = Vessel::factory()->create();
    Livewire::test('pages::services.spb-form')
        ->set('form', arrivalArchiveInput($vessel))
        ->set('form.billing_code_labuh', 'BERANGKAT')
        ->set('form.arrival_spb_enabled', true)
        ->set('form.arrival_billing_code_labuh', '00123')
        ->set('form.arrival_billing_code_rambu', 'RAMBU-01')
        ->set('form.arrival_billing_code_mastercable', 'MC-01')
        ->set('documents.spb_file', UploadedFile::fake()->create('spb.pdf', 10, 'application/pdf'))
        ->set('documents.arrival_spb', UploadedFile::fake()->create('arrival.pdf', 10, 'application/pdf'))
        ->set('documents.arrival_entry_approval', UploadedFile::fake()->create('izin.pdf', 10, 'application/pdf'))
        ->set('documents.arrival_pkk', UploadedFile::fake()->create('pkk.pdf', 10, 'application/pdf'))
        ->call('save')->assertHasNoErrors();

    $spb = SpbService::query()->sole();
    expect($spb->billing_code_labuh)->toBe('BERANGKAT')
        ->and($spb->arrival_billing_code_labuh)->toBe('00123')
        ->and($spb->arrival_billing_code_rambu)->toBe('RAMBU-01')
        ->and($spb->arrival_billing_code_mastercable)->toBe('MC-01');
    foreach (['arrival_spb', 'arrival_entry_approval', 'arrival_pkk'] as $type) {
        $document = $spb->latestDocument($type);
        Storage::disk('local')->assertExists($document->file_path);
        $this->get(route('spb.documents.download', [$spb, $document]))->assertOk();
    }
    $this->get(route('spb.show', $spb))->assertOk()->assertSee('00123')->assertSee('Surat Persetujuan Masuk')->assertSee('PKK');

    Livewire::test('pages::services.spb-form', ['spb' => $spb])
        ->assertSet('form.arrival_spb_enabled', true)
        ->assertSet('form.arrival_billing_code_labuh', '00123')
        ->set('form.arrival_billing_code_labuh', 'NEW')
        ->set('documents.arrival_entry_approval', UploadedFile::fake()->create('izin-baru.pdf', 10, 'application/pdf'))
        ->call('save')->assertHasNoErrors();
    expect($spb->fresh()->arrival_billing_code_labuh)->toBe('NEW')
        ->and($spb->documents()->where('document_type', 'arrival_entry_approval')->count())->toBe(2);

    Livewire::test('pages::services.spb-form', ['spb' => $spb])
        ->set('form.arrival_spb_enabled', false)->call('save')->assertHasNoErrors();
    expect($spb->fresh()->arrival_spb_enabled)->toBeFalse()
        ->and($spb->fresh()->arrival_billing_code_labuh)->toBe('NEW')
        ->and($spb->documents()->where('document_type', 'arrival_entry_approval')->count())->toBe(2);
});

test('arrival billing codes and files are validated', function () {
    $vessel = Vessel::factory()->create();
    $page = Livewire::test('pages::services.spb-form')
        ->set('form', arrivalArchiveInput($vessel))
        ->set('form.arrival_spb_enabled', true)
        ->set('form.arrival_billing_code_labuh', str_repeat('1', 256))
        ->set('documents.spb_file', UploadedFile::fake()->create('spb.pdf', 10, 'application/pdf'));
    $page->call('save')->assertHasErrors(['arrival_billing_code_labuh']);
    $page->set('form.arrival_billing_code_labuh', 'valid')
        ->set('documents.arrival_pkk', UploadedFile::fake()->create('pkk.txt', 10, 'text/plain'))
        ->call('save')->assertHasErrors(['documents.arrival_pkk']);
    expect(SpbService::query()->count())->toBe(0);
});

test('existing arrival LK3 documents remain accessible in the archive', function () {
    $spb = SpbService::factory()->create(['status' => 'completed']);
    $document = SpbDocument::factory()->for($spb, 'spbService')->create(['document_type' => 'arrival_lk3']);
    Storage::disk('local')->put($document->file_path, '%PDF-1.4 archive');

    $this->get(route('spb.show', $spb))->assertOk()->assertSee('LK3 kedatangan');
    $this->get(route('spb.documents.download', [$spb, $document]))->assertOk();
});
