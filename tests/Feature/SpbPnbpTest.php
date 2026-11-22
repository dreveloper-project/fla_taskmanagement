<?php

use App\Models\Client;
use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function pnbpInput(Vessel $vessel): array
{
    return [
        'vessel_id' => $vessel->id, 'assigned_staff_id' => auth()->id(),
        'priority' => 'medium', 'total_crew' => 4, 'total_passengers' => 0,
        'cargo_status' => 'not_applicable', 'port_cargo_activity_applicable' => false,
        'departure_port' => 'Makassar', 'destination' => 'Kupang',
        'estimated_departure_at' => '2026-12-01T10:00',
        'estimated_port_activity_completion_at' => '2026-12-01T09:00',
        'agent_fee' => '1500000', 'pnbp_labuh' => '100000.00', 'pnbp_tambat' => '', 'pnbp_mastercable' => '30000',
    ];
}

function pnbpProof(string $name): UploadedFile
{
    return UploadedFile::fake()->create($name, 10, 'application/pdf');
}

test('PNBP amounts and separate transfer proofs are saved with their SPB', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $this->actingAs($user);
    $vessel = Vessel::factory()->for(Client::factory())->create(['vessel_type' => 'cargo']);
    $input = array_replace(pnbpInput($vessel), ['pnbp_tambat' => '25000', 'pnbp_mastercable' => '30000']);
    Livewire::test('pages::services.spb-form')->set('priceMode', 'manual')->set('form', $input)
        ->set('documents.pnbp_labuh_transfer', pnbpProof('labuh.pdf'))
        ->set('documents.pnbp_tambat_transfer', pnbpProof('tambat.pdf'))
        ->set('documents.pnbp_mastercable_transfer', pnbpProof('mastercable.pdf'))
        ->call('save')->assertHasNoErrors();
    $spb = SpbService::firstOrFail();
    expect($spb->agent_fee)->toEqual('1500000.00');
    expect($spb->pnbp_labuh)->toEqual('100000.00')->and($spb->pnbp_tambat)->toEqual('25000.00')->and($spb->pnbp_mastercable)->toEqual('30000.00');
    foreach (['labuh', 'tambat', 'mastercable'] as $type) {
        $document = $spb->latestDocument('pnbp_'.$type.'_transfer');
        Storage::disk('local')->assertExists($document->file_path);
        $this->get(route('spb.documents.download', [$spb, $document]))->assertOk();
    }
    $this->get(route('spb.show', $spb))->assertSee('1.500.000,00')->assertSee('100.000,00')->assertSee('Bukti transfer PNBP labuh');
});

test('labuh and mastercable amounts are required while rambu and transfer proofs are optional', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->for(Client::factory())->create(['vessel_type' => 'cargo']);
    $page = Livewire::test('pages::services.spb-form')->set('priceMode', 'manual')
        ->set('form', array_replace(pnbpInput($vessel), ['pnbp_labuh' => '', 'pnbp_tambat' => '', 'pnbp_mastercable' => '']))
        ->call('save')->assertHasErrors(['pnbp_labuh', 'pnbp_mastercable']);
    expect(SpbService::count())->toBe(0);

    $page->set('form.pnbp_labuh', '100000')->set('form.pnbp_mastercable', '200')->call('save')->assertHasNoErrors();
    $spb = SpbService::sole();
    expect($spb->pnbp_tambat)->toBeNull()->and($spb->pnbp_mastercable)->toEqual('200.00');
    expect($spb->documents)->toHaveCount(0);
});

test('a transfer proof cannot be saved without its corresponding amount', function (string $type) {
    Storage::fake('local');
    $this->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->for(Client::factory())->create(['vessel_type' => 'cargo']);
    $input = array_replace(pnbpInput($vessel), ['pnbp_'.$type => '']);

    Livewire::test('pages::services.spb-form')->set('priceMode', 'manual')->set('form', $input)
        ->set('documents.pnbp_'.$type.'_transfer', pnbpProof('proof.pdf'))
        ->call('save')->assertHasErrors(['pnbp_'.$type]);

    expect(SpbService::count())->toBe(0);
})->with(['labuh', 'tambat', 'mastercable']);

test('existing proofs remain available when an amount changes without a new proof', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $this->actingAs($user);
    $vessel = Vessel::factory()->for(Client::factory())->create(['vessel_type' => 'cargo']);
    Livewire::test('pages::services.spb-form')->set('priceMode', 'manual')->set('form', pnbpInput($vessel))
        ->set('documents.pnbp_labuh_transfer', pnbpProof('labuh.pdf'))->call('save')->assertHasNoErrors();
    $spb = SpbService::firstOrFail();
    $document = $spb->latestDocument('pnbp_labuh_transfer');
    Livewire::test('pages::services.spb-form', ['spb' => $spb])->set('priceMode', 'manual')->set('form.notes', 'Catatan')->set('form.agent_fee', '1800000')
        ->call('save')->assertHasNoErrors();
    expect($spb->fresh()->agent_fee)->toEqual('1800000.00');
    Livewire::test('pages::services.spb-form', ['spb' => $spb])->set('priceMode', 'manual')->set('form.pnbp_labuh', '200000')
        ->call('save')->assertHasNoErrors();
    expect($spb->fresh()->pnbp_labuh)->toEqual('200000.00');
    Storage::disk('local')->assertExists($document->file_path);
});

test('editing cannot add a proof without its PNBP amount', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->for(Client::factory())->create(['vessel_type' => 'cargo']);
    Livewire::test('pages::services.spb-form')->set('priceMode', 'manual')->set('form', pnbpInput($vessel))
        ->call('save')->assertHasNoErrors();
    $spb = SpbService::sole();

    Livewire::test('pages::services.spb-form', ['spb' => $spb])->set('priceMode', 'manual')
        ->set('documents.pnbp_tambat_transfer', pnbpProof('tambat.pdf'))
        ->call('save')->assertHasErrors(['pnbp_tambat']);

    expect($spb->fresh()->documents)->toHaveCount(0);
});

test('editing cannot clear an amount while its saved proof remains available', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->for(Client::factory())->create(['vessel_type' => 'cargo']);
    Livewire::test('pages::services.spb-form')->set('priceMode', 'manual')
        ->set('form', array_replace(pnbpInput($vessel), ['pnbp_tambat' => '100']))
        ->set('documents.pnbp_tambat_transfer', pnbpProof('tambat.pdf'))->call('save')->assertHasNoErrors();
    $spb = SpbService::sole();

    Livewire::test('pages::services.spb-form', ['spb' => $spb])->set('priceMode', 'manual')
        ->set('form.pnbp_tambat', '')->call('save')->assertHasErrors(['pnbp_tambat']);

    expect($spb->fresh()->pnbp_tambat)->toEqual('100.00');
});

test('new SPB requires a valid agent fee', function (string $amount) {
    Storage::fake('local');
    $this->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->for(Client::factory())->create(['vessel_type' => 'cargo']);
    Livewire::test('pages::services.spb-form')->set('priceMode', 'manual')->set('form', array_replace(pnbpInput($vessel), ['agent_fee' => $amount]))
        ->call('save')->assertHasErrors(['agent_fee']);
    expect(SpbService::count())->toBe(0);
})->with(['-1', 'abc', '100.001']);

test('an empty SPB agent fee is saved as zero', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->for(Client::factory())->create(['vessel_type' => 'cargo']);
    Livewire::test('pages::services.spb-form')->set('priceMode', 'manual')->set('form', array_replace(pnbpInput($vessel), ['agent_fee' => '']))
        ->call('save')->assertHasNoErrors();

    expect(SpbService::sole()->agent_fee)->toBe('0.00');
});
