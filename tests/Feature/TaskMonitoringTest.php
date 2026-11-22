<?php

use App\Models\ShipNationalityService;
use App\Models\Task;
use App\Models\User;
use App\Models\Vessel;
use App\Services\TaskAutomationService;
use App\Services\TaskServiceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../BunkerFixtures.php';

test('task pages require authenticated staff', function () {
    $task = Task::factory()->create();
    $this->get(route('dashboard.task-monitoring'))->assertRedirect(route('login'));
    $this->get(route('tasks.show', $task))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['role' => 'client']));
    $this->get(route('dashboard.task-monitoring'))->assertForbidden();
    $this->get(route('tasks.show', $task))->assertForbidden();
});

test('admins see draft bunker tasks in the active list even when documents are complete', function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-07 16:00', 'Asia/Makassar'));
    $service = bunkerDraft();
    $task = $service->tasks()->sole();
    expect($service->status)->toBe('draft');
    expect($task->status)->toBe('pending');
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::dashboard.task-monitoring')
        ->assertSet('status', 'active')->assertSee($service->service_number)->assertSee('Perlu dilengkapi')
        ->set('status', 'active')->assertSee($service->service_number)
        ->set('status', 'completed')->assertDontSee($service->service_number)
        ->set('status', '')->assertSee($service->service_number);

    expect($task->fresh()->status)->toBe('pending');
});

test('staff still start with only their own active tasks', function () {
    $staff = User::factory()->create();
    $active = Task::factory()->create(['assigned_to' => $staff->id, 'title' => 'Tugas aktif saya']);
    Task::factory()->create(['assigned_to' => $staff->id, 'status' => 'completed', 'title' => 'Tugas selesai saya']);
    Task::factory()->create(['title' => 'Tugas petugas lain']);
    $this->actingAs($staff);

    $page = Livewire::test('pages::dashboard.task-monitoring')
        ->assertSet('status', 'active')->assertSee('Tugas aktif saya')
        ->assertDontSee('Tugas selesai saya')->assertDontSee('Tugas petugas lain');
    expect($page->get('tasks')->pluck('id')->all())->toBe([$active->id]);
});

test('completed services leave the default task list for admins and staff while retaining history', function (string $class, string $role): void {
    $this->freezeTime();
    $staff = User::factory()->create();
    $viewer = $role === 'admin' ? User::factory()->admin()->create() : $staff;
    $service = $class::factory()->create(['assigned_staff_id' => $staff->id]);
    $automation = app(TaskAutomationService::class);
    $automation->syncForService($service);
    $task = $service->tasks()->sole();
    $this->actingAs($viewer);
    $page = Livewire::test('pages::dashboard.task-monitoring')
        ->assertSee($service->service_number);

    $service->update(['status' => 'completed', 'completed_at' => now()]);
    $automation->syncForService($service);

    $page->call('$refresh')->assertSet('status', 'active')->assertDontSee($service->service_number);
    expect($page->get('summary')['active'])->toBe(0);
    expect($task->fresh())->status->toBe('completed')->progress->toBe(100)->completed_at->not->toBeNull();
    $page->set('status', 'completed')->assertSee($service->service_number)
        ->set('status', '')->assertSee($service->service_number);
    $this->assertModelExists($task);
})->with(array_values(TaskServiceRegistry::TYPES))->with(['admin', 'staff']);

test('admin task monitoring preserves the status explicitly selected in the URL', function (string $status, array $expectedTitles) {
    $admin = User::factory()->admin()->create();
    Task::factory()->create(['assigned_to' => $admin->id, 'title' => 'Tugas aktif']);
    Task::factory()->create(['assigned_to' => $admin->id, 'status' => 'completed', 'title' => 'Tugas selesai']);
    $this->actingAs($admin);

    $page = Livewire::withQueryParams(['status' => $status])->test('pages::dashboard.task-monitoring')
        ->assertSet('status', $status);

    expect($page->get('tasks')->pluck('title')->all())->toEqualCanonicalizing($expectedTitles);
})->with([
    'active' => ['active', ['Tugas aktif']],
    'completed' => ['completed', ['Tugas selesai']],
    'all statuses' => ['', ['Tugas aktif', 'Tugas selesai']],
]);

test('task monitoring summaries use database and local day boundaries', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 01:00', 'Asia/Makassar'));
    $this->actingAs(User::factory()->admin()->create());
    Task::factory()->create(['due_at' => CarbonImmutable::parse('2026-10-08 00:30', 'Asia/Makassar')->utc()]);
    Task::factory()->create(['due_at' => CarbonImmutable::parse('2026-10-08 23:59', 'Asia/Makassar')->utc()]);
    Task::factory()->create(['due_at' => CarbonImmutable::parse('2026-10-07 23:59', 'Asia/Makassar')->utc()]);
    Task::factory()->create(['status' => 'completed', 'progress' => 100, 'completed_at' => now()]);
    Task::factory()->create(['status' => 'cancelled', 'due_at' => now()->subDay()]);
    Livewire::test('pages::dashboard.task-monitoring')
        ->assertSet('summary', ['active' => 3, 'due_today' => 2, 'overdue' => 2, 'completed_today' => 1, 'unassigned' => 3])
        ->assertDontSee('Data dan lampiran merupakan contoh');
    $this->assertDatabaseCount('tasks', 5);
});

test('manual task create and detail actions persist through livewire', function () {
    $staff = User::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
    Livewire::test('pages::dashboard.task-monitoring')->call('create')->assertSet('creating', true)
        ->set('form.related_service', 'ship-nationality:'.ShipNationalityService::factory()->create(['assigned_staff_id' => $staff->id])->id)
        ->set('form.title', 'Koordinasi keberangkatan')->set('form.priority', 'high')->set('form.assigned_to', $staff->id)
        ->call('save')->assertHasNoErrors()->assertRedirect(route('tasks.show', Task::sole()));
    $task = Task::sole();
    Livewire::test('pages::dashboard.task-detail', ['task' => $task])
        ->assertSee('Koordinasi keberangkatan')
        ->call('changeStatus', 'in_progress')->assertHasErrors('status')
        ->assertDontSee('wire:model="progress"', false)->assertDontSee('Update Progress')
        ->assertSee('Lengkapi service')->assertSee('Task History')->assertDontSee('<progress', false)
        ->assertDontSee('Kirim untuk Review')->assertDontSee('Complete Task');
    expect($task->fresh())->status->toBe('pending')->progress->toBe(0);
});

test('invalid input and transitions show validation errors on page', function () {
    $this->actingAs(User::factory()->admin()->create());
    Livewire::test('pages::dashboard.task-monitoring')->call('save')->assertHasErrors('form.related_service');
    $task = Task::factory()->create();
    Livewire::test('pages::dashboard.task-detail', ['task' => $task])->call('changeStatus', 'completed')->assertHasErrors('status');
    expect($task->fresh()->status)->toBe('pending');
});

test('search filters pagination and due date isolate matching records', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00', 'Asia/Makassar'));
    $staff = User::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
    $vessel = Vessel::factory()->create();
    $service = ShipNationalityService::factory()->for($vessel)->create(['service_number' => 'SN-FILTER-01']);
    $match = Task::factory()->for($service, 'taskable')->create([
        'title' => 'Target Maritime', 'vessel_id' => $vessel->id, 'status' => 'in_progress',
        'priority' => 'urgent', 'assigned_to' => $staff->id, 'due_at' => now()->subHour(),
    ]);
    Task::factory()->count(11)->create(['title' => 'Other work']);
    $page = Livewire::test('pages::dashboard.task-monitoring')->assertSet('tasks', fn ($tasks) => $tasks->total() === 12)
        ->call('gotoPage', 2)->assertSet('paginators.page', 2)
        ->set('search', 'Target Maritime')->assertSet('paginators.page', 1)->assertSet('tasks', fn ($tasks) => $tasks->total() === 1)
        ->assertSee('Target Maritime')->assertDontSee('Other work')
        ->set('search', 'SN-FILTER-01')->assertSet('tasks', fn ($tasks) => $tasks->total() === 1)
        ->set('search', $match->number())->assertSet('tasks', fn ($tasks) => $tasks->total() === 1)
        ->set('search', '')->set('status', 'in_progress')->set('priority', 'urgent')->set('staff', (string) $staff->id)
        ->set('serviceType', 'ship-nationality')->set('vessel', (string) $vessel->id)
        ->set('dueDate', '2026-10-08')->set('overdueOnly', true)->assertSet('tasks', fn ($tasks) => $tasks->total() === 1);
    $page->set('dueDate', '2026-10-09')->assertSet('tasks', fn ($tasks) => $tasks->total() === 0);
    $page->set('dueDate', 'not-date')->assertSet('tasks', fn ($tasks) => $tasks->total() === 0);
});

test('sidebar badge and overview attention reflect active and overdue tasks', function () {
    $this->freezeTime();
    $this->actingAs(User::factory()->admin()->create());
    $task = Task::factory()->create(['title' => 'Overdue follow up', 'due_at' => now()->subHour()]);
    Task::factory()->create(['status' => 'cancelled']);
    $this->get(route('dashboard.task-monitoring'))->assertSee('data-testid="active-task-count"', false)
        ->assertSee('>1</span>', false);
    Livewire::test('pages::dashboard.overview')->assertSet('needAttentionCount', 1)
        ->assertSee('Overdue follow up')->assertSee(route('tasks.show', $task), false);
});

test('detail escapes user content and allows metadata edits without reopening', function () {
    $this->actingAs(User::factory()->admin()->create());
    $task = Task::factory()->create(['title' => '<script>alert(1)</script>', 'status' => 'completed', 'progress' => 100, 'completed_at' => now()]);
    Livewire::test('pages::dashboard.task-detail', ['task' => $task])
        ->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false)
        ->set('form.notes', 'Catatan setelah selesai')->call('save')->assertHasNoErrors()->assertSee('Catatan setelah selesai');
    expect($task->fresh())->status->toBe('completed')->progress->toBe(100);
});

test('loading task monitoring repeatedly never creates tasks', function () {
    $this->actingAs(User::factory()->admin()->create());
    ShipNationalityService::factory()->create();
    Livewire::test('pages::dashboard.task-monitoring')->call('$refresh')->call('$refresh');
    $this->assertDatabaseCount('tasks', 0);
});
