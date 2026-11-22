<?php

use App\Models\BunkerService;
use App\Models\Task;
use App\Models\User;
use App\Models\Vessel;
use App\Services\BunkerWorkflow;
use App\Services\ReportFilters;
use App\Services\ReportService;
use App\Services\ServiceArchive;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
});

test('task monitoring routes are unavailable and remaining dashboards load', function () {
    $this->get('/dashboard/task-monitoring')->assertNotFound();
    $this->get('/dashboard/task-monitoring/1')->assertNotFound();
    $this->get('/tasks/1/attachments/1/download')->assertNotFound();
    foreach (['home', 'staff.index', 'reports.index'] as $route) {
        $this->get(route($route))->assertOk()->assertDontSee('Task Monitoring')->assertDontSee('Active Tasks');
    }
    Livewire::test('pages::dashboard.reports')->call('selectTab', 'staff')->assertHasNoErrors()->assertDontSee('Overdue Tasks');
    $this->get('/dashboard/reports/export/tasks')->assertNotFound();
});

test('service creation and updates do not create tasks', function () {
    $vessel = Vessel::factory()->create();
    $workflow = app(BunkerWorkflow::class);
    $service = $workflow->saveDraft(null, ['vessel_id' => $vessel->id, 'priority' => 'medium']);
    $workflow->saveDraft($service, ['vessel_id' => $vessel->id, 'priority' => 'high']);
    expect($service->fresh()->assigned_staff_id)->toBe(auth()->id())->and(Task::count())->toBe(0);
});

test('historical task attachments remain in service archives', function () {
    $service = BunkerService::factory()->create(['status' => 'completed']);
    $task = Task::factory()->for($service, 'taskable')->create();
    $task->attachments()->create(['file_path' => 'tasks/old/document.pdf', 'original_filename' => 'document.pdf', 'uploaded_by' => auth()->id()]);
    Storage::disk('local')->put('tasks/old/document.pdf', 'historical document');
    $path = app(ServiceArchive::class)->create($service);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    expect(collect($names)->contains(fn (string $name): bool => str_contains($name, 'Task-'.$task->id.'/')))->toBeTrue();
    $zip->close();
    unlink($path);
});

test('historical overdue tasks do not affect service reports', function () {
    $staff = User::factory()->create();
    BunkerService::factory()->create(['assigned_staff_id' => $staff->id, 'status' => 'draft']);
    Task::factory()->create(['assigned_to' => $staff->id, 'due_at' => now()->subDay()]);
    $report = new ReportService(ReportFilters::validate([]));
    $summary = $report->summary();
    expect($summary['attention'])->toBe($summary['service_attention'] + $summary['expired_documents'])
        ->and($report->staff()->where('u.id', $staff->id)->first()->active)->toEqual(1)
        ->and($report->columns('staff'))->not->toHaveKey('overdue_tasks');
});
