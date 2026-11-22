<?php

use App\Models\BunkerService;
use App\Models\Task;
use App\Models\User;
use App\Services\ServiceAssignment;
use App\Services\TaskAutomationService;
use App\Services\TaskServiceRegistry;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('each service notifies only the staff member assigned to its task', function (string $serviceClass) {
    $admin = User::factory()->admin()->create();
    $recipient = User::factory()->create();
    $otherStaff = User::factory()->create();
    $service = $serviceClass::factory()->create(['created_by' => $admin->id, 'assigned_staff_id' => $recipient->id]);

    app(TaskAutomationService::class)->syncForService($service, $admin->id);

    Livewire::actingAs($recipient)->test('dashboard.task-navigation')
        ->assertSet('unseenTaskCount', 1)->assertSee('data-testid="new-task-notification"', false)
        ->assertSee('1 baru')->assertSee('tugas baru ditugaskan kepada Anda');
    Livewire::actingAs($otherStaff)->test('dashboard.task-navigation')
        ->assertSet('unseenTaskCount', 0)->assertDontSee('data-testid="new-task-notification"', false);
    Livewire::actingAs($admin)->test('dashboard.task-navigation')
        ->assertSet('activeTaskCount', 1)->assertSet('unseenTaskCount', 0);
})->with(array_values(TaskServiceRegistry::TYPES));

test('sidebar polling detects new assignments and ignores finished or unassigned tasks', function () {
    $staff = User::factory()->create();
    $component = Livewire::actingAs($staff)->test('dashboard.task-navigation')->assertSet('unseenTaskCount', 0);
    Task::factory()->create(['assigned_to' => $staff->id]);
    Task::factory()->create(['assigned_to' => $staff->id, 'status' => 'completed']);
    Task::factory()->create(['assigned_to' => $staff->id, 'status' => 'cancelled']);
    Task::factory()->create();

    $component->call('$refresh')->assertSet('unseenTaskCount', 1)->assertSet('activeTaskCount', 1)
        ->assertSee('task-assignment-alert', false);
});

test('opening task monitoring acknowledges only the current users assignments without changing task progress or ordering', function () {
    $this->freezeTime();
    $staff = User::factory()->create();
    $otherStaff = User::factory()->create();
    $ownTask = Task::factory()->create(['assigned_to' => $staff->id]);
    $otherTask = Task::factory()->create(['assigned_to' => $otherStaff->id]);
    $updatedAt = $ownTask->updated_at;
    $this->travel(5)->minutes();

    $this->actingAs($staff)->get(route('dashboard.task-monitoring'))->assertOk()
        ->assertDontSee('data-testid="new-task-notification"', false);

    expect($ownTask->fresh())->assignment_seen_at->not->toBeNull()
        ->status->toBe('pending')->progress->toBe(0);
    expect($ownTask->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
    expect($otherTask->fresh()->assignment_seen_at)->toBeNull();
    Livewire::actingAs($staff)->test('dashboard.task-navigation')->assertSet('unseenTaskCount', 0);
    Livewire::actingAs($otherStaff)->test('dashboard.task-navigation')->assertSet('unseenTaskCount', 1);
});

test('service reassignment notifies the new recipient and repeated synchronization does not notify again', function () {
    $admin = User::factory()->admin()->create();
    $firstStaff = User::factory()->create();
    $nextStaff = User::factory()->create();
    $service = BunkerService::factory()->create(['created_by' => $admin->id, 'assigned_staff_id' => $firstStaff->id]);
    app(TaskAutomationService::class)->syncForService($service, $admin->id);
    Livewire::actingAs($firstStaff)->test('dashboard.task-navigation', ['active' => true]);

    $this->actingAs($admin);
    app(ServiceAssignment::class)->reassign('bunker', $service->id, $nextStaff->id);

    Livewire::actingAs($firstStaff)->test('dashboard.task-navigation')->assertSet('unseenTaskCount', 0);
    Livewire::actingAs($nextStaff)->test('dashboard.task-navigation')->assertSet('unseenTaskCount', 1);
    Livewire::test('dashboard.task-navigation', ['active' => true]);
    app(TaskAutomationService::class)->syncForService($service);
    Livewire::test('dashboard.task-navigation')->assertSet('unseenTaskCount', 0);
    expect($service->tasks()->count())->toBe(1);
});

test('failed transactions leave no assignment notification behind', function () {
    $staff = User::factory()->create();
    $service = BunkerService::factory()->create(['assigned_staff_id' => $staff->id]);

    try {
        DB::transaction(function () use ($service): void {
            app(TaskAutomationService::class)->syncForService($service);
            throw new RuntimeException('Rollback');
        });
    } catch (RuntimeException) {
    }

    Livewire::actingAs($staff)->test('dashboard.task-navigation')->assertSet('unseenTaskCount', 0);
    expect($service->tasks()->count())->toBe(0);
});

test('inactive users cannot poll assignment notifications', function () {
    $staff = User::factory()->create();
    $component = Livewire::actingAs($staff)->test('dashboard.task-navigation');
    $staff->forceFill(['status' => 'inactive'])->save();

    $component->call('$refresh')->assertForbidden();
});
