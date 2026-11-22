<?php

use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\BunkerService;
use App\Models\SafetyCertificateService;
use App\Models\ShipNationalityService;
use App\Models\SijilService;
use App\Models\SpbService;
use App\Models\Task;
use App\Models\User;
use App\Services\BunkerWorkflow;
use App\Services\SafetyCertificateWorkflow;
use App\Services\ServiceAssignment;
use App\Services\ShipNationalityWorkflow;
use App\Services\SijilWorkflow;
use App\Services\SpbWorkflow;
use App\Services\StaffManagement;
use App\Services\TaskAutomationService;
use App\Services\TaskServiceRegistry;
use App\Services\TaskWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

function roleStaffData(array $extra = []): array
{
    return array_replace(['fullname' => 'Operational Staff', 'username' => 'operational.staff', 'email' => 'staff@example.test',
        'phone' => null, 'password' => 'Password123!', 'password_confirmation' => 'Password123!'], $extra);
}

test('first registration fixes role and status and closes both registration endpoints', function () {
    $this->get(route('register'))->assertOk();
    $this->post(route('register.store'), roleStaffData(['role' => 'staff', 'status' => 'inactive']))->assertRedirect(route('home'));
    $admin = User::sole();
    expect($admin)->role->toBe('superadmin')->status->toBe('active');
    $this->assertAuthenticatedAs($admin);
    auth()->logout();
    $this->get(route('register'))->assertRedirect(route('login'));
    $this->post(route('register.store'), roleStaffData(['username' => 'second', 'email' => 'second@example.test']))->assertForbidden();
    $this->assertDatabaseCount('users', 1);
});

test('initial registration rechecks existence inside the lock after request authorization', function () {
    $request = Mockery::mock(RegisterRequest::class)->makePartial();
    $request->shouldReceive('file')->with('profile_picture')->andReturnNull();
    $request->shouldReceive('validated')->andReturnUsing(fn ($key) => roleStaffData()[$key] ?? null);
    User::factory()->admin()->create();
    expect(fn () => app(RegisteredUserController::class)->store($request))
        ->toThrow(HttpException::class);
    $this->assertDatabaseCount('users', 1);
    expect(Cache::store('database')->lock('initial-admin-registration', 120)->get(fn () => true))->toBeTrue();
});

test('registration cannot acquire an already held database bootstrap lock', function () {
    $lock = Cache::store('database')->lock('initial-admin-registration', 120);
    expect($lock->get())->toBeTrue();
    try {
        $this->withoutExceptionHandling();
        expect(fn () => $this->post(route('register.store'), roleStaffData()))->toThrow(ValidationException::class, 'Registrasi awal sedang diproses.');
        $this->assertDatabaseCount('users', 0);
    } finally {
        $lock->release();
    }
});

test('active admins and staff can log in but inactive accounts cannot', function (string $role, string $status) {
    $user = User::factory()->create(['role' => $role, 'status' => $status]);
    $response = $this->post(route('login.store'), ['username' => $user->username, 'password' => 'password']);
    if ($status === 'active') {
        $response->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
    } else {
        $response->assertSessionHasErrors(['username' => 'Akun tidak aktif. Hubungi Admin.']);
        $this->assertGuest();
    }
})->with([['superadmin', 'active'], ['admin', 'active'], ['staff', 'active'], ['finance', 'active'], ['admin', 'inactive'], ['staff', 'inactive'], ['finance', 'inactive']]);

test('inactive sessions are invalidated on the next request', function () {
    $staff = User::factory()->create();
    $this->actingAs($staff)->withSession(['private' => 'secret']);
    $staff->status = 'inactive';
    $staff->save();
    $this->get(route('home'))->assertRedirect(route('login'))->assertSessionMissing('private')->assertSessionHasErrors('username');
    $this->assertGuest();
});

test('staff management is admin only including direct livewire actions', function () {
    $this->actingAs(User::factory()->create())->get(route('staff.index'))->assertForbidden();
    Livewire::test('pages::dashboard.staff-management')->assertForbidden();
    expect(fn () => app(StaffManagement::class)->save(null, roleStaffData()))->toThrow(AuthorizationException::class);
    $this->actingAs(User::factory()->admin()->create())->get(route('staff.index'))->assertOk()->assertSee('Tambah Akun');
});

test('admin creates edits and deactivates staff without deleting operational history', function () {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $manager = app(StaffManagement::class);
    $staff = $manager->save(null, roleStaffData(['username' => ' OPERATIONAL.STAFF ', 'email' => 'STAFF@EXAMPLE.TEST', 'role' => 'staff', 'status' => 'inactive']), UploadedFile::fake()->image('photo.jpg'));
    expect($staff)->role->toBe('staff')->status->toBe('active')->username->toBe('operational.staff')->email->toBe('staff@example.test');
    $this->assertAuthenticatedAs($admin);
    Storage::disk('local')->assertExists($staff->profile_picture_path);
    $task = Task::factory()->create(['assigned_to' => $staff->id, 'created_by' => $staff->id]);
    $task->histories()->create(['event' => 'created', 'changed_by' => $staff->id, 'to_status' => 'pending']);
    $manager->save($staff, roleStaffData(['fullname' => 'Updated', 'status' => 'inactive', 'role' => 'staff']));
    expect($staff->fresh())->name->toBe('Updated')->status->toBe('inactive')->role->toBe('staff');
    expect($task->fresh()->assigned_to)->toBe($staff->id);
    $this->assertDatabaseCount('task_histories', 1);
    expect(DB::table('staff_histories')->pluck('event')->all())->toContain('staff_created', 'staff_updated', 'staff_deactivated');
    $manager->setStatus($staff, 'active');
    expect($staff->fresh()->isActive())->toBeTrue();
});

test('staff forms validate unique credentials and image uploads', function () {
    $this->actingAs(User::factory()->admin()->create());
    User::factory()->create(['username' => 'operational.staff']);
    expect(fn () => app(StaffManagement::class)->save(null, roleStaffData()))->toThrow(ValidationException::class);
    expect(fn () => app(StaffManagement::class)->save(null, roleStaffData(['username' => 'another.staff']), UploadedFile::fake()->create('unsafe.svg', 10, 'image/svg+xml')))->toThrow(ValidationException::class);
});

test('admin management cannot target the superadmin or mass assign privileged attributes', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $superadmin = User::factory()->create(['role' => 'superadmin']);
    expect(fn () => app(StaffManagement::class)->setStatus($superadmin, 'inactive'))->toThrow(HttpException::class);
    expect(fn () => app(StaffManagement::class)->save($superadmin, roleStaffData(['status' => 'inactive'])))->toThrow(HttpException::class);
    $admin->fill(['role' => 'staff', 'status' => 'inactive']);
    expect($admin)->role->toBe('admin')->status->toBe('active');
    $this->patch(route('account.update'), ['fullname' => $admin->name, 'username' => $admin->username, 'email' => $admin->email, 'phone' => $admin->phone, 'role' => 'staff', 'status' => 'inactive'])->assertRedirect();
    expect($admin->fresh())->role->toBe('admin')->status->toBe('active');
});

test('staff sees only assigned tasks and cannot access other task urls or actions', function () {
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $mine = Task::factory()->create(['assigned_to' => $staff->id, 'title' => 'My private task']);
    $other = Task::factory()->create(['title' => 'Other private task']);
    Livewire::test('pages::dashboard.task-monitoring')->assertSee($mine->title)->assertDontSee($other->title)
        ->set('staff', (string) $other->assigned_to)->assertDontSee($other->title);
    $this->get(route('tasks.show', $other))->assertForbidden();
    expect(fn () => app(TaskWorkflow::class)->updateProgress($other, 50))->toThrow(AuthorizationException::class);
    expect(fn () => app(TaskWorkflow::class)->save($mine, ['assigned_to' => $other->assigned_to]))->toThrow(AuthorizationException::class);
    expect(fn () => app(TaskWorkflow::class)->save(null, ['title' => 'Forged']))->toThrow(AuthorizationException::class);
    Livewire::test('pages::dashboard.task-detail', ['task' => $mine])->set('form.assigned_to', $other->assigned_to)->call('save')->assertForbidden();
});

test('task owner can work add notes and upload but cannot cancel', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $task = Task::factory()->create(['assigned_to' => $staff->id]);
    $workflow = app(TaskWorkflow::class);
    $workflow->transition($task, 'in_progress');
    $workflow->updateProgress($task, 50);
    $workflow->addNotes($task, 'Progress checked');
    $attachment = $workflow->attach($task, UploadedFile::fake()->createWithContent('result.pdf', '%PDF-1.4 result'));
    $this->get(route('tasks.attachments.download', [$task, $attachment]))->assertOk();
    expect(fn () => $workflow->transition($task, 'cancelled'))->toThrow(AuthorizationException::class);
    $workflow->transition($task, 'in_review');
    $workflow->transition($task, 'completed');
    expect($task->fresh())->status->toBe('completed')->progress->toBe(100)->notes->toBe('Progress checked');
    $this->actingAs(User::factory()->create())->get(route('tasks.attachments.download', [$task, $attachment]))->assertForbidden();
});

test('admin reassigns tasks and the dedicated history retains both staff ids', function () {
    $this->actingAs(User::factory()->admin()->create());
    $staff = User::factory()->create();
    $task = Task::factory()->create();
    $from = $task->assigned_to;
    app(TaskWorkflow::class)->save($task, ['title' => $task->title, 'priority' => $task->priority, 'assigned_to' => $staff->id]);
    $history = $task->histories()->where('event', 'task_reassigned')->sole();
    expect($history->metadata)->toMatchArray(['from_staff' => $from, 'to_staff' => $staff->id, 'changed_by' => auth()->id()]);
    expect($history->metadata)->toHaveKey('timestamp');
    Livewire::test('pages::dashboard.task-monitoring')->assertSee($task->title);
});

test('manual assignments require active staff and automatic assignments retain an active administrator', function (string $role, string $status) {
    $this->actingAs(User::factory()->admin()->create());
    $person = User::factory()->create(compact('role', 'status'));
    expect(fn () => app(TaskWorkflow::class)->save(null, ['title' => 'Assign', 'priority' => 'low', 'assigned_to' => $person->id]))->toThrow(ValidationException::class);
    $creator = User::factory()->create();
    $service = ShipNationalityService::factory()->create(['assigned_staff_id' => $person->id, 'created_by' => $creator->id]);
    app(TaskAutomationService::class)->syncForService($service);
    app(TaskAutomationService::class)->syncForService($service);
    expect($service->tasks()->sole()->assigned_to)->toBe($role === 'admin' ? $person->id : $creator->id);
    $service2 = ShipNationalityService::factory()->create(['assigned_staff_id' => $person->id, 'created_by' => $person->id]);
    app(TaskAutomationService::class)->syncForService($service2);
    expect($service2->tasks()->sole()->assigned_to)->toBe($role === 'admin' ? $person->id : null);
})->with([['staff', 'inactive'], ['admin', 'active']]);

test('staff cannot open reports or export data while administrators can', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::dashboard.reports')->assertForbidden();
    foreach (['staff', 'tasks', 'staff-performance', 'services', 'documents', 'vessels', 'clients'] as $kind) {
        $this->get(route('reports.export', ['kind' => $kind]))->assertForbidden();
    }
    $this->actingAs(User::factory()->admin()->create());
    Livewire::test('pages::dashboard.reports')->call('selectTab', 'staff')->assertSee('Staff Performance');
    $this->get(route('reports.export', ['kind' => 'staff']))->assertOk();
});

test('service reassignment is admin only preserves state and records history for all service types', function (string $type, string $class) {
    $staff = User::factory()->create();
    $other = User::factory()->create();
    $service = $class::factory()->create(['assigned_staff_id' => $staff->id]);
    $this->actingAs($staff);
    expect(fn () => app(ServiceAssignment::class)->reassign($type, $service->id, $other->id))->toThrow(AuthorizationException::class);
    expect(fn () => app(ServiceAssignment::class)->prepare($service, ['assigned_staff_id' => $other->id]))->toThrow(AuthorizationException::class);
    $this->actingAs(User::factory()->admin()->create());
    app(ServiceAssignment::class)->reassign($type, $service->id, $other->id);
    expect($service->fresh())->assigned_staff_id->toBe($other->id)->status->toBe('draft');
    expect($service->histories()->where('event', 'service_reassigned')->count())->toBe(1);
    $other->status = 'inactive';
    $other->save();
    expect(fn () => app(ServiceAssignment::class)->prepare(null, ['assigned_staff_id' => $other->id]))->toThrow(ValidationException::class);
})->with(collect(TaskServiceRegistry::TYPES)->map(fn ($class, $type) => [$type, $class])->values()->all());

test('staff management livewire form and workload are usable and preserve admin login', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Livewire::test('pages::dashboard.staff-management')->call('create')->set('form', roleStaffData())->call('save')->assertHasNoErrors()->assertSee('Operational Staff');
    $staff = User::where('role', 'staff')->sole();
    Task::factory()->create(['assigned_to' => $staff->id, 'due_at' => now()->subHour()]);
    Livewire::test('pages::dashboard.staff-management')->call('viewWorkload', $staff->id)->assertSee('Melewati tenggat')->call('changeStatus', $staff->id, 'inactive')->assertSee('1 tugas aktif');
    $this->assertAuthenticatedAs($admin);
});

test('admin remains subject to readiness in every service workflow', function (string $class, string $workflow) {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $service = $class::factory()->create();
    expect(fn () => app($workflow)->ready($service))->toThrow(ValidationException::class);
    expect($service->fresh()->status)->toBe('draft');
})->with([
    [SpbService::class, SpbWorkflow::class],
    [SafetyCertificateService::class, SafetyCertificateWorkflow::class],
    [BunkerService::class, BunkerWorkflow::class],
    [SijilService::class, SijilWorkflow::class],
    [ShipNationalityService::class, ShipNationalityWorkflow::class],
]);

test('inactive staff is absent from new assignment options but historical task actor remains visible', function () {
    $this->actingAs(User::factory()->admin()->create());
    $inactive = User::factory()->inactive()->create(['name' => 'Historical Worker']);
    $active = User::factory()->create(['name' => 'Current Worker']);
    $task = Task::factory()->create(['assigned_to' => $inactive->id]);
    Livewire::test('pages::dashboard.task-detail', ['task' => $task])->assertSee('Historical Worker')
        ->assertSet('staffOptions', fn ($users) => $users->contains('id', $active->id) && ! $users->contains('id', $inactive->id));
    foreach (['spb', 'safety-certificate', 'bunker', 'sijil', 'ship-nationality'] as $form) {
        Livewire::test('pages::services.'.$form.'-form')->assertSet('staff', fn ($users) => ! $users->contains('id', $inactive->id));
    }
});

test('staff task access is revoked after administrative reassignment', function () {
    $staff = User::factory()->create();
    $other = User::factory()->create();
    $task = Task::factory()->create(['assigned_to' => $staff->id]);
    $this->actingAs($staff);
    $page = Livewire::test('pages::dashboard.task-detail', ['task' => $task]);
    $task->update(['assigned_to' => $other->id]);
    $page->call('changeStatus', 'in_progress')->assertForbidden();
    expect($task->fresh())->status->toBe('pending')->progress->toBe(0);
});

test('inactive staff cannot reuse a previously valid Livewire snapshot', function () {
    $staff = User::factory()->create();
    $task = Task::factory()->create(['assigned_to' => $staff->id]);
    $this->actingAs($staff);
    $page = Livewire::test('pages::dashboard.task-detail', ['task' => $task]);
    $staff->status = 'inactive';
    $staff->save();
    $this->withHeaders(['X-Livewire' => 'true'])->post(app('livewire')->getUpdateUri(), [
        'components' => [['snapshot' => json_encode($page->snapshot), 'updates' => [],
            'calls' => [['path' => '', 'method' => 'changeStatus', 'params' => ['in_progress']]]]],
    ])->assertRedirect(route('login'));
    expect($task->fresh())->status->toBe('pending')->progress->toBe(0);
    $this->assertGuest();
});

test('inactive credentials never dispatch a successful login event', function () {
    Event::fake([Login::class]);
    $user = User::factory()->inactive()->create();
    $this->post(route('login.store'), ['username' => $user->username, 'password' => 'password', 'remember' => true])->assertSessionHasErrors('username');
    Event::assertNotDispatched(Login::class);
    $this->assertGuest();
});

test('PIC validation rejects malformed ids before normalization', function () {
    $this->actingAs(User::factory()->admin()->create());
    $staff = User::factory()->create();
    expect(fn () => app(ServiceAssignment::class)->prepare(null, ['assigned_staff_id' => $staff->id.'invalid']))->toThrow(ValidationException::class);
});
