<?php

use App\Models\Client;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

function vesselPayload(array $overrides = []): array
{
    return array_replace([
        'client_id' => '',
        'vessel_name' => 'KM. Nusantara',
        'vessel_type' => 'passenger',
        'owner' => 'PT Pemilik Kapal',
        'flag' => 'Indonesia',
        'port_of_registry' => 'Makassar',
        'registry_number' => 'REG-001',
        'call_sign' => 'YB123',
        'gross_tonnage' => '1200.50',
        'net_tonnage' => '950.25',
        'loa' => '80.50',
        'breadth' => '15.25',
        'draft' => '5.50',
        'build_year' => '2020',
        'operational_status' => 'berthed',
    ], $overrides);
}

test('every file input on the vessel edit form has a drop and paste area', function () {
    $vessel = Vessel::factory()->create();
    $response = $this->actingAs(User::factory()->create())->get(route('vessels.edit', $vessel));
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    try {
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    $xpath = new DOMXPath($document);
    $inputs = $xpath->query('//input[@type="file"]');
    expect($inputs->length)->toBeGreaterThan(1);
    foreach ($inputs as $input) {
        expect($xpath->query('ancestor::*[@data-file-dropzone]', $input)->length)->toBe(1);
    }
});

test('guests cannot open vessel management', function () {
    $this->get(route('dashboard.vessel'))->assertRedirectToRoute('login');
    $this->get(route('vessels.create'))->assertRedirectToRoute('login');
});

test('non staff cannot open vessel management', function () {
    $user = User::factory()->create(['role' => 'external']);

    $this->actingAs($user)->get(route('dashboard.vessel'))->assertForbidden();
    $this->get(route('vessels.create'))->assertForbidden();
    Livewire::actingAs($user)->test('pages::dashboard.vessel')->assertForbidden();
});

test('staff see an empty fleet and can open registration', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard.vessel'))
        ->assertSee('Belum ada kapal yang terdaftar.')
        ->assertSee('Kapal sedang berlayar')
        ->assertSee('Belum ada kapal yang sedang berlayar.')
        ->assertSee('Tambahkan Kapal Baru')
        ->assertDontSee('Demo preview')
        ->assertDontSee('KM. Dharma Kencana VII');

    Livewire::actingAs($user)->test('pages::dashboard.vessel')
        ->call('create')
        ->assertSet('form.flag', 'Indonesia')
        ->assertSee('Vessel Documents')
        ->assertSee('Sijil Awak Kapal');
});

test('staff can register a vessel without a client or documents', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('create')->set('form', vesselPayload())
        ->call('save')->assertHasNoErrors()
        ->assertSee('Vessel berhasil didaftarkan.')
        ->assertSee('KM. Nusantara')
        ->assertSet('showForm', false);

    $this->assertDatabaseHas('vessels', [
        'vessel_name' => 'KM. Nusantara', 'client_id' => null,
        'owner' => 'PT Pemilik Kapal', 'gross_tonnage' => 1200.50,
        'net_tonnage' => 950.25, 'loa' => 80.50, 'breadth' => 15.25,
        'draft' => 5.50, 'build_year' => 2020, 'operational_status' => 'berthed',
        'registry_number' => 'REG-001', 'call_sign' => 'YB123',
        'port_of_registry' => 'Makassar', 'flag' => 'Indonesia',
    ]);
    $this->assertDatabaseCount('vessel_documents', 0);
});

test('staff can register a vessel using only required fields', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('create')
        ->set('form.vessel_name', '  KM. Minimal  ')
        ->set('form.vessel_type', 'cargo')
        ->call('save')->assertHasNoErrors();

    $this->assertDatabaseHas('vessels', [
        'vessel_name' => 'KM. Minimal', 'client_id' => null,
        'gross_tonnage' => null, 'build_year' => null,
    ]);
});

test('registered vessel belongs to the existing client', function () {
    $client = Client::factory()->create();

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('create')->set('form', vesselPayload(['client_id' => $client->id]))
        ->call('save')->assertHasNoErrors()->assertSee($client->name);

    $vessel = Vessel::sole();
    expect($vessel->client->is($client))->toBeTrue();
    expect($client->vessels->sole()->is($vessel))->toBeTrue();
    $this->assertDatabaseHas('vessels', ['id' => $vessel->id, 'client_id' => $client->id, 'owner' => null]);
    $this->assertDatabaseCount('clients', 1);
});

test('invalid vessel inputs are rejected without saving', function (string $field, mixed $value, string $rule) {
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('create')->set('form', vesselPayload())
        ->set('form.'.$field, $value)->call('save')
        ->assertHasErrors(['form.'.$field => $rule]);

    $this->assertDatabaseCount('vessels', 0);
})->with([
    'missing name' => ['vessel_name', '', 'required'],
    'blank name' => ['vessel_name', '   ', 'required'],
    'long name' => ['vessel_name', str_repeat('a', 256), 'max'],
    'invalid client' => ['client_id', 999, 'exists'],
    'missing type' => ['vessel_type', '', 'required'],
    'invalid type' => ['vessel_type', 'random', 'in'],
    'removed tanker type' => ['vessel_type', 'tanker', 'in'],
    'removed tugboat type' => ['vessel_type', 'tugboat', 'in'],
    'removed fishing type' => ['vessel_type', 'fishing', 'in'],
    'removed other type' => ['vessel_type', 'other', 'in'],
    'missing status' => ['operational_status', '', 'required'],
    'invalid status' => ['operational_status', 'random', 'in'],
    'missing flag' => ['flag', '', 'required'],
    'negative tonnage' => ['gross_tonnage', '-1', 'between'],
    'invalid net tonnage' => ['net_tonnage', 'abc', 'numeric'],
    'negative length' => ['loa', '-1', 'between'],
    'negative breadth' => ['breadth', '-1', 'between'],
    'negative draft' => ['draft', '-1', 'between'],
    'excess precision' => ['draft', '1.234', 'decimal'],
    'oversized dimension' => ['loa', '100000000', 'between'],
    'decimal build year' => ['build_year', '2020.5', 'integer'],
    'future build year' => ['build_year', '9999', 'between'],
    'invalid build year' => ['build_year', '999', 'between'],
]);

test('name validation explains the required field', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('create')->call('save')
        ->assertSee('Nama kapal wajib diisi.');
});

test('staff can edit vessel data without changing clients or losing documents', function () {
    Storage::fake('local');
    $client = Client::factory()->create(['identity_number' => '00123']);
    $otherClient = Client::factory()->create();
    $vessel = Vessel::factory()->for($client)->create();
    $document = VesselDocument::factory()->for($vessel)->create();
    Storage::disk('local')->put($document->file_path, 'original contents');

    $page = Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('edit', $vessel->id)->assertSet('form.vessel_name', $vessel->vessel_name)
        ->set('form', vesselPayload(['client_id' => $otherClient->id, 'vessel_name' => 'MT. Updated', 'vessel_type' => 'cargo', 'operational_status' => 'sailing']))
        ->call('save')->assertHasNoErrors()
        ->assertRedirectToRoute('dashboard.vessel');

    expect($page->effects['redirectUsingNavigate'] ?? false)->toBeFalse();
    $this->get(route('dashboard.vessel'))->assertSee('MT. Updated')->assertSee('Data vessel berhasil diperbarui.');

    $this->assertDatabaseHas('vessels', ['id' => $vessel->id, 'vessel_name' => 'MT. Updated', 'client_id' => $otherClient->id, 'operational_status' => 'sailing']);
    $this->assertDatabaseHas('clients', ['id' => $client->id, 'name' => $client->name, 'identity_number' => '00123']);
    $this->assertDatabaseHas('clients', ['id' => $otherClient->id, 'name' => $otherClient->name]);
    $this->assertDatabaseHas('vessel_documents', ['id' => $document->id, 'file_path' => $document->file_path]);
    Storage::disk('local')->assertExists($document->file_path);
    $this->assertDatabaseCount('vessels', 1);
});

test('staff can unlink a client and supply an owner', function () {
    $vessel = Vessel::factory()->for(Client::factory())->create();

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('edit', $vessel->id)->set('form.client_id', '')
        ->set('form.owner', 'Pemilik mandiri')->call('save')->assertHasNoErrors();

    $this->assertDatabaseHas('vessels', ['id' => $vessel->id, 'client_id' => null, 'owner' => 'Pemilik mandiri']);
    $this->assertDatabaseCount('clients', 1);
});

test('canceling registration clears the form without persisting a vessel', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('create')->set('form', vesselPayload())->call('closeForm')
        ->assertSet('showForm', false)->assertSet('form', [])
        ->call('create')->assertSet('form.vessel_name', '');

    $this->assertDatabaseCount('vessels', 0);
});

test('vessel detail renders escaped database values and document information', function () {
    $vessel = Vessel::factory()->create(['vessel_name' => '<script>alert(1)</script>', 'owner' => '<b>Owner</b>']);

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('detail', $vessel->id)
        ->assertSee('Vessel Information')->assertSee('Peringatan dokumen')
        ->assertSee('Belum ada dokumen yang diunggah.')
        ->assertSee($vessel->vessel_name)->assertDontSeeHtml($vessel->vessel_name)
        ->assertSee($vessel->owner)->assertDontSeeHtml($vessel->owner);
});

test('fleet pagination and summary reflect stored vessels', function () {
    $first = Vessel::factory()->create(['vessel_name' => 'First vessel', 'operational_status' => 'maintenance']);
    Vessel::factory()->count(9)->create(['operational_status' => 'sailing']);

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->assertSet('summary', ['total' => 10, 'sailing' => 9, 'berthed' => 0, 'maintenance' => 1])
        ->assertDontSee($first->vessel_name)->call('nextPage')->assertSee($first->vessel_name);
});

test('fleet search matches vessel identities and its client', function (string $field) {
    $client = Client::factory()->create(['name' => $field === 'client' ? 'PT Samudra Pilihan' : 'PT Client Lain']);
    $attributes = ['vessel_name' => 'KM Target', 'owner' => 'Pemilik Lain'];
    if ($field !== 'client') {
        $attributes[$field] = 'Samudra Pilihan';
    }
    $matchingVessel = Vessel::factory()->for($client)->create($attributes);
    Vessel::factory()->create(['vessel_name' => 'KM Tidak Cocok', 'owner' => 'Pemilik Berbeda']);

    $page = Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->set('search', '  Samudra Pilihan  ')
        ->assertSee('1 kapal ditemukan');

    expect($page->get('vessels')->pluck('id')->all())->toBe([$matchingVessel->id]);
})->with(['vessel_name', 'owner', 'registry_number', 'call_sign', 'port_of_registry', 'client']);

test('changing fleet search resets pagination and keeps global sailing statistics', function () {
    $matchingVessel = Vessel::factory()->create(['vessel_name' => 'KM Dicari', 'operational_status' => 'maintenance']);
    Vessel::factory()->count(9)->create(['operational_status' => 'sailing']);

    $page = Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->assertSee('Kapal sedang berlayar')
        ->call('nextPage')->assertSet('paginators.page', 2)
        ->set('search', 'Dicari')->assertSet('paginators.page', 1)
        ->assertSee('KM Dicari')
        ->assertSet('summary', ['total' => 10, 'sailing' => 9, 'berthed' => 0, 'maintenance' => 1]);

    expect($page->get('vessels')->pluck('id')->all())->toBe([$matchingVessel->id]);
});

test('fleet search explains no matches and clearing it restores the fleet', function () {
    $vessel = Vessel::factory()->create(['vessel_name' => 'KM Terdaftar']);

    $page = Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->assertSeeHtml('wire:model.live.debounce.300ms="search"')
        ->set('search', 'Kapal Tidak Ada')
        ->assertSee('Tidak ada kapal yang cocok dengan pencarian.')
        ->assertSee('Hapus pencarian')
        ->assertDontSee('Belum ada kapal yang terdaftar.')
        ->set('search', '')
        ->assertSee('KM Terdaftar')->assertSee('1 kapal terdaftar')
        ->assertDontSee('Hapus pencarian');

    expect($page->get('vessels')->pluck('id')->all())->toBe([$vessel->id]);

    $page->set('search', '   ')->assertSee('KM Terdaftar')->assertSee('1 kapal terdaftar');
});

test('a removed staff role cannot save a vessel', function () {
    $user = User::factory()->create();
    $component = Livewire::actingAs($user)->test('pages::dashboard.vessel')->call('create')->set('form', vesselPayload());
    $user->forceFill(['role' => 'external'])->save();

    $component->call('save')->assertForbidden();

    $this->assertDatabaseCount('vessels', 0);
});

test('vessel identifiers cannot be overwritten from the browser', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->set('editingVesselId', 1);
})->throws(CannotUpdateLockedPropertyException::class);

test('missing vessels return not found when selected', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('detail', 999)->assertNotFound();
});

test('vessel registration opens as a full page from the fleet', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard.vessel'))
        ->assertSee(route('vessels.create'), false)
        ->assertDontSee('wire:click="create"', false);
    $this->get(route('vessels.create'))
        ->assertOk()->assertSee('Tambah Vessel')->assertSee('Vessel Documents')
        ->assertSee('wire:submit="save"', false)
        ->assertSee(route('dashboard.vessel'), false)
        ->assertDontSee('x-ref="vesselFormDialog"', false)
        ->assertDontSee('Ringkasan armada');
});

test('registration page saves vessel and documents then returns to the fleet', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $client = Client::factory()->create();

    Livewire::actingAs($staff)->test('pages::dashboard.vessel', ['registrationPage' => true])
        ->set('form', vesselPayload(['client_id' => $client->id]))
        ->set('documents.surat_laut.file', UploadedFile::fake()->create('surat.pdf', 10, 'application/pdf'))
        ->call('save')->assertHasNoErrors()->assertRedirectToRoute('dashboard.vessel');

    $vessel = Vessel::sole();
    $this->assertDatabaseHas('vessels', ['id' => $vessel->id, 'vessel_name' => 'KM. Nusantara', 'client_id' => $client->id]);
    $document = $vessel->documents()->sole();
    expect($document->document_type)->toBe('surat_laut');
    Storage::disk('local')->assertExists($document->file_path);
    $this->get(route('dashboard.vessel'))->assertSee('Vessel berhasil didaftarkan.')->assertSee('KM. Nusantara');
});

test('registration page stays open with errors when required fields are missing', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel', ['registrationPage' => true])
        ->call('save')->assertHasErrors('form.vessel_name')
        ->assertNoRedirect()->assertSee('Nama kapal wajib diisi.')
        ->assertSet('registrationPage', true)->assertSet('showForm', true);

    $this->assertDatabaseCount('vessels', 0);
});

test('canceling the registration page returns to the fleet without saving', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel', ['registrationPage' => true])
        ->set('form', vesselPayload())->call('closeForm')->assertRedirectToRoute('dashboard.vessel');

    $this->assertDatabaseCount('vessels', 0);
});

test('vessel editing opens a full page with its existing data and navigation links', function () {
    $vessel = Vessel::factory()->create();
    $this->actingAs(User::factory()->create());

    $this->get(route('vessels.edit', $vessel))->assertOk()->assertSee('Edit Vessel')
        ->assertSee($vessel->vessel_name)->assertSee('Vessel Documents')
        ->assertDontSee('x-ref="vesselFormDialog"', false)->assertDontSee('Ringkasan armada');
    $this->get(route('dashboard.vessel'))->assertSee(route('vessels.edit', $vessel), false);
    Livewire::test('pages::dashboard.vessel')->call('detail', $vessel->id)
        ->assertSee(route('vessels.edit', $vessel), false);
});

test('vessel edit page requires system access and an existing vessel', function () {
    $vessel = Vessel::factory()->create();
    $this->get(route('vessels.edit', $vessel))->assertRedirectToRoute('login');
    $this->actingAs(User::factory()->create(['role' => 'external']))
        ->get(route('vessels.edit', $vessel))->assertForbidden();
    $this->actingAs(User::factory()->create())->get(route('vessels.edit', 999))->assertNotFound();
});

test('vessel edit page updates the selected vessel and preserves its documents', function () {
    Storage::fake('local');
    $vessel = Vessel::factory()->create();
    $other = Vessel::factory()->create();
    $document = VesselDocument::factory()->for($vessel)->create();
    Storage::disk('local')->put($document->file_path, 'original document');

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel', ['vessel' => $vessel])
        ->assertSet('editingVesselId', $vessel->id)->assertSet('form.vessel_name', $vessel->vessel_name)
        ->set('form.vessel_name', 'KM. Updated')->call('save')->assertHasNoErrors()
        ->assertRedirectToRoute('dashboard.vessel');

    expect($vessel->fresh()->vessel_name)->toBe('KM. Updated');
    expect($other->fresh()->vessel_name)->toBe($other->vessel_name);
    $this->assertDatabaseCount('vessels', 2);
    $this->assertDatabaseHas('vessel_documents', ['id' => $document->id, 'file_path' => $document->file_path]);
    Storage::disk('local')->assertExists($document->file_path);
    $this->get(route('dashboard.vessel'))->assertSee('Data vessel berhasil diperbarui.');
});

test('staff can change passenger capacity without changing historical captain or crew totals', function () {
    $vessel = Vessel::factory()->create();
    $vessel->forceFill(['captain_name' => 'Nama lama', 'total_crew' => 12])->save();
    $page = Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel', ['vessel' => $vessel]);

    $page->assertDontSee('Nama nakhoda (opsional)')->assertDontSee('Total crew (opsional)')->set('form.captain_name', 'Nama tidak sah')->set('form.total_crew', '99')->set('form.passenger_capacity', '150')
        ->call('save')->assertHasNoErrors()->assertRedirectToRoute('dashboard.vessel');

    $this->assertDatabaseHas('vessels', ['id' => $vessel->id, 'captain_name' => 'Nama lama', 'total_crew' => 12, 'passenger_capacity' => 150]);
    $this->get(route('dashboard.vessel'))->assertDontSee('Nama lama')->assertSee('0 orang')->assertSee('150 orang');

    Livewire::test('pages::dashboard.vessel', ['vessel' => $vessel->fresh()])
        ->assertSet('form.passenger_capacity', 150)
        ->set('form.passenger_capacity', '')
        ->call('save')->assertHasNoErrors();

    $this->assertDatabaseHas('vessels', ['id' => $vessel->id, 'captain_name' => 'Nama lama', 'total_crew' => 12, 'passenger_capacity' => null]);
});

test('vessel passenger capacity rejects invalid values', function (string $field, mixed $value, string $rule) {
    $vessel = Vessel::factory()->create();

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel', ['vessel' => $vessel])
        ->set('form.'.$field, $value)->call('save')->assertHasErrors(['form.'.$field => $rule]);

    $this->assertDatabaseHas('vessels', ['id' => $vessel->id, 'captain_name' => null, 'total_crew' => null]);
})->with([
    ['passenger_capacity', '-1', 'between'],
    ['passenger_capacity', '1.5', 'integer'],
    ['passenger_capacity', '1000001', 'between'],
]);

test('vessel edit page keeps validation errors on the page and cancel leaves data unchanged', function () {
    $vessel = Vessel::factory()->create();
    $page = Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel', ['vessel' => $vessel]);

    $page->set('form.vessel_name', '')->call('save')->assertHasErrors('form.vessel_name')
        ->assertNoRedirect()->assertSee('Nama kapal wajib diisi.')->assertSet('editingPage', true);
    $page->call('closeForm')->assertRedirectToRoute('dashboard.vessel');

    expect($vessel->fresh()->vessel_name)->toBe($vessel->vessel_name);
});

test('example vessel profiles fill missing values without replacing existing data', function () {
    $empty = Vessel::factory()->create(['vessel_type' => 'passenger']);
    $filled = Vessel::factory()->create(['passenger_capacity' => 80]);
    $filled->forceFill(['captain_name' => 'Nakhoda Asli', 'total_crew' => 0])->save();

    $this->seed(\Database\Seeders\VesselProfileExampleSeeder::class);

    expect($empty->fresh()->captain_name)->toBeNull()
        ->and($empty->fresh()->total_crew)->toBeNull()
        ->and($empty->fresh()->passenger_capacity)->toBeGreaterThan(0);
    $this->assertDatabaseHas('vessels', ['id' => $filled->id, 'captain_name' => 'Nakhoda Asli', 'total_crew' => 0, 'passenger_capacity' => 80]);
});
