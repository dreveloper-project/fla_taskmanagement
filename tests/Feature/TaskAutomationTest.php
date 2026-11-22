<?php

use App\Models\BunkerService;
use App\Models\Client;
use App\Models\ShipNationalityService;
use App\Models\SpbService;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use App\Models\Vessel;
use App\Services\ShipNationalityWorkflow;
use App\Services\SpbWorkflow;
use App\Services\TaskAutomationService;
use App\Services\TaskServiceRegistry;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../SafetyCertificateFixtures.php';
require_once __DIR__.'/../BunkerFixtures.php';
require_once __DIR__.'/../SijilFixtures.php';
require_once __DIR__.'/../ShipNationalityFixtures.php';

test('workflow creation generates the mapped internal task for every service', function (string $type) {
    Storage::fake('local');
    $this->travelTo(now('Asia/Makassar')->setDate(2026, 10, 7)->setTime(16, 0));
    $this->actingAs(User::factory()->create());
    if ($type === 'spb') {
        $vessel = Vessel::factory()->for(Client::factory())->create(['vessel_type' => 'cargo']);
        $service = app(SpbWorkflow::class)->saveDraft(null, [
            'vessel_id' => $vessel->id, 'assigned_staff_id' => auth()->id(), 'priority' => 'medium', 'total_crew' => 5,
            'cargo_status' => 'no_cargo', 'port_cargo_activity_applicable' => false,
            'departure_port' => 'Benoa', 'destination' => 'Makassar', 'estimated_departure_at' => '2026-10-08T12:00',
            'estimated_port_activity_completion_at' => '2026-10-08T10:00',
            'agent_fee' => '100000', 'pnbp_labuh' => '10000', 'pnbp_mastercable' => '1000',
        ], []);
    } else {
        $service = match ($type) {
            'safety-certificates' => safetyDraft(), 'bunker' => bunkerDraft(), 'sijil' => sijilDraft(), 'ship-nationality' => nationalityDraft(),
        };
    }
    $task = $service->tasks()->sole();
    expect($task)->status->toBe('pending')->is_system_generated->toBeTrue()->vessel_id->toBe($service->vessel_id)
        ->client_id->toBe($service->client_id)->assigned_to->toBe($service->assigned_staff_id ?? $service->created_by);
    app(TaskAutomationService::class)->syncForService($service);
    app(TaskAutomationService::class)->syncForService($service);
    expect($service->tasks()->count())->toBe(1);
    expect($task->histories()->where('event', 'created')->count())->toBe(1);
})->with(array_keys(TaskServiceRegistry::TYPES));

test('sijil tasks do not inherit historical completion targets', function () {
    Storage::fake('local');
    $service = sijilDraft();
    $service->forceFill(['due_at' => now()->addDay()])->save();

    app(TaskAutomationService::class)->syncForService($service);
    expect($service->tasks()->sole()->due_at)->toBeNull();

    $service->tasks()->sole()->update(['due_at' => now()->addDays(2)]);
    app(TaskAutomationService::class)->syncForService($service);
    expect($service->tasks()->sole()->due_at)->not->toBeNull();
});

test('all service stages generate their mapped task once', function (string $class, string $status, string $title) {
    $service = $class::factory()->create(['status' => $status]);
    app(TaskAutomationService::class)->syncForService($service);
    app(TaskAutomationService::class)->syncForService($service);
    expect($service->tasks()->sole()->title)->toBe($title);
})->with(function () {
    foreach (TaskAutomationService::MAPPING as $class => $stages) {
        foreach ($stages as $status => $title) {
            yield $class.' '.$status => [$class, $status, $title];
        }
    }
});

test('nationality progression completes the previous stage and keeps follow up tasks active', function () {
    Storage::fake('local');
    $service = nationalityDraft();
    $old = $service->tasks()->sole();
    $manual = Task::factory()->for($service, 'taskable')->create();
    $service = app(ShipNationalityWorkflow::class)->ready($service);
    expect($old->fresh()->status)->toBe('completed');
    expect($manual->fresh()->status)->toBe('pending');
    $this->assertDatabaseHas('task_histories', ['task_id' => $old->id, 'event' => 'completed']);
    expect($service->tasks()->active()->count())->toBe(2);
});

test('invalid service transition cannot alter task state', function () {
    $this->actingAs(User::factory()->create());
    $service = ShipNationalityService::factory()->create(['assigned_staff_id' => auth()->id()]);
    app(TaskAutomationService::class)->syncForService($service);
    expect(fn () => app(ShipNationalityWorkflow::class)->ready($service))->toThrow(ValidationException::class);
    expect($service->tasks()->sole()->status)->toBe('pending');
    expect($service->fresh()->status)->toBe('draft');
    $this->assertDatabaseMissing('task_histories', ['event' => 'cancelled']);
});

test('recurring revision stages create distinct tasks without reopening previous tasks', function () {
    $service = ShipNationalityService::factory()->create(['status' => 'revision_required']);
    $service->histories()->create(['event' => 'Revision', 'from_status' => 'under_review', 'to_status' => 'revision_required', 'changed_by' => $service->created_by]);
    $auto = app(TaskAutomationService::class);
    $auto->syncForService($service);
    $first = $service->tasks()->sole();
    $service->update(['status' => 'under_review']);
    $service->histories()->create(['event' => 'Resubmit', 'from_status' => 'revision_required', 'to_status' => 'under_review', 'changed_by' => $service->created_by]);
    $auto->syncForService($service);
    $service->update(['status' => 'revision_required']);
    $service->histories()->create(['event' => 'Revision', 'from_status' => 'under_review', 'to_status' => 'revision_required', 'changed_by' => $service->created_by]);
    $auto->syncForService($service);
    $auto->syncForService($service);
    expect($service->tasks()->count())->toBe(3);
    expect($first->fresh()->status)->toBe('completed');
    expect($service->tasks()->active()->count())->toBe(1);
});

test('terminal nationality services finish or cancel active tasks according to the outcome', function (string $status, string $taskStatus, int $completedCount) {
    $service = ShipNationalityService::factory()->create();
    app(TaskAutomationService::class)->syncForService($service);
    Task::factory()->for($service, 'taskable')->create();
    $service->update(['status' => $status]);
    app(TaskAutomationService::class)->syncForService($service);
    expect($service->tasks()->active()->count())->toBe(0);
    expect($service->tasks()->whereNotNull('completed_at')->count())->toBe($completedCount);
    expect($service->tasks()->where('status', $taskStatus)->count())->toBe(2);
})->with([
    ['completed', 'completed', 2],
    ['cancelled', 'cancelled', 0],
    ['rejected', 'cancelled', 0],
]);

test('task failure rolls service transition back atomically', function () {
    Storage::fake('local');
    $service = nationalityDraft();
    $service->tasks()->sole()->update(['status' => 'pending', 'completed_at' => null]);
    TaskHistory::creating(function ($history): void {
        if ($history->event === 'completed') {
            throw new RuntimeException('History unavailable');
        }
    });
    try {
        expect(fn () => app(ShipNationalityWorkflow::class)->ready($service))->toThrow(RuntimeException::class, 'History unavailable');
    } finally {
        TaskHistory::flushEventListeners();
    }
    expect($service->fresh()->status)->toBe('draft');
    expect($service->tasks()->sole()->status)->toBe('pending');
    expect($service->histories()->count())->toBe(1);
});

test('backfill command syncs existing services idempotently without inventing deadlines', function () {
    $service = ShipNationalityService::factory()->create(['assigned_staff_id' => null, 'due_at' => null]);
    $this->artisan('tasks:sync-services')->assertSuccessful();
    $this->artisan('tasks:sync-services')->assertSuccessful();
    expect($service->tasks()->sole())->assigned_to->toBe($service->created_by)->due_at->toBeNull();
});

test('service assignment always propagates while preserving deadline and priority overrides', function () {
    $this->actingAs(User::factory()->create());
    $service = ShipNationalityService::factory()->create(['due_at' => null]);
    $automation = app(TaskAutomationService::class);
    $automation->syncForService($service);
    $staff = User::factory()->create();
    $service->update(['assigned_staff_id' => $staff->id, 'due_at' => '2026-10-10 01:00:00', 'priority' => 'urgent']);
    $automation->syncForService($service);
    $task = $service->tasks()->sole();
    expect($task)->assigned_to->toBe($staff->id)->priority->toBe('urgent');
    expect($task->due_at->format('Y-m-d H:i:s'))->toBe('2026-10-10 01:00:00');
    $task->update(['assigned_to' => auth()->id(), 'due_at' => '2026-10-12 01:00:00', 'priority' => 'low']);
    $service->update(['assigned_staff_id' => $service->created_by, 'due_at' => '2026-10-11 01:00:00', 'priority' => 'high']);
    $automation->syncForService($service);
    $count = $task->histories()->count();
    $automation->syncForService($service);
    expect($task->fresh())->assigned_to->toBe($service->created_by)->priority->toBe('low');
    expect($task->fresh()->due_at->format('Y-m-d H:i:s'))->toBe('2026-10-12 01:00:00');
    expect($task->histories()->count())->toBe($count);
});

test('service deadlines use spb departure and bunker schedule without arbitrary offsets', function (string $class, string $field) {
    $service = $class::factory()->create([$field => '2026-10-10 01:00:00']);
    app(TaskAutomationService::class)->syncForService($service);
    expect($service->tasks()->sole()->due_at->format('Y-m-d H:i:s'))->toBe('2026-10-10 01:00:00');
})->with([[SpbService::class, 'estimated_departure_at'], [BunkerService::class, 'scheduled_at']]);
