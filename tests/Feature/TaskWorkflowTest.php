<?php

use App\Models\Client;
use App\Models\ShipNationalityService;
use App\Models\Task;
use App\Models\User;
use App\Models\Vessel;
use App\Services\TaskWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

test('admin creates a manual task with derived vessel client and ignores forged workflow fields', function () {
    $staff = User::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
    $vessel = Vessel::factory()->for(Client::factory())->create();
    $service = ShipNationalityService::factory()->for($vessel)->create(['client_id' => $vessel->client_id, 'assigned_staff_id' => $staff->id]);
    $task = app(TaskWorkflow::class)->save(null, [
        'title' => 'Verifikasi internal', 'priority' => 'high', 'related_service' => 'ship-nationality:'.$service->id,
        'assigned_to' => $staff->id, 'vessel_id' => null, 'client_id' => null, 'status' => 'completed', 'progress' => 100,
        'created_by' => 999, 'is_system_generated' => true, 'task_key' => 'forged',
    ]);

    expect($task)->title->toBe('Verifikasi internal')->vessel_id->toBe($vessel->id)->client_id->toBe($vessel->client_id)
        ->assigned_to->toBe($staff->id)->created_by->toBe(auth()->id())->status->toBe('pending')->progress->toBe(0)
        ->is_system_generated->toBeFalse()->task_key->toBeNull();
    expect($task->taskable->is($service))->toBeTrue();
    expect($task->histories()->pluck('event')->all())->toBe(['created', 'assigned']);
});

test('service tasks reject manual status and progress reports', function () {
    $this->freezeTime();
    $this->actingAs(User::factory()->admin()->create());
    $service = ShipNationalityService::factory()->create();
    $task = Task::factory()->for($service, 'taskable')->create();
    $workflow = app(TaskWorkflow::class);
    expect(fn () => $workflow->transition($task, 'in_progress'))->toThrow(ValidationException::class, 'Tindak lanjuti service terkait.');
    expect(fn () => $workflow->updateProgress($task, 75))->toThrow(ValidationException::class);
    expect($task->fresh())->status->toBe('pending')->progress->toBe(0)->completed_at->toBeNull();
    expect($service->fresh()->status)->toBe('draft');
    expect($task->histories()->count())->toBe(0);
});

test('invalid transitions leave task and history unchanged', function (string $from, string $to) {
    $this->actingAs(User::factory()->admin()->create());
    $task = Task::factory()->create(['status' => $from]);
    expect(fn () => app(TaskWorkflow::class)->transition($task, $to))->toThrow(ValidationException::class, 'Perubahan status tugas tidak valid.');
    expect($task->fresh()->status)->toBe($from);
    $this->assertDatabaseCount('task_histories', 0);
})->with([['pending', 'completed'], ['pending', 'in_review'], ['in_review', 'in_progress'], ['completed', 'pending'], ['cancelled', 'in_progress'], ['completed', 'cancelled']]);

test('any active task can be cancelled without counting as active', function (string $status) {
    $this->actingAs(User::factory()->admin()->create());
    $task = Task::factory()->create(['status' => $status]);
    app(TaskWorkflow::class)->transition($task, 'cancelled', 'Tidak diperlukan');
    expect(Task::active()->count())->toBe(0);
    $this->assertDatabaseHas('task_histories', ['task_id' => $task->id, 'event' => 'cancelled', 'notes' => 'Tidak diperlukan']);
})->with(Task::ACTIVE);

test('only completed tasks can reach progress one hundred and closed progress is immutable', function (string $status, int $progress) {
    $this->actingAs(User::factory()->admin()->create());
    $task = Task::factory()->create(['status' => $status, 'progress' => $status === 'completed' ? 100 : 0]);
    expect(fn () => app(TaskWorkflow::class)->updateProgress($task, $progress))->toThrow(ValidationException::class);
    expect($task->fresh()->progress)->toBe($status === 'completed' ? 100 : 0);
})->with([['pending', 100], ['in_progress', -1], ['in_review', 101], ['completed', 50], ['cancelled', 20]]);

test('assignment requires staff and related service must be active', function () {
    $this->actingAs(User::factory()->admin()->create());
    $nonStaff = User::factory()->create(['role' => 'client']);
    expect(fn () => app(TaskWorkflow::class)->save(null, ['title' => 'Task', 'priority' => 'low', 'assigned_to' => $nonStaff->id]))->toThrow(ValidationException::class);
    $service = ShipNationalityService::factory()->create(['status' => 'completed']);
    expect(fn () => app(TaskWorkflow::class)->save(null, ['title' => 'Task', 'priority' => 'low', 'related_service' => 'ship-nationality:'.$service->id]))->toThrow(ValidationException::class, 'Pilih layanan yang masih aktif.');
    $this->assertDatabaseCount('tasks', 0);
});

test('system generated relation is immutable and completed task only accepts metadata', function () {
    $this->actingAs(User::factory()->admin()->create());
    $service = ShipNationalityService::factory()->create();
    $task = Task::factory()->for($service, 'taskable')->create(['is_system_generated' => true, 'status' => 'completed', 'progress' => 100, 'completed_at' => now()]);
    $input = ['title' => 'Catatan final', 'priority' => 'medium', 'related_service' => 'ship-nationality:'.$service->id, 'notes' => 'Arsip client'];
    expect(fn () => app(TaskWorkflow::class)->save($task, array_replace($input, ['related_service' => ''])))->toThrow(ValidationException::class);
    $saved = app(TaskWorkflow::class)->save($task, $input + ['status' => 'pending', 'progress' => 0, 'completed_at' => null]);
    expect($saved)->title->toBe('Catatan final')->notes->toBe('Arsip client')->status->toBe('completed')->progress->toBe(100)->completed_at->not->toBeNull();
});

test('assignment due date priority notes and progress changes are audited', function () {
    $this->actingAs(User::factory()->admin()->create());
    $staff = User::factory()->create();
    $task = Task::factory()->create(['assigned_to' => auth()->id()]);
    $saved = app(TaskWorkflow::class)->save($task, [
        'title' => $task->title, 'priority' => 'urgent', 'assigned_to' => $staff->id,
        'due_at' => '2026-10-08T09:00', 'start_at' => '', 'notes' => 'Hubungi operator',
    ]);
    expect($saved->due_at->format('Y-m-d H:i'))->toBe('2026-10-08 01:00');
    expect($saved->histories()->pluck('event')->all())->toContain('reassigned', 'due_date_changed', 'priority_changed', 'updated');
});

test('overdue is computed and excludes completed cancelled future and missing deadlines', function () {
    $this->freezeTime();
    $overdue = Task::factory()->create(['due_at' => now()->subMinute()]);
    Task::factory()->create(['status' => 'completed', 'due_at' => now()->subDay()]);
    Task::factory()->create(['status' => 'cancelled', 'due_at' => now()->subDay()]);
    Task::factory()->create(['due_at' => now()->addMinute()]);
    Task::factory()->create();
    expect(Task::overdue()->pluck('id')->all())->toBe([$overdue->id]);
    expect($overdue->isOverdue())->toBeTrue();
    expect($overdue->fresh()->status)->toBe('pending');
});

test('workflow rejects non staff direct mutations', function () {
    $this->actingAs(User::factory()->create(['role' => 'client']));
    expect(fn () => app(TaskWorkflow::class)->save(null, ['title' => 'Unsafe', 'priority' => 'low']))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('tasks', 0);
});

test('attachments are private scoped and require staff authentication', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
    $task = Task::factory()->create();
    $file = UploadedFile::fake()->createWithContent('check.pdf', "%PDF-1.4\nexample");
    $attachment = app(TaskWorkflow::class)->attach($task, $file);
    Storage::disk('local')->assertExists($attachment->file_path);
    $url = route('tasks.attachments.download', [$task, $attachment]);
    $this->get($url)->assertDownload('check.pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
    $other = Task::factory()->create();
    $this->get(route('tasks.attachments.download', [$other, $attachment]))->assertNotFound();
    $this->actingAs(User::factory()->create(['role' => 'client']))->get($url)->assertForbidden();
    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->admin()->create());
    Storage::disk('local')->delete($attachment->file_path);
    $this->get($url)->assertNotFound();
});

test('invalid attachment extension and size are rejected', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $task = Task::factory()->create();
    expect(fn () => app(TaskWorkflow::class)->attach($task, UploadedFile::fake()->createWithContent('unsafe.html', "%PDF-1.4\nexample")))->toThrow(ValidationException::class);
    expect(fn () => app(TaskWorkflow::class)->attach($task, UploadedFile::fake()->create('large.pdf', 2049, 'application/pdf')))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('task_attachments', 0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});
