<?php

use App\Models\BunkerService;
use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use App\Services\ServiceAssignment;
use App\Services\TaskServiceRegistry;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('prevents administrators from assigning another person and keeps existing assignments', function (string $role) {
    Storage::fake('local');
    $actor = User::factory()->create(['role' => $role]);
    $this->actingAs($actor);
    $staff = User::factory()->create(['name' => 'Staf Lain']);
    $vessel = Vessel::factory()->create();
    foreach (['spb', 'bunker', 'sijil', 'safety-certificate', 'ship-nationality'] as $type) {
        Livewire::test('pages::services.'.$type.'-form')->assertDontSee('Gunakan PIC saat ini / pembuat service');
    }
    $page = Livewire::test('pages::services.bunker-form')->set('form.vessel_id', $vessel->id)
        ->set('form.assigned_staff_id', (string) $staff->id)->call('save')->assertHasErrors(['assigned_staff_id']);
    expect(BunkerService::count())->toBe(0);
    $page->set('form.assigned_staff_id', '')->call('save')->assertHasNoErrors();
    $newService = BunkerService::query()->sole();
    expect($newService->assigned_staff_id)->toBe($actor->id);
    foreach (TaskServiceRegistry::TYPES as $class) {
        $service = $class::factory()->create(['assigned_staff_id' => $staff->id]);
        expect(app(ServiceAssignment::class)->prepare($service, [])['assigned_staff_id'])->toBe($staff->id);
        expect(fn () => app(ServiceAssignment::class)->prepare($service, ['assigned_staff_id' => $actor->id]))->toThrow(ValidationException::class);
    }
    Livewire::test('pages::dashboard.staff-management')->call('viewWorkload', $staff->id)->assertDontSee('Reassign PIC');
})->with(['admin', 'superadmin']);

it('keeps an unassigned existing service unassigned when it is edited', function () {
    $this->actingAs(User::factory()->admin()->create());
    $service = SpbService::factory()->create(['assigned_staff_id' => null, 'cargo_status' => 'not_applicable', 'passengers_none' => false]);
    Livewire::test('pages::services.spb-form', ['spb' => $service])
        ->set('form.notes', 'Diperbarui')->call('save')->assertHasNoErrors();
    expect($service->fresh()->assigned_staff_id)->toBeNull();
});

it('preserves another existing PIC when an administrator edits service details', function (string $role) {
    $this->actingAs(User::factory()->create(['role' => $role]));
    $staff = User::factory()->create();
    $service = BunkerService::factory()->create(['assigned_staff_id' => $staff->id]);
    Livewire::test('pages::services.bunker-form', ['service' => $service])
        ->set('form.notes', 'Catatan baru')->call('save')->assertHasNoErrors();
    expect($service->fresh()->assigned_staff_id)->toBe($staff->id)
        ->and($service->fresh()->notes)->toBe('Catatan baru');
})->with(['admin', 'superadmin']);
