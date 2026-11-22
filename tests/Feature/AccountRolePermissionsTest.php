<?php

use App\Models\Client;
use App\Models\FinanceEntry;
use App\Models\User;
use App\Models\Vessel;
use App\Services\ServiceAssignment;
use App\Services\StaffManagement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

test('roles can access only their permitted pages', function (string $role, int $financeStatus, int $reportStatus, int $staffStatus, int $vesselEditStatus, int $serviceCreateStatus) {
    $user = User::factory()->create(['role' => $role]);
    $vessel = Vessel::factory()->create();
    $this->actingAs($user);
    $this->get(route('dashboard.vessel'))->assertOk();
    $this->get(route('spb.index'))->assertOk();
    $this->get(route('finance.index'))->assertStatus($financeStatus);
    $this->get(route('reports.index'))->assertStatus($reportStatus);
    $this->get(route('staff.index'))->assertStatus($staffStatus);
    $this->get(route('vessels.edit', $vessel))->assertStatus($vesselEditStatus);
    $this->get(route('spb.create'))->assertStatus($serviceCreateStatus);
})->with([
    ['superadmin', 200, 200, 200, 200, 200],
    ['admin', 200, 200, 200, 200, 200],
    ['staff', 403, 403, 403, 200, 200],
    ['finance', 200, 200, 403, 403, 403],
]);

test('finance can update client prices but cannot edit client or vessel data', function () {
    $finance = User::factory()->create(['role' => 'finance']);
    $client = Client::factory()->create(['service_prices' => ['spb' => '100']]);
    $vessel = Vessel::factory()->create();
    Livewire::actingAs($finance)->test('pages::dashboard.clients')
        ->assertDontSee('wire:click="create"', false)
        ->call('editAgencyPrices', $client->id)
        ->set('agencyPrices.spb', '250')
        ->call('saveAgencyPrices')->assertHasNoErrors();
    expect($client->fresh()->service_prices['spb'])->toBe('250');
    Livewire::actingAs($finance)->test('pages::dashboard.clients')->call('edit', $client->id)->assertForbidden();
    Livewire::actingAs($finance)->test('pages::dashboard.clients')->call('save')->assertForbidden();
    Livewire::actingAs($finance)->test('pages::dashboard.vessel')->call('edit', $vessel->id)->assertForbidden();
    Livewire::actingAs($finance)->test('pages::dashboard.vessel')->call('save')->assertForbidden();
    expect(fn () => app(ServiceAssignment::class)->prepare(null, []))->toThrow(AuthorizationException::class);
});

test('staff cannot change client prices or assign another PIC', function () {
    $staff = User::factory()->create();
    $other = User::factory()->create();
    $client = Client::factory()->create(['service_prices' => ['spb' => '100']]);
    $this->actingAs($staff);
    Livewire::test('pages::dashboard.clients')->call('editAgencyPrices', $client->id)->assertForbidden();
    Livewire::test('pages::dashboard.clients')->call('edit', $client->id)
        ->set('servicePrices.spb', '999')->call('save')->assertHasNoErrors();
    expect($client->fresh()->service_prices['spb'])->toBe('100');
    expect(fn () => app(ServiceAssignment::class)->prepare(null, ['assigned_staff_id' => $other->id]))->toThrow(AuthorizationException::class);
    expect(app(ServiceAssignment::class)->prepare(null, [])['assigned_staff_id'])->toBe($staff->id);
    $this->get(route('reports.export', ['kind' => 'services']))->assertForbidden();
});

test('administrators can create other roles but cannot create or modify superadmin accounts', function (string $actorRole) {
    $this->actingAs(User::factory()->create(['role' => $actorRole]));
    $manager = app(StaffManagement::class);
    $input = ['fullname' => 'New Account', 'username' => 'new.account', 'email' => 'new@example.test',
        'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'role' => 'finance'];
    $finance = $manager->save(null, $input);
    expect($finance->role)->toBe('finance');
    $manager->save($finance, array_replace($input, ['role' => 'admin', 'status' => 'active']));
    expect($finance->fresh()->role)->toBe('admin');
    expect(fn () => $manager->save(null, array_replace($input, ['username' => 'forged', 'email' => 'forged@example.test', 'role' => 'superadmin'])))
        ->toThrow(ValidationException::class);
    $superadmin = User::factory()->create(['role' => 'superadmin']);
    Livewire::test('pages::dashboard.staff-management')->assertSee($superadmin->name)->call('edit', $superadmin->id)->assertNotFound();
    expect(fn () => $manager->setStatus($superadmin, 'inactive'))->toThrow(HttpException::class);
})->with(['admin', 'superadmin']);

test('finance sees the staff performance report and cannot open service forms', function () {
    $this->actingAs(User::factory()->create(['role' => 'finance']));
    Livewire::test('pages::dashboard.reports')->call('selectTab', 'staff')->assertSee('Staff Performance');
    foreach (['spb', 'bunker', 'safety-certificates', 'sijil', 'ship-nationality'] as $type) {
        $this->get(route($type.'.create'))->assertForbidden();
    }
});

test('finance can download payment proofs while staff cannot', function () {
    Storage::fake('local');
    Storage::disk('local')->put('finance/proof.pdf', 'payment proof');
    $entry = FinanceEntry::factory()->create(['proof_path' => 'finance/proof.pdf', 'proof_filename' => 'proof.pdf']);

    $this->actingAs(User::factory()->create(['role' => 'finance']))->get(route('finance.proof', $entry))->assertDownload('proof.pdf');
    $this->actingAs(User::factory()->create(['role' => 'staff']))->get(route('finance.proof', $entry))->assertForbidden();
});
