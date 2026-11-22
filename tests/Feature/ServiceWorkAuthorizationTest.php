<?php

use App\Models\BunkerService;
use App\Models\SafetyCertificateService;
use App\Models\ShipNationalityService;
use App\Models\SijilService;
use App\Models\SpbService;
use App\Models\Task;
use App\Models\User;
use App\Services\BunkerWorkflow;
use App\Services\SafetyCertificateWorkflow;
use App\Services\ShipNationalityWorkflow;
use App\Services\SijilWorkflow;
use App\Services\SpbWorkflow;
use App\Services\TaskWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

$serviceWorkModules = [
    'SPB' => [SpbService::class, SpbWorkflow::class, 'spb', 'spb', ['ready', 'apply', 'recordResult', 'depart', 'finish']],
    'Safety' => [SafetyCertificateService::class, SafetyCertificateWorkflow::class, 'safety-certificates', 'safety-certificate', ['ready', 'apply', 'recordReview', 'scheduleInspection', 'recordInspection', 'issueCertificate', 'handover']],
    'Bunker' => [BunkerService::class, BunkerWorkflow::class, 'bunker', 'bunker', ['ready', 'submit', 'recordReview', 'schedule', 'complete', 'cancel']],
    'Sijil' => [SijilService::class, SijilWorkflow::class, 'sijil', 'sijil', ['ready', 'submit', 'recordReview', 'applyCrewChanges', 'cancel']],
    'Nationality' => [ShipNationalityService::class, ShipNationalityWorkflow::class, 'ship-nationality', 'ship-nationality', ['ready', 'apply', 'recordReview', 'handover', 'cancel']],
];

dataset('service work modules', $serviceWorkModules);

test('service work permission requires an active admin or the assigned staff', function (string $class, string $workflow, string $route, string $component, array $actions) {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $service = $class::factory()->create(['assigned_staff_id' => $owner->id, 'created_by' => $other->id]);

    expect(Gate::forUser($owner)->allows('work', $service))->toBeTrue();
    expect(Gate::forUser($other)->allows('work', $service))->toBeFalse();
    expect(Gate::forUser($admin)->allows('work', $service))->toBeTrue();
    $owner->status = 'inactive';
    $admin->status = 'inactive';
    expect(Gate::forUser($owner)->allows('work', $service))->toBeFalse();
    expect(Gate::forUser($admin)->allows('work', $service))->toBeFalse();
    $service->assigned_staff_id = null;
    expect(Gate::forUser($other)->allows('work', $service))->toBeFalse();
})->with('service work modules');

test('staff cannot mutate another or unassigned service through any workflow action', function (string $class, string $workflow, string $route, string $component, array $actions, bool $unassigned = false) {
    Storage::fake('local');
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $service = $class::factory()->create(['created_by' => $staff->id, 'assigned_staff_id' => $unassigned ? null : User::factory()->create()->id]);
    $before = $service->fresh()->getAttributes();

    foreach (array_merge(['saveDraft'], $actions) as $action) {
        $arguments = match ($action) {
            'ready' => [$service],
            'saveDraft', 'recordResult', 'recordReview', 'recordInspection', 'issueCertificate', 'handover', 'finish', 'complete' => [$service, [], []],
            default => [$service, []],
        };
        expect(fn () => app($workflow)->$action(...$arguments))->toThrow(AuthorizationException::class);
    }

    expect($service->fresh()->getAttributes())->toBe($before);
    expect($service->histories()->count())->toBe(0);
    expect($service->documents()->count())->toBe(0);
    $this->assertDatabaseCount('tasks', 0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(array_merge($serviceWorkModules, [
    'SPB unassigned' => [...$serviceWorkModules['SPB'], true],
    'Nationality unassigned' => [...$serviceWorkModules['Nationality'], true],
]));

test('other staff cannot open service editing or invoke hidden detail actions', function (string $class, string $workflow, string $route, string $component, array $actions) {
    $this->actingAs(User::factory()->create());
    $service = $class::factory()->create();
    $parameter = $route === 'spb' ? 'spb' : 'service';

    $this->get(route($route.'.edit', $service))->assertForbidden();
    Livewire::test('pages::services.'.$component.'-detail', [$parameter => $service])
        ->assertDontSee('wire:click="ready"', false)
        ->assertDontSee(route($route.'.edit', $service), false)
        ->call('ready')->assertForbidden();

    expect($service->fresh()->status)->toBe('draft');
    expect($service->histories()->count())->toBe(0);
})->with('service work modules');

test('reassignment revokes already opened service forms and stale workflow models', function (string $class, string $workflow, string $route, string $component, array $actions) {
    Storage::fake('local');
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $service = $class::factory()->create(['assigned_staff_id' => $staff->id]);
    $parameter = $route === 'spb' ? 'spb' : 'service';
    $edit = Livewire::test('pages::services.'.$component.'-form', [$parameter => $service]);
    $input = $edit->get('form');
    $detail = Livewire::test('pages::services.'.$component.'-detail', [$parameter => $service]);
    $replacement = User::factory()->create();
    $class::whereKey($service->id)->update(['assigned_staff_id' => $replacement->id]);

    $edit->call('save')->assertForbidden();
    $detail->call('ready')->assertForbidden();
    expect(fn () => app($workflow)->ready($service))->toThrow(AuthorizationException::class);
    expect(fn () => app($workflow)->saveDraft($service, $input, []))->toThrow(AuthorizationException::class);

    expect($service->fresh())->assigned_staff_id->toBe($replacement->id)->status->toBe('draft');
    expect($service->histories()->count())->toBe(0);
    expect($service->documents()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with('service work modules');

test('staff cannot open another PIC departure form or use one after reassignment', function () {
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $service = SpbService::factory()->create(['assigned_staff_id' => $staff->id, 'status' => 'ready_to_sail']);
    $page = Livewire::withQueryParams(['vessel' => $service->vessel_id])->test('pages::dashboard.vessel')
        ->call('openDeparture', $service->id)->assertHasNoErrors();
    $service->update(['assigned_staff_id' => User::factory()->create()->id]);

    $page->call('confirmDeparture')->assertForbidden();
    Livewire::withQueryParams(['vessel' => $service->vessel_id])->test('pages::dashboard.vessel')
        ->assertDontSee('wire:click="openDeparture(', false)
        ->call('openDeparture', $service->id)->assertForbidden();

    expect($service->fresh())->status->toBe('ready_to_sail')->actual_departure_at->toBeNull();
});

test('all task work actions reject a former assignee without writing changes', function (string $action) {
    Storage::fake('local');
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $task = Task::factory()->create(['assigned_to' => $staff->id]);
    Task::whereKey($task->id)->update(['assigned_to' => User::factory()->create()->id]);
    $before = $task->fresh()->getAttributes();
    $value = match ($action) {
        'transition' => 'in_progress',
        'updateProgress' => 50,
        'addNotes' => 'Unauthorized note',
        'attach' => UploadedFile::fake()->createWithContent('proof.pdf', '%PDF-1.4 proof'),
    };

    expect(fn () => app(TaskWorkflow::class)->$action($task, $value))->toThrow(AuthorizationException::class);

    expect($task->fresh()->getAttributes())->toBe($before);
    expect($task->histories()->count())->toBe(0);
    expect($task->attachments()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['transition', 'updateProgress', 'addNotes', 'attach']);

test('legacy task notes follow its assignee without granting service work permission', function () {
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $service = BunkerService::factory()->create();
    $task = Task::factory()->create(['taskable_type' => $service->getMorphClass(), 'taskable_id' => $service->id, 'assigned_to' => $staff->id]);

    app(TaskWorkflow::class)->addNotes($task, 'Perlu penyesuaian PIC service.');

    expect($task->fresh()->notes)->toBe('Perlu penyesuaian PIC service.');
    expect($service->fresh()->assigned_staff_id)->toBe($service->assigned_staff_id);
    expect(Gate::forUser($staff)->allows('work', $service))->toBeFalse();
    $this->actingAs($service->assignedStaff);
    expect(fn () => app(TaskWorkflow::class)->updateProgress($task, 75))->toThrow(AuthorizationException::class);
});
