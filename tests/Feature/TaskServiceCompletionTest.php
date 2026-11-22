<?php

use App\Models\BunkerService;
use App\Models\Task;
use App\Models\User;
use App\Models\VesselDocument;
use App\Services\BunkerWorkflow;
use App\Services\ShipNationalityWorkflow;
use App\Services\TaskAutomationService;
use App\Services\TaskServiceRegistry;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../BunkerFixtures.php';
require_once __DIR__.'/../ShipNationalityFixtures.php';

it('shows missing service requirements and direct work links instead of progress reports', function (string $type, string $class): void {
    Storage::fake('local');
    $staff = User::factory()->create();
    $service = $class::factory()->create(['assigned_staff_id' => $staff->id]);
    app(TaskAutomationService::class)->syncForService($service);
    $task = $service->tasks()->sole();
    Livewire::actingAs($staff)->test('pages::dashboard.task-detail', ['task' => $task])
        ->assertSee('Masih perlu dilengkapi:')->assertSee('Lengkapi service')
        ->assertSee(route($type.'.edit', $service), false)
        ->assertDontSee('<progress', false)->assertDontSee('Kirim untuk Review')->assertDontSee('Complete Task')
        ->assertDontSee('wire:submit="uploadAttachment"', false)
        ->call('changeStatus', 'completed')->assertHasErrors('status');
    Livewire::test('pages::dashboard.task-monitoring')->assertSee('Kerjakan service')->assertDontSee('<progress', false);
    expect($task->fresh()->status)->toBe('pending');
})->with(function () {
    foreach (TaskServiceRegistry::TYPES as $type => $class) {
        yield $type => [$type, $class];
    }
});

it('keeps the task active when service documents are complete but the service is still a draft', function (): void {
    Storage::fake('local');
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $service = BunkerService::factory()->create(['assigned_staff_id' => $staff->id]);
    foreach (['fire_fighting', 'sopep_equipment'] as $type) {
        $document = VesselDocument::factory()->for($service->vessel)->readyEquipment($type)->create();
        Storage::disk('local')->put($document->file_path, '%PDF-1.4 equipment');
    }
    app(TaskAutomationService::class)->syncForService($service);
    $task = $service->tasks()->sole();
    app(BunkerWorkflow::class)->saveDraft($service, bunkerInput($service->vessel), [
        'application_letter' => bunkerPdf('application.pdf'), 'bunker_request' => bunkerPdf('request.pdf'),
    ]);
    expect($task->fresh())->status->toBe('pending')->completed_at->toBeNull();
    expect($service->tasks()->active()->count())->toBe(1);
    app(TaskAutomationService::class)->syncForService($service);
    expect($task->histories()->where('event', 'completed')->count())->toBe(0);
    Livewire::test('dashboard.task-navigation')->assertSet('activeTaskCount', 1)->assertSet('unseenTaskCount', 1);
});

it('updates the service task after required vessel documents are uploaded', function (): void {
    Storage::fake('local');
    $service = nationalityDraft();
    $document = $service->vessel->documents()->where('document_type', 'surat_ukur')->sole();
    Storage::disk('local')->delete($document->file_path);
    app(TaskAutomationService::class)->syncForService($service);
    $task = $service->tasks()->sole();
    expect($task->status)->toBe('pending');
    $service->vessel->saveWithDocuments([], ['surat_ukur' => ['file' => nationalityPdf('measure.pdf')]]);
    expect($task->fresh()->status)->toBe('pending');
});

it('keeps revision tasks active until the corrected service is resubmitted', function (): void {
    Storage::fake('local');
    $service = nationalityDraft();
    $service->update(['status' => 'revision_required', 'revision_notes' => 'Perbaiki nomor surat.']);
    app(TaskAutomationService::class)->syncForService($service);
    $task = $service->tasks()->active()->sole();
    Livewire::test('pages::dashboard.task-detail', ['task' => $task])->assertSee('Perbaiki nomor surat.');
    expect($task->status)->toBe('pending');
    app(ShipNationalityWorkflow::class)->apply($service, ['submitted_at' => now(config('spb.timezone'))->format('Y-m-d\TH:i')]);
    expect($task->fresh()->status)->toBe('completed');
    expect($service->tasks()->active()->count())->toBe(1);
});

it('keeps a follow up task for services awaiting submission', function (string $class): void {
    $service = $class::factory()->create(['status' => 'ready_to_apply']);
    app(TaskAutomationService::class)->syncForService($service);
    expect($service->tasks()->active()->sole()->status)->toBe('pending');
})->with(array_values(TaskServiceRegistry::TYPES));

it('requires a related service when creating a task', function (): void {
    Livewire::actingAs(User::factory()->admin()->create())->test('pages::dashboard.task-monitoring')
        ->set('form.title', 'Tanpa service')->call('save')->assertHasErrors('form.related_service')
        ->assertSee('Pilih service yang akan ditindaklanjuti.');
    expect(Task::count())->toBe(0);
});

it('reopens and notifies a completed task when required service files become unavailable', function (): void {
    Storage::fake('local');
    $service = bunkerDraft();
    $task = $service->tasks()->sole();
    $task->update(['status' => 'completed', 'progress' => 100, 'completed_at' => now()]);
    expect($task->status)->toBe('completed');
    $task->assignment_seen_at = now();
    $task->save();
    Storage::disk('local')->delete($service->latestDocument('application_letter')->file_path);
    app(TaskAutomationService::class)->syncForService($service);
    expect($task->fresh())->status->toBe('pending')->assignment_seen_at->toBeNull()->completed_at->toBeNull();
    Livewire::test('dashboard.task-navigation')->assertSet('unseenTaskCount', 1);
    expect($service->tasks()->count())->toBe(1);
});
