<?php

use App\Models\BunkerService;
use App\Models\Task;
use App\Models\User;
use App\Services\ServiceAssignment;
use App\Services\TaskAutomationService;
use App\Services\TaskServiceRegistry;
use Livewire\Livewire;

test('editing an automatic task transfers the service PIC for every service type', function (string $type, string $class) {
    $admin = User::factory()->admin()->create();
    $previousStaff = User::factory()->create(['name' => 'Previous PIC']);
    $newStaff = User::factory()->create(['name' => 'Replacement PIC']);
    $service = $class::factory()->create(['assigned_staff_id' => $previousStaff->id, 'created_by' => $admin->id]);
    app(TaskAutomationService::class)->syncForService($service, $admin->id);
    $task = $service->tasks()->sole();
    $this->actingAs($admin);

    Livewire::test('pages::dashboard.task-detail', ['task' => $task])
        ->set('editing', true)->assertSee('PIC Service')
        ->set('form.assigned_to', (string) $newStaff->id)->call('save')->assertHasNoErrors();

    expect($service->fresh())->assigned_staff_id->toBe($newStaff->id)->created_by->toBe($admin->id)->status->toBe('draft');
    expect($task->fresh())->assigned_to->toBe($newStaff->id)->status->toBe('pending');
    expect($service->histories()->where('event', 'service_reassigned')->sole()->metadata)
        ->toMatchArray(['from_staff' => $previousStaff->id, 'to_staff' => $newStaff->id, 'changed_by' => $admin->id]);
    $this->actingAs($previousStaff);
    $this->get(route($type.'.show', $service))->assertOk()->assertSee('Replacement PIC');
    $this->get(route('tasks.show', $task))->assertForbidden();
    Livewire::test('pages::dashboard.task-monitoring')->assertDontSee($task->title);
    $this->actingAs($newStaff);
    Livewire::test('pages::dashboard.task-monitoring')->assertSee($task->title);
})->with(collect(TaskServiceRegistry::TYPES)->map(fn ($class, $type) => [$type, $class])->values()->all());

test('service reassignment replaces old automatic task overrides and preserves manual and closed tasks', function () {
    $admin = User::factory()->admin()->create();
    $previousStaff = User::factory()->create();
    $newStaff = User::factory()->create();
    $service = BunkerService::factory()->create(['assigned_staff_id' => $previousStaff->id]);
    app(TaskAutomationService::class)->syncForService($service);
    $automaticTask = $service->tasks()->sole();
    $automaticTask->update(['assigned_to' => User::factory()->create()->id, 'priority' => 'urgent']);
    $manualTask = Task::factory()->for($service, 'taskable')->create(['assigned_to' => $previousStaff->id]);
    $closedTask = Task::factory()->for($service, 'taskable')->create([
        'assigned_to' => $previousStaff->id, 'is_system_generated' => true, 'task_key' => 'previous-stage', 'status' => 'completed',
    ]);
    $this->actingAs($admin);

    Livewire::test('pages::dashboard.staff-management')
        ->set('assignments.bunker_'.$service->id, $newStaff->id)
        ->call('reassign', 'bunker', $service->id)->assertHasNoErrors();

    expect($service->fresh()->assigned_staff_id)->toBe($newStaff->id);
    expect($automaticTask->fresh())->assigned_to->toBe($newStaff->id)->priority->toBe('urgent');
    expect($manualTask->fresh()->assigned_to)->toBe($previousStaff->id);
    expect($closedTask->fresh())->assigned_to->toBe($previousStaff->id)->status->toBe('completed');
});

test('resynchronizing the same PIC repairs an old task mismatch only once', function () {
    $admin = User::factory()->admin()->create();
    $service = BunkerService::factory()->create();
    app(TaskAutomationService::class)->syncForService($service);
    $task = $service->tasks()->sole();
    $task->update(['assigned_to' => User::factory()->create()->id]);
    $this->actingAs($admin);

    app(ServiceAssignment::class)->reassign('bunker', $service->id, $service->assigned_staff_id);
    app(ServiceAssignment::class)->reassign('bunker', $service->id, $service->assigned_staff_id);

    expect($task->fresh()->assigned_to)->toBe($service->assigned_staff_id);
    expect($task->histories()->where('event', 'reassigned')->count())->toBe(1);
    expect($service->histories()->where('event', 'service_reassigned')->count())->toBe(0);
});

test('manual task delegation does not change the service PIC', function () {
    $service = BunkerService::factory()->create();
    $task = Task::factory()->for($service, 'taskable')->create(['assigned_to' => $service->assigned_staff_id]);
    $newStaff = User::factory()->create();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::dashboard.task-detail', ['task' => $task])
        ->set('editing', true)->assertSee('Petugas Task')
        ->set('form.assigned_to', $newStaff->id)->call('save')->assertHasNoErrors();

    expect($task->fresh()->assigned_to)->toBe($newStaff->id);
    expect($service->fresh()->assigned_staff_id)->toBe($service->assigned_staff_id);
    expect($service->histories()->count())->toBe(0);
});

test('invalid replacement staff leaves the service and task unchanged', function (string $replacement) {
    $service = BunkerService::factory()->create();
    app(TaskAutomationService::class)->syncForService($service);
    $task = $service->tasks()->sole();
    $replacementId = match ($replacement) {
        'empty' => '',
        'inactive' => User::factory()->create(['status' => 'inactive'])->id,
        'admin' => User::factory()->admin()->create()->id,
    };
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::dashboard.task-detail', ['task' => $task])
        ->set('form.assigned_to', $replacementId)->call('save')->assertHasErrors('assigned_to');

    expect($task->fresh()->assigned_to)->toBe($task->assigned_to);
    expect($service->fresh()->assigned_staff_id)->toBe($service->assigned_staff_id);
    expect($service->histories()->count())->toBe(0);
    expect($task->histories()->where('event', 'task_reassigned')->count())->toBe(0);
})->with(['empty', 'inactive', 'admin']);

test('closed automatic tasks cannot transfer the service PIC', function (string $status) {
    $service = BunkerService::factory()->create();
    app(TaskAutomationService::class)->syncForService($service);
    $task = $service->tasks()->sole();
    $task->update(['status' => $status]);
    $newStaff = User::factory()->create();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::dashboard.task-detail', ['task' => $task])
        ->set('form.assigned_to', $newStaff->id)->call('save')->assertHasErrors('assigned_to')
        ->assertSee('PIC tugas otomatis yang sudah ditutup tidak dapat diganti.');

    expect($task->fresh())->assigned_to->toBe($task->assigned_to)->status->toBe($status);
    expect($service->fresh()->assigned_staff_id)->toBe($service->assigned_staff_id);
})->with(['completed', 'cancelled']);

test('staff cannot transfer a service by editing its automatic task', function () {
    $staff = User::factory()->create();
    $service = BunkerService::factory()->create(['assigned_staff_id' => $staff->id]);
    app(TaskAutomationService::class)->syncForService($service);
    $task = $service->tasks()->sole();
    $newStaff = User::factory()->create();
    $this->actingAs($staff);

    Livewire::test('pages::dashboard.task-detail', ['task' => $task])
        ->set('form.assigned_to', $newStaff->id)->call('save')->assertForbidden();

    expect($task->fresh()->assigned_to)->toBe($staff->id);
    expect($service->fresh()->assigned_staff_id)->toBe($staff->id);
});

test('an admin can edit their automatically assigned task without changing its PIC', function () {
    $admin = User::factory()->admin()->create();
    $service = BunkerService::factory()->create(['created_by' => $admin->id, 'assigned_staff_id' => $admin->id]);
    app(TaskAutomationService::class)->syncForService($service, $admin->id);
    $task = $service->tasks()->sole();

    Livewire::actingAs($admin)->test('pages::dashboard.task-detail', ['task' => $task])
        ->set('editing', true)->set('form.priority', 'high')->call('save')->assertHasNoErrors();

    expect($task->fresh())->assigned_to->toBe($admin->id)->priority->toBe('high');
    expect($service->fresh()->assigned_staff_id)->toBe($admin->id);
});
