<?php

use App\Models\ShipNationalityService;
use App\Models\SpbDocument;
use App\Models\SpbService;
use App\Models\Task;
use App\Models\User;
use App\Services\BunkerWorkflow;
use App\Services\SafetyCertificateWorkflow;
use App\Services\SijilWorkflow;
use App\Services\SpbWorkflow;
use App\Services\TaskAutomationService;
use App\Services\TaskServiceRegistry;
use App\Services\TaskWorkflow;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../BunkerFixtures.php';
require_once __DIR__.'/../SafetyCertificateFixtures.php';
require_once __DIR__.'/../SijilFixtures.php';

dataset('other active service stages', function () {
    foreach (TaskServiceRegistry::TYPES as $type => $class) {
        if ($class === ShipNationalityService::class) {
            continue;
        }
        foreach ($class::ACTIVE as $stage) {
            yield $type.' '.$stage => [$class, $stage];
        }
    }
});

test('every active service stage has one visible task after repeated synchronization', function (string $class, string $stage) {
    $staff = User::factory()->create();
    $service = $class::factory()->create(['status' => $stage, 'assigned_staff_id' => $staff->id]);
    $automation = app(TaskAutomationService::class);

    $automation->syncForService($service);
    $historyCount = $service->tasks()->sole()->histories()->count();
    $automation->syncForService($service);

    expect($service->tasks()->count())->toBe(1);
    $task = $service->tasks()->active()->sole();
    expect($task)->status->toBe('pending')->completed_at->toBeNull()->assigned_to->toBe($staff->id);
    expect($task->histories()->count())->toBe($historyCount);
    Livewire::actingAs($staff)->test('pages::dashboard.task-monitoring')->assertSee($service->service_number);
})->with('other active service stages');

test('prematurely closed tasks are restored for the current service stage', function (string $class, string $stage, string $taskStatus) {
    $service = $class::factory()->create(['status' => $stage]);
    $automation = app(TaskAutomationService::class);
    $automation->syncForService($service);
    $task = $service->tasks()->sole();
    $task->update(['status' => $taskStatus, 'progress' => 100, 'completed_at' => now(), 'assignment_seen_at' => now()]);

    $automation->syncForService($service);

    expect($task->fresh())->status->toBe('pending')->progress->toBe(0)->completed_at->toBeNull()->assignment_seen_at->toBeNull();
    expect($service->tasks()->count())->toBe(1);
})->with('other active service stages')->with(['completed', 'cancelled']);

test('terminal services close active tasks according to the outcome without recreating them', function (string $class, string $status) {
    $service = $class::factory()->create();
    $automation = app(TaskAutomationService::class);
    $automation->syncForService($service);
    $task = $service->tasks()->sole();
    $manual = Task::factory()->for($service, 'taskable')->create();
    $service->update(['status' => $status]);

    $automation->syncForService($service);
    $historyCount = $task->histories()->count();
    $automation->syncForService($service);

    expect($service->tasks()->active()->count())->toBe(0);
    expect($service->tasks()->count())->toBe(2);
    expect($task->fresh()->status)->toBe($status === 'completed' ? 'completed' : 'cancelled');
    expect($manual->fresh()->status)->toBe($status === 'completed' ? 'completed' : 'cancelled');
    expect($task->histories()->count())->toBe($historyCount);
})->with(function () {
    foreach (TaskServiceRegistry::TYPES as $type => $class) {
        if ($class === ShipNationalityService::class) {
            continue;
        }
        foreach ($class::TERMINAL as $stage) {
            yield $type.' '.$stage => [$class, $stage];
        }
    }
});

test('manual follow up tasks can be created for services already awaiting submission', function (string $type, string $class) {
    $staff = User::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
    $service = $class::factory()->create(['status' => 'ready_to_apply', 'assigned_staff_id' => $staff->id]);
    $key = $type.':'.$service->id;
    expect(app(TaskServiceRegistry::class)->options())->toHaveKey($key);

    $task = app(TaskWorkflow::class)->save(null, ['title' => 'Tindak lanjut pengajuan', 'priority' => 'medium', 'related_service' => $key]);

    expect($task)->status->toBe('pending')->assigned_to->toBe($staff->id)->taskable_id->toBe($service->id);
    $this->assertModelExists($task);
})->with(function () {
    foreach (TaskServiceRegistry::TYPES as $type => $class) {
        yield $type => [$type, $class];
    }
});

test('bunker execution retains follow up tasks until the actual activity is recorded', function () {
    Storage::fake('local');
    $this->travelTo(now('Asia/Makassar')->setDate(2026, 10, 7)->setTime(16, 0));
    $service = bunkerScheduled();
    $task = $service->tasks()->active()->sole();
    expect($task->title)->toBe('Catat realisasi pelaksanaan bunker');

    app(BunkerWorkflow::class)->complete($service, bunkerActual());

    expect($service->fresh()->status)->toBe('completed');
    expect($task->fresh())->status->toBe('completed')->completed_at->not->toBeNull();
    expect($service->tasks()->active()->count())->toBe(0);
    Storage::disk('local')->assertExists($service->latestDocument('approval_document')->file_path);
});

test('safety certificate issuance retains a task until handover is recorded', function () {
    Storage::fake('local');
    $this->travelTo(now('Asia/Makassar')->setDate(2026, 10, 7)->setTime(16, 0));
    $service = safetyIssued();
    $task = $service->tasks()->active()->sole();
    expect($task->title)->toBe('Selesaikan serah terima sertifikat keselamatan');

    app(SafetyCertificateWorkflow::class)->handover($service, ['handed_over_to' => 'Nakhoda', 'handed_over_at' => '2026-10-07T10:00']);

    expect($service->fresh()->status)->toBe('completed');
    expect($task->fresh())->status->toBe('completed')->completed_at->not->toBeNull();
    expect($service->tasks()->active()->count())->toBe(0);
    Storage::disk('local')->assertExists($service->fresh()->issuedCertificate->file_path);
});

test('approved sijil retains a task until crew changes are applied', function () {
    Storage::fake('local');
    $this->travelTo(now('Asia/Makassar')->setDate(2026, 10, 7)->setTime(16, 0));
    $service = sijilApproved();
    $task = $service->tasks()->active()->sole();
    expect($task->title)->toBe('Terapkan perubahan awak kapal');

    app(SijilWorkflow::class)->applyCrewChanges($service, ['effective_at' => '2026-10-07']);

    expect($service->fresh()->status)->toBe('completed');
    expect($task->fresh())->status->toBe('completed')->completed_at->not->toBeNull();
    expect($service->tasks()->active()->count())->toBe(0);
    Storage::disk('local')->assertExists($service->latestDocument('official_result')->file_path);
});

test('a sailing SPB retains a task until the service is finished', function () {
    Storage::fake('local');
    $this->freezeTime();
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $service = SpbService::factory()->create([
        'status' => 'sailing', 'assigned_staff_id' => $staff->id, 'actual_departure_at' => now()->subHour(),
    ]);
    $document = SpbDocument::factory()->for($service, 'spbService')->create(['document_type' => 'issued_spb']);
    Storage::disk('local')->put($document->file_path, '%PDF-1.4 issued');
    app(TaskAutomationService::class)->syncForService($service);
    $task = $service->tasks()->active()->sole();
    expect($task->title)->toBe('Catat hasil SPB');

    app(SpbWorkflow::class)->finish($service, ['sailing_result' => 'success', 'actual_departure_at' => $service->actual_departure_at->timezone(config('spb.timezone'))->format('Y-m-d\TH:i')]);

    expect($service->fresh()->status)->toBe('completed');
    expect($task->fresh())->status->toBe('completed')->completed_at->not->toBeNull();
    expect($service->tasks()->active()->count())->toBe(0);
});
