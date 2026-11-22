<?php

use App\Models\Client;
use App\Models\User;
use Livewire\Livewire;

test('guests must sign in to view clients', function () {
    $this->get(route('dashboard.clients'))->assertRedirect(route('login'));
});

test('non staff cannot access clients', function () {
    $user = User::factory()->create(['role' => 'guest']);

    $this->actingAs($user)->get(route('dashboard.clients'))->assertForbidden();
    Livewire::actingAs($user)->test('pages::dashboard.clients')->assertForbidden();
});

test('staff can view clients and the sidebar link', function () {
    $client = Client::factory()->create();

    $this->actingAs(User::factory()->create())->get(route('dashboard.clients'))
        ->assertSee($client->name)
        ->assertSee('Tambah client')
        ->assertSee(route('dashboard.clients'));
});

test('staff can register clients with optional identity', function (string $identity, ?string $savedIdentity) {
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.clients')
        ->set('name', '  PT Lautan Abadi  ')
        ->set('identity_number', $identity)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Client berhasil didaftarkan.')
        ->assertSet('name', '');

    $this->assertDatabaseHas('clients', ['name' => 'PT Lautan Abadi', 'identity_number' => $savedIdentity]);
    $this->assertDatabaseCount('clients', 1);
})->with([
    'without identity' => ['', null],
    'blank identity' => ['   ', null],
    'formatted identity' => ['  01.234.567.8-901.000  ', '01.234.567.8-901.000'],
]);

test('invalid client data is rejected', function (string $field, string $value, string $rule, string $message) {
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.clients')
        ->set('name', 'Client valid')
        ->set($field, $value)
        ->call('save')
        ->assertHasErrors([$field => $rule])
        ->assertSee($message);

    $this->assertDatabaseCount('clients', 0);
})->with([
    'missing name' => ['name', '', 'required', 'Nama client wajib diisi.'],
    'blank name' => ['name', '   ', 'required', 'Nama client wajib diisi.'],
    'long name' => ['name', str_repeat('a', 256), 'max', 'Nama client maksimal 255 karakter.'],
    'long identity' => ['identity_number', str_repeat('1', 256), 'max', 'Identitas client maksimal 255 karakter.'],
]);

test('staff can edit a client and clear optional identity', function () {
    $client = Client::factory()->create(['identity_number' => '00123']);
    $other = Client::factory()->create();

    $page = Livewire::actingAs(User::factory()->create())->test('pages::dashboard.clients')
        ->call('edit', $client->id)
        ->assertSet('name', $client->name)
        ->assertSet('identity_number', '00123')
        ->set('name', 'CV Bahari')
        ->set('identity_number', '')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirectToRoute('dashboard.clients')
        ->assertSet('editingClientId', null);

    expect($page->effects['redirectUsingNavigate'] ?? false)->toBeFalse();
    $this->get(route('dashboard.clients'))->assertSee('CV Bahari')->assertSee('Data client berhasil diperbarui.');

    $this->assertDatabaseHas('clients', ['id' => $client->id, 'name' => 'CV Bahari', 'identity_number' => null]);
    $this->assertDatabaseHas('clients', ['id' => $other->id, 'name' => $other->name]);
    $this->assertDatabaseCount('clients', 2);
});

test('editing a client reloads the same list page with its updated data', function () {
    $client = Client::factory()->create(['name' => 'Client halaman kedua']);
    Client::factory()->count(10)->create();

    $page = Livewire::actingAs(User::factory()->create())->test('pages::dashboard.clients')
        ->call('setPage', 2)->call('edit', $client->id)->set('name', 'Client terbaru halaman kedua')
        ->call('save')->assertHasNoErrors()
        ->assertRedirect(route('dashboard.clients', ['page' => 2]));

    expect($page->effects['redirectUsingNavigate'] ?? false)->toBeFalse();
    $this->get(route('dashboard.clients', ['page' => 2]))->assertSee('Client terbaru halaman kedua');
    expect($client->fresh()->name)->toBe('Client terbaru halaman kedua');
});

test('canceling an edit leaves the stored client unchanged', function () {
    $client = Client::factory()->create();

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.clients')
        ->call('edit', $client->id)
        ->set('name', 'Unsaved name')
        ->call('cancel')
        ->assertSet('editingClientId', null)
        ->assertSet('name', '')
        ->assertSet('identity_number', '');

    $this->assertDatabaseHas('clients', ['id' => $client->id, 'name' => $client->name]);
});

test('client list escapes user supplied content and paginates', function () {
    $first = Client::factory()->create(['name' => 'First client']);
    Client::factory()->count(9)->create();
    $client = Client::factory()->create(['name' => '<script>alert(1)</script>', 'identity_number' => '<b>123</b>']);

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.clients')
        ->assertSee($client->name)
        ->assertSee($client->identity_number)
        ->assertDontSeeHtml($client->name)
        ->assertDontSeeHtml($client->identity_number)
        ->assertDontSee($first->name)
        ->call('nextPage')
        ->assertSee($first->name);
});

test('missing clients cannot be edited', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.clients')
        ->call('edit', 999)->assertNotFound();
});

test('clients can be found by partial name or identity', function (string $search) {
    $matching = Client::factory()->create(['name' => 'PT Samudra Nusantara', 'identity_number' => 'NPWP-0012345']);
    Client::factory()->create(['name' => 'CV Bahari', 'identity_number' => 'OTHER-987']);

    $page = Livewire::actingAs(User::factory()->create())->test('pages::dashboard.clients')
        ->set('search', $search);

    expect($page->get('clients')->modelKeys())->toBe([$matching->id]);
})->with(['name' => '  Samudra  ', 'identity' => '001234']);

test('search and sorting return the client list to its first page', function () {
    Client::factory()->count(12)->create(['name' => 'Matching client']);

    $page = Livewire::actingAs(User::factory()->create())->test('pages::dashboard.clients')
        ->call('setPage', 2)->set('search', 'Matching');
    expect($page->get('clients')->currentPage())->toBe(1);

    $page->call('setPage', 2)->set('sort', 'name_asc');
    expect($page->get('clients')->currentPage())->toBe(1);
});

test('client sorting uses only supported orderings', function (string $sort, array $names) {
    Client::factory()->create(['name' => 'Zulu']);
    Client::factory()->create(['name' => 'Alpha']);
    Client::factory()->create(['name' => 'Bravo']);

    $page = Livewire::actingAs(User::factory()->create())->test('pages::dashboard.clients')->set('sort', $sort);

    expect($page->get('clients')->pluck('name')->all())->toBe($names);
})->with([
    'alphabetical' => ['name_asc', ['Alpha', 'Bravo', 'Zulu']],
    'reverse alphabetical' => ['name_desc', ['Zulu', 'Bravo', 'Alpha']],
    'latest' => ['latest', ['Bravo', 'Alpha', 'Zulu']],
    'untrusted order' => ['name; DROP TABLE clients', ['Bravo', 'Alpha', 'Zulu']],
]);

test('an empty search result offers a way back to the list', function () {
    $client = Client::factory()->create(['name' => 'PT Samudra']);

    $page = Livewire::actingAs(User::factory()->create())->test('pages::dashboard.clients')
        ->set('search', 'No matching client')->assertSee('Client tidak ditemukan')->assertSee('Hapus pencarian')
        ->call('clearSearch')->assertSet('search', '')->assertSee($client->name);

    expect($page->get('clients')->modelKeys())->toBe([$client->id]);
});

test('opening a new client form clears the previous edit and cancellation keeps list context', function () {
    $client = Client::factory()->create();

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.clients')
        ->set('search', 'Samudra')->set('sort', 'name_asc')
        ->call('edit', $client->id)->assertSet('showClientForm', true)
        ->call('create')->assertSet('editingClientId', null)->assertSet('name', '')
        ->set('name', 'Unsaved client')->call('cancel')
        ->assertSet('showClientForm', false)->assertSet('name', '')
        ->assertSet('search', 'Samudra')->assertSet('sort', 'name_asc');

    $this->assertDatabaseCount('clients', 1);
});

test('validation keeps the client form open with the entered values', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.clients')
        ->call('create')->set('identity_number', 'KEEP-123')->call('save')
        ->assertHasErrors('name')->assertSet('showClientForm', true)
        ->assertSet('identity_number', 'KEEP-123')->assertSee('Nama client wajib diisi.');

    $this->assertDatabaseCount('clients', 0);
});

test('editing preserves the active search and sort after saving', function () {
    $client = Client::factory()->create(['name' => 'PT Samudra']);

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.clients')
        ->set('search', 'Samudra')->set('sort', 'name_asc')
        ->call('edit', $client->id)->set('identity_number', '123')->call('save')
        ->assertHasNoErrors()->assertSet('showClientForm', false)
        ->assertRedirect(route('dashboard.clients', ['search' => 'Samudra', 'sort' => 'name_asc']));

    expect($client->fresh()->identity_number)->toBe('123');
});

test('a newly added client is visible after saving from a filtered list', function () {
    $page = Livewire::actingAs(User::factory()->create())->test('pages::dashboard.clients')
        ->set('search', 'Other name')->set('sort', 'name_asc')
        ->call('create')->set('name', 'New client')->call('save')
        ->assertHasNoErrors()->assertSet('showClientForm', false)
        ->assertSet('search', '')->assertSet('sort', 'latest')->assertSee('New client');

    expect($page->get('clients')->total())->toBe(1);
    $this->assertDatabaseHas('clients', ['name' => 'New client']);
});

test('client rows show their own registered vessel counts', function () {
    $client = Client::factory()->create(['name' => 'PT Armada']);
    $other = Client::factory()->create(['name' => 'CV Armada Lain']);
    \App\Models\Vessel::factory()->for($client)->count(2)->create();
    \App\Models\Vessel::factory()->for($other)->create();

    $page = Livewire::actingAs(User::factory()->create())->test('pages::dashboard.clients');

    expect($page->get('clients')->pluck('vessels_count', 'id')->all())->toBe([$other->id => 1, $client->id => 2]);
});
