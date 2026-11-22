<?php

use App\Models\BunkerService;
use App\Models\SafetyCertificateService;
use App\Models\ShipNationalityService;
use App\Models\SijilService;
use App\Models\SpbService;
use App\Models\User;
use App\Services\ServiceFeeManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('administrator can set and update a fee on each service type', function (string $type, string $class) {
    $this->actingAs(User::factory()->admin()->create());
    $service = $class::factory()->create(['status' => 'completed']);
    $manager = app(ServiceFeeManager::class);
    $manager->update($type, $service->id, '1250000.50');
    expect($service->fresh()->agent_fee)->toEqual('1250000.50')->and($service->histories()->count())->toBe(1);
    $manager->update($type, $service->id, '1250000.50');
    expect($service->histories()->count())->toBe(1);
})->with([
    ['spb', SpbService::class], ['bunker', BunkerService::class],
    ['safety-certificates', SafetyCertificateService::class], ['sijil', SijilService::class],
    ['ship-nationality', ShipNationalityService::class],
]);

test('invalid fees and unprivileged edits are rejected', function () {
    $service = BunkerService::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
    expect(fn () => app(ServiceFeeManager::class)->update('bunker', $service->id, '-1'))->toThrow(ValidationException::class);
    $this->actingAs(User::factory()->create());
    expect(fn () => app(ServiceFeeManager::class)->update('bunker', $service->id, '100'))->toThrow(AuthorizationException::class);
    expect($service->fresh()->agent_fee)->toBeNull();
});

test('finance can price a completed service and leaves other services unchanged', function () {
    $admin = User::factory()->admin()->create();
    $completed = BunkerService::factory()->create(['status' => 'completed']);
    $draft = BunkerService::factory()->create(['status' => 'draft']);
    Livewire::actingAs($admin)->test('pages::dashboard.finance')->assertSee($completed->service_number)
        ->assertDontSee($draft->service_number)->call('editServiceFee', $completed->id)
        ->set('feeAmount', '500000')->call('saveServiceFee')->assertHasNoErrors();
    expect($completed->fresh()->agent_fee)->toEqual('500000.00')->and($draft->fresh()->agent_fee)->toBeNull();
});
