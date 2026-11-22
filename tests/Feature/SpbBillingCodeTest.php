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

function billingCodeSpbInput(Vessel $vessel): array
{
    return [
        'vessel_id' => $vessel->id, 'assigned_staff_id' => auth()->id(), 'priority' => 'medium',
        'total_passengers' => 0, 'cargo_status' => 'not_applicable', 'port_cargo_activity_applicable' => false,
        'departure_port' => 'Makassar', 'destination' => 'Surabaya', 'estimated_departure_at' => '2026-12-01T10:00',
        'billing_code_labuh' => '001234567890123', 'billing_code_rambu' => 'RMB-002', 'billing_code_mastercable' => 'MC-003',
    ];
}

test('billing codes replace PNBP uploads and are saved, displayed and editable', function () {
    $vessel = Vessel::factory()->create();
    Livewire::test('pages::services.spb-form')
        ->assertSee('Kode billing labuh')->assertDontSee('Bukti transfer PNBP labuh')
        ->set('form', billingCodeSpbInput($vessel))->set('documents.spb_file', UploadedFile::fake()->create('spb.pdf', 10, 'application/pdf'))->call('save')->assertHasNoErrors();
    $spb = SpbService::query()->sole();
    expect($spb->billing_code_labuh)->toBe('001234567890123')
        ->and($spb->billing_code_rambu)->toBe('RMB-002')
        ->and($spb->billing_code_mastercable)->toBe('MC-003');
    $this->get(route('spb.show', $spb))->assertOk()->assertSee('001234567890123')->assertSee('RMB-002')->assertSee('MC-003');
    Livewire::test('pages::services.spb-form', ['spb' => $spb])
        ->assertSet('form.billing_code_labuh', '001234567890123')
        ->set('form.billing_code_labuh', 'NEW-123')->set('form.billing_code_rambu', '')
        ->call('save')->assertHasNoErrors();
    expect($spb->fresh()->billing_code_labuh)->toBe('NEW-123')->and($spb->fresh()->billing_code_rambu)->toBeNull();
});

test('billing codes are optional and reject values over the maximum length', function () {
    $vessel = Vessel::factory()->create();
    $input = billingCodeSpbInput($vessel);
    $input['billing_code_labuh'] = str_repeat('1', 256);
    Livewire::test('pages::services.spb-form')->set('form', $input)->call('save')->assertHasErrors(['billing_code_labuh']);
    expect(SpbService::count())->toBe(0);
    $input['billing_code_labuh'] = '';
    $input['billing_code_rambu'] = '';
    $input['billing_code_mastercable'] = '';
    Livewire::test('pages::services.spb-form')->set('form', $input)->set('documents.spb_file', UploadedFile::fake()->create('spb.pdf', 10, 'application/pdf'))->call('save')->assertHasNoErrors();
    expect(SpbService::query()->sole()->billing_code_labuh)->toBeNull();
});

test('previously uploaded PNBP files remain accessible in the archive', function () {
    $spb = SpbService::factory()->create();
    $document = SpbDocument::factory()->for($spb, 'spbService')->create(['document_type' => 'pnbp_labuh_transfer', 'file_path' => 'spb/old-proof.pdf']);
    Storage::disk('local')->put($document->file_path, 'old proof');
    $this->get(route('spb.show', $spb))->assertOk()->assertSee('Bukti transfer PNBP labuh');
    $this->get(route('spb.documents.download', [$spb, $document]))->assertOk();
});
