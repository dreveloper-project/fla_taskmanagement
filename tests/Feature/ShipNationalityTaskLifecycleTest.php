<?php

use App\Models\ShipNationalityService;
use App\Models\SpbService;
use App\Models\Task;
use App\Models\User;
use App\Services\ShipNationalityWorkflow;
use App\Services\TaskAutomationService;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../ShipNationalityFixtures.php';

test('nationality services remain in active tasks through review and handover until completed', function (string $type) {
    Storage::fake('local');
    $this->travelTo(now('Asia/Makassar')->setDate(2026, 10, 7)->setTime(16, 0));
    $workflow = app(ShipNationalityWorkflow::class);
    $service = nationalityDraft('new_issue', $type);
    $draftTask = $service->tasks()->active()->sole();
    expect($draftTask->status)->toBe('pending');

    $service = $workflow->ready($service);
    expect($draftTask->fresh()->status)->toBe('completed');
    expect($service->tasks()->active()->sole()->title)->toBe('Ajukan dokumen kebangsaan kapal');

    $service = $workflow->apply($service, ['submitted_at' => '2026-10-06T09:00']);
    expect($service->tasks()->active()->sole()->title)->toBe('Tindak lanjuti pemeriksaan dokumen kebangsaan kapal');

    $service = $workflow->recordReview($service, ['result' => 'revision_required', 'revision_notes' => 'Perbaiki nomor surat.']);
    expect($service->tasks()->active()->sole()->title)->toBe('Lengkapi revisi dokumen kebangsaan');

    $service = $workflow->apply($service, ['submitted_at' => '2026-10-06T10:00']);
    expect($service->tasks()->active()->sole()->title)->toBe('Tindak lanjuti pemeriksaan dokumen kebangsaan kapal');

    $service = $workflow->issueDocument($service, nationalityIssueInput(), ['issued_document' => nationalityPdf()]);
    $handoverTask = $service->tasks()->active()->sole();
    expect($service->status)->toBe('ready_for_handover');
    expect($handoverTask->title)->toBe('Selesaikan serah terima dokumen kebangsaan kapal');
    Livewire::test('pages::dashboard.task-monitoring')->assertSet('status', 'active')
        ->assertSee($service->service_number)->assertSee($handoverTask->title);
    Livewire::test('pages::dashboard.task-detail', ['task' => $handoverTask])
        ->assertSee('Ready For Handover')->assertSee(route('ship-nationality.show', $service), false);

    $workflow->handover($service, ['handed_over_to' => 'Nakhoda', 'handed_over_at' => '2026-10-07T10:00']);

    expect($service->tasks()->active()->count())->toBe(0);
    expect($handoverTask->fresh())->status->toBe('completed')->completed_at->not->toBeNull()->progress->toBe(100);
    Livewire::test('pages::dashboard.task-monitoring')->assertDontSee($service->service_number);
    Storage::disk('local')->assertExists($service->fresh()->issuedDocument->file_path);
})->with(['surat_laut', 'pas_besar', 'pas_kecil']);

test('targeted synchronization restores prematurely closed nationality tasks without duplicates', function (string $stage, string $taskStatus) {
    $staff = User::factory()->create();
    $service = ShipNationalityService::factory()->create(['status' => $stage, 'assigned_staff_id' => $staff->id]);
    $task = Task::factory()->for($service, 'taskable')->create([
        'task_key' => $stage.':0', 'is_system_generated' => true, 'assigned_to' => $staff->id,
        'status' => $taskStatus, 'progress' => 100, 'completed_at' => now(), 'assignment_seen_at' => now(),
    ]);
    $otherService = SpbService::factory()->create();

    $this->artisan('tasks:sync-services', ['--type' => 'ship-nationality'])->assertSuccessful();
    $historyCount = $task->histories()->count();
    $this->artisan('tasks:sync-services', ['--type' => 'ship-nationality'])->assertSuccessful();

    expect($task->fresh())->status->toBe('pending')->completed_at->toBeNull()->assignment_seen_at->toBeNull()->progress->toBe(0);
    expect($service->tasks()->active()->sole()->id)->toBe($task->id);
    expect($task->histories()->count())->toBe($historyCount);
    expect($otherService->tasks()->count())->toBe(0);
    Livewire::actingAs($staff)->test('pages::dashboard.task-monitoring')->assertSee($service->service_number);
})->with(ShipNationalityService::ACTIVE)->with(['completed', 'cancelled']);

test('targeted synchronization recreates a missing task for each active nationality stage', function (string $stage) {
    $service = ShipNationalityService::factory()->create(['status' => $stage]);

    $this->artisan('tasks:sync-services', ['--type' => 'ship-nationality'])->assertSuccessful();
    $this->artisan('tasks:sync-services', ['--type' => 'ship-nationality'])->assertSuccessful();

    expect($service->tasks()->count())->toBe(1);
    expect($service->tasks()->active()->sole())->status->toBe('pending')->assigned_to->toBe($service->assigned_staff_id);
})->with(ShipNationalityService::ACTIVE);

test('nationality synchronization preserves progress and acknowledgement on an active task', function () {
    $this->freezeTime();
    $service = ShipNationalityService::factory()->create(['status' => 'under_review']);
    $automation = app(TaskAutomationService::class);
    $automation->syncForService($service);
    $task = $service->tasks()->sole();
    $task->assignment_seen_at = now();
    $task->update(['status' => 'in_progress', 'progress' => 50]);
    $historyCount = $task->histories()->count();

    $automation->syncForService($service);

    expect($task->fresh())->status->toBe('in_progress')->progress->toBe(50)->assignment_seen_at->not->toBeNull();
    expect($task->histories()->count())->toBe($historyCount);
});

test('synchronization rejects an unknown service type without creating tasks', function () {
    ShipNationalityService::factory()->create();

    $this->artisan('tasks:sync-services', ['--type' => 'unknown'])->assertFailed();

    $this->assertDatabaseCount('tasks', 0);
});
