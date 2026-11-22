<?php

use App\Models\BunkerService;
use App\Models\SafetyCertificateService;
use App\Models\ShipNationalityService;
use App\Models\SijilService;
use App\Models\SpbService;
use App\Models\Task;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Models\VesselDocumentVersion;
use App\Services\OperationalCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

dataset('calendar service modules', [
    [SpbService::class, 'spb', 'estimated_departure_at'],
    [SafetyCertificateService::class, 'safety-certificates', 'due_at'],
    [BunkerService::class, 'bunker', 'scheduled_at'],
    [SijilService::class, 'sijil', 'due_at'],
    [ShipNationalityService::class, 'ship-nationality', 'due_at'],
]);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 09:00:00', 'Asia/Makassar'));
});

test('overview contains a localized calendar with an empty agenda', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('home'))->assertSee('Kalender operasional')->assertSee('Oktober 2026')
        ->assertSee('Hari ini')->assertSee('Belum ada agenda yang sesuai.')
        ->assertSee('Tampilkan kalender penuh')->assertSee(route('dashboard.operational-calendar'), false);
});

test('the full calendar page shows operational events without dashboard navigation', function (string $role) {
    $user = User::factory()->create(['role' => $role]);
    Task::factory()->create(['assigned_to' => $user->id, 'title' => 'Agenda kalender penuh', 'due_at' => now()->addHour()]);
    VesselDocument::factory()->create(['document_type' => 'radio_certificate', 'expired_date' => '2026-10-13']);

    $this->actingAs($user)->get(route('dashboard.operational-calendar'))
        ->assertSee('Kalender operasional')->assertSee('Oktober 2026')->assertSee('Radio Certificate')->assertDontSee('Agenda kalender penuh')
        ->assertSee('Kembali ke Overview')->assertSee(route('home').'#operational-calendar-title', false)
        ->assertDontSee('Tampilkan kalender penuh')
        ->assertDontSee('id="dashboard-sidebar"', false)->assertDontSee('aria-label="Breadcrumb"', false)
        ->assertDontSee('<header', false);
})->with(['staff', 'admin']);

test('the full calendar page redirects guests to login', function () {
    $this->get(route('dashboard.operational-calendar'))->assertRedirect(route('login'));
});

test('the full calendar page rejects users without system access', function () {
    $this->actingAs(User::factory()->create(['role' => 'external']))
        ->get(route('dashboard.operational-calendar'))->assertForbidden();
});

test('the full calendar page redirects inactive users to login', function (string $role) {
    $this->actingAs(User::factory()->inactive()->create(['role' => $role]))
        ->get(route('dashboard.operational-calendar'))->assertRedirect(route('login'))
        ->assertSessionHasErrors(['username' => 'Akun tidak aktif. Hubungi Admin.']);
})->with(['staff', 'admin']);

test('the expanded calendar keeps its full page controls after navigation and filtering', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('dashboard.operational-calendar', ['expanded' => true])
        ->assertSee('Kembali ke Overview')->assertDontSee('Tampilkan kalender penuh')
        ->call('moveMonth', 1)->assertSee('November 2026')
        ->call('setView', 'agenda')->call('toggleOverdue')
        ->assertSet('expanded', true)->assertSee('Kembali ke Overview')->assertDontSee('Tampilkan kalender penuh');
});

test('month navigation updates the calendar grid across years and leap days', function () {
    $this->actingAs(User::factory()->create());
    $calendar = Livewire::test('dashboard.operational-calendar')
        ->assertSet('selectedDate', '2026-10-13')->assertSet('month', '2026-10')
        ->call('moveMonth', 1)->assertSet('month', '2026-11')->assertSee('November 2026');
    expect($calendar->get('days')[0]['date'])->toBe('2026-10-26');
    $calendar->call('selectDate', '2026-12-31')->call('moveMonth', 1)
        ->assertSet('month', '2027-01')->assertSee('Januari 2027')
        ->call('moveMonth', -1)->assertSet('month', '2026-12')
        ->call('selectDate', '2028-02-29')->assertSet('month', '2028-02')->assertSee('Februari 2028');
    expect(collect($calendar->get('days'))->pluck('date'))->toContain('2028-02-29')->not->toContain('2028-02-30');
    $calendar->call('setView', 'agenda')->call('goToday')
        ->assertSet('month', '2026-10')->assertSet('selectedDate', '2026-10-13')->assertSet('view', 'month');
});

test('month and year picker jumps directly to the chosen period and refreshes its agenda', function () {
    $this->actingAs(User::factory()->create());
    VesselDocument::factory()->create(['document_type' => 'radio_certificate', 'expired_date' => '2028-02-29']);

    $calendar = Livewire::test('dashboard.operational-calendar')->assertSee('Pilih bulan dan tahun')
        ->assertSee('calendar-month-choice', false)->assertSee('calendar-year-choice', false)
        ->call('selectDate', '2026-01-31')->call('selectPeriod', '2', '2028')
        ->assertHasNoErrors()->assertSet('month', '2028-02')->assertSet('selectedDate', '2028-02-29')
        ->assertSee('Februari 2028')->assertSee('Radio Certificate');

    expect($calendar->get('agenda'))->toHaveCount(1);
    $calendar->call('selectPeriod', '2', '2027')->assertSet('selectedDate', '2027-02-28')
        ->call('moveMonth', 1)->assertSet('month', '2027-03')->assertSee('Maret 2027');
});

test('month and year picker rejects invalid choices without changing the displayed period', function (string $month, string $year, string $field) {
    $this->actingAs(User::factory()->create());

    Livewire::test('dashboard.operational-calendar')->call('selectPeriod', $month, $year)
        ->assertHasErrors($field)->assertSet('month', '2026-10')->assertSet('selectedDate', '2026-10-13');
})->with([
    ['0', '2026', 'month'], ['13', '2026', 'month'], ['abc', '2026', 'month'],
    ['10', '1999', 'year'], ['10', '2101', 'year'], ['10', 'abc', 'year'],
]);

test('document dates include only current vessel documents and preserve date only expiry semantics', function () {
    $this->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->create(['vessel_name' => 'KM Kalender']);
    $expired = VesselDocument::factory()->for($vessel)->create(['document_type' => 'radio_certificate', 'expired_date' => '2026-10-12']);
    $today = VesselDocument::factory()->for($vessel)->create(['document_type' => 'safety_certificate', 'expired_date' => '2026-10-13']);
    VesselDocument::factory()->for($vessel)->create(['document_type' => 'insurance', 'expired_date' => null]);
    VesselDocument::factory()->for($vessel)->create(['expired_date' => '2027-01-01']);
    VesselDocumentVersion::factory()->create(['vessel_document_id' => $today->id, 'expired_date' => '2026-10-13']);

    $calendar = Livewire::test('dashboard.operational-calendar')->assertSee('Masa berlaku berakhir hari ini')->assertSee('KM Kalender');
    $events = $calendar->get('events');
    expect($events)->toHaveCount(2);
    expect($events->firstWhere('id', 'document:'.$today->id))->toMatchArray(['date' => '2026-10-13', 'time' => null, 'overdue' => false, 'work_url' => null]);
    $calendar->call('selectDate', '2026-10-12')->assertSee('Sudah kedaluwarsa');
    expect($events->firstWhere('id', 'document:'.$expired->id)['overdue'])->toBeTrue();
});

test('service deadlines of every type are excluded from the calendar', function (string $class, string $type, string $deadline) {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $service = $class::factory()->create(['assigned_staff_id' => $owner->id, $deadline => '2026-10-13 02:30:00']);

    $calendar = Livewire::test('dashboard.operational-calendar')->call('setView', 'agenda')->assertDontSee($service->service_number);
    expect($calendar->get('events')->where('kind', 'service'))->toHaveCount(0);
})->with('calendar service modules');

test('other staff do not see service deadlines on the calendar', function (string $class, string $type, string $deadline) {
    $this->actingAs(User::factory()->create());
    $service = $class::factory()->create([$deadline => '2026-10-13 02:30:00']);

    Livewire::test('dashboard.operational-calendar')->call('setView', 'agenda')->assertDontSee($service->service_number);
    $this->get(route($type.'.edit', $service))->assertForbidden();
})->with('calendar service modules');

test('admins do not see service deadlines on the calendar', function () {
    $this->actingAs(User::factory()->admin()->create());
    $service = SafetyCertificateService::factory()->create(['status' => 'ready_to_apply', 'due_at' => '2026-10-13 02:00:00']);

    Livewire::test('dashboard.operational-calendar')->call('setView', 'agenda')->assertDontSee($service->service_number)->assertDontSee('Kerjakan service');
});

test('service deadlines remain excluded after completion and refresh', function (string $class, string $type, string $deadline) {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $service = $class::factory()->create(['assigned_staff_id' => $owner->id, $deadline => '2026-10-13 02:30:00']);
    $calendar = Livewire::test('dashboard.operational-calendar')->call('setView', 'agenda')->assertDontSee($service->service_number);
    $service->update(['status' => 'completed']);

    $calendar->call('$refresh')->assertDontSee($service->service_number);
})->with('calendar service modules');

test('task deadlines are excluded for staff and admins', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    Task::factory()->create(['title' => 'Deadline pribadi', 'assigned_to' => $owner->id, 'due_at' => '2026-10-13 03:00:00']);

    Livewire::test('dashboard.operational-calendar')->call('setView', 'agenda')->assertDontSee('Deadline pribadi');
    $this->actingAs(User::factory()->admin()->create());
    Livewire::test('dashboard.operational-calendar')->call('setView', 'agenda')->assertDontSee('Deadline pribadi');
});

test('task deadlines crossing month boundaries are excluded', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    Task::factory()->create(['assigned_to' => $owner->id, 'title' => 'Awal Oktober WITA', 'due_at' => '2026-09-30 16:30:00']);
    Task::factory()->create(['assigned_to' => $owner->id, 'title' => 'Awal November WITA', 'due_at' => '2026-10-31 16:30:00']);

    Livewire::test('dashboard.operational-calendar')->call('setView', 'agenda')->assertDontSee('Awal Oktober WITA')
        ->call('selectDate', '2026-11-01')->assertDontSee('Awal November WITA');
});

test('overdue filter shows only expired vessel documents', function () {
    $this->actingAs(User::factory()->create());
    VesselDocument::factory()->create(['expired_date' => '2026-10-12']);
    VesselDocument::factory()->create(['expired_date' => '2026-10-13']);

    $calendar = Livewire::test('dashboard.operational-calendar')->call('setView', 'agenda');
    expect($calendar->get('agenda'))->toHaveCount(2);
    $calendar->call('toggleOverdue')->assertSet('view', 'agenda');
    expect($calendar->get('agenda'))->toHaveCount(1);
});

test('reassigning a service does not add it to the calendar', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $service = SafetyCertificateService::factory()->create(['assigned_staff_id' => $owner->id, 'due_at' => '2026-10-13 02:00:00']);
    $calendar = Livewire::test('dashboard.operational-calendar')->call('setView', 'agenda')->assertDontSee($service->service_number);
    $service->update(['assigned_staff_id' => User::factory()->create()->id]);

    $calendar->call('$refresh')->assertDontSee($service->service_number);
});

test('generated tasks and related services are excluded from the calendar', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $service = SafetyCertificateService::factory()->create(['assigned_staff_id' => $owner->id, 'due_at' => '2026-10-13 02:00:00']);
    Task::factory()->create(['title' => 'Duplikasi otomatis', 'assigned_to' => $owner->id, 'taskable_type' => $service->getMorphClass(), 'taskable_id' => $service->id, 'is_system_generated' => true, 'due_at' => '2026-10-13 02:00:00']);

    $calendar = Livewire::test('dashboard.operational-calendar')->call('setView', 'agenda')->assertDontSee('Duplikasi otomatis')->assertDontSee($service->service_number);
    expect($calendar->get('events'))->toHaveCount(0);
});

test('linked tasks are excluded from the calendar', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $service = SpbService::factory()->create(['estimated_departure_at' => '2027-01-01 00:00:00']);
    Task::factory()->create(['title' => 'Task terkait service PIC lain', 'assigned_to' => $owner->id, 'taskable_type' => $service->getMorphClass(), 'taskable_id' => $service->id, 'due_at' => '2026-10-13 02:00:00']);

    Livewire::test('dashboard.operational-calendar')->call('setView', 'agenda')->assertDontSee('Task terkait service PIC lain');
});

test('busy dates show an overflow marker and let the user open every event', function () {
    $this->actingAs(User::factory()->create());
    foreach (['radio_certificate', 'safety_certificate', 'insurance', 'load_line'] as $type) {
        VesselDocument::factory()->create(['document_type' => $type, 'expired_date' => '2026-10-20']);
    }

    $calendar = Livewire::test('dashboard.operational-calendar')->assertSee('+2 lainnya')->call('selectDate', '2026-10-20');

    expect($calendar->get('agenda'))->toHaveCount(4);
    $calendar->assertSee('Radio Certificate')->assertSee('Asuransi Kapal')->assertSee('Load Line Certificate');
});

test('invalid date selections receive an Indonesian validation message', function (string $date) {
    $this->actingAs(User::factory()->create());

    Livewire::test('dashboard.operational-calendar')->call('selectDate', $date)
        ->assertHasErrors('date')->assertSet('month', '2026-10')->assertSet('selectedDate', '2026-10-13');
})->with(['not-a-date', '2026-02-30', '1999-12-31', '2101-01-01']);

test('calendar rejects invalid navigation filters and views', function (string $method, mixed $value) {
    $this->actingAs(User::factory()->create());

    Livewire::test('dashboard.operational-calendar')->call($method, $value)->assertStatus(422);
})->with([['moveMonth', 100], ['setView', 'unsupported']]);

test('calendar state cannot be changed directly by the client', function (string $property, mixed $value) {
    $this->actingAs(User::factory()->create());

    expect(fn () => Livewire::test('dashboard.operational-calendar')->set($property, $value))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([['month', '0000-01'], ['selectedDate', 'invalid'], ['view', 'unsupported'], ['overdueOnly', true], ['expanded', true]]);

test('calendar actions and event queries reject users without system access', function (string $role, string $status) {
    $user = User::factory()->create(['role' => $role, 'status' => $status]);
    $this->actingAs($user);

    Livewire::test('dashboard.operational-calendar')->assertForbidden();
    expect(fn () => app(OperationalCalendar::class)->events($user,
        CarbonImmutable::parse('2026-10-01', 'Asia/Makassar'), CarbonImmutable::parse('2026-10-31', 'Asia/Makassar')))
        ->toThrow(AuthorizationException::class);
})->with([['external', 'active'], ['staff', 'inactive'], ['admin', 'inactive']]);

test('calendar navigation stops at its supported date boundaries', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('dashboard.operational-calendar')->call('selectDate', '2000-01-01')->call('moveMonth', -1)
        ->assertSet('month', '2000-01')->call('selectDate', '2100-12-31')->call('moveMonth', 1)->assertSet('month', '2100-12');
});

test('calendar escapes vessel names in document agenda links', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $vessel = Vessel::factory()->create(['vessel_name' => '<script>alert(1)</script>']);
    VesselDocument::factory()->for($vessel)->create(['expired_date' => '2026-10-13']);

    Livewire::test('dashboard.operational-calendar')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false);
});

test('calendar loads related vessels and assigned staff without lazy loading', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    VesselDocument::factory()->count(3)->create(['expired_date' => '2026-10-13']);
    BunkerService::factory()->count(3)->create(['assigned_staff_id' => $owner->id, 'scheduled_at' => '2026-10-13 02:00:00']);
    Task::factory()->count(3)->create(['assigned_to' => $owner->id, 'due_at' => '2026-10-13 02:00:00', 'vessel_id' => Vessel::factory()]);
    Model::preventLazyLoading();

    try {
        $events = app(OperationalCalendar::class)->events($owner,
            CarbonImmutable::parse('2026-10-01', 'Asia/Makassar'), CarbonImmutable::parse('2026-10-31', 'Asia/Makassar'));
        expect($events)->toHaveCount(3);
    } finally {
        Model::preventLazyLoading(false);
    }
});

test('scheduled certificate inspections are excluded from the calendar', function () {
    $this->actingAs(User::factory()->create());
    SafetyCertificateService::factory()->create(['status' => 'inspection_scheduled', 'due_at' => null, 'inspection_scheduled_at' => '2026-10-13 03:00:00']);

    Livewire::test('dashboard.operational-calendar')->call('setView', 'agenda')->assertDontSee('Pemeriksaan sertifikat keselamatan');
});

test('sailing reminders are excluded from the calendar', function (string $status) {
    $this->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->create(['operational_status' => 'sailing', 'sailed_at' => '2026-10-12 02:00:00']);
    $service = SpbService::factory()->for($vessel)->create(['status' => $status, 'sailing_result' => 'success', 'estimated_departure_at' => '2026-10-12 01:00:00', 'actual_departure_at' => '2026-10-12 02:00:00', 'expected_arrival_at' => '2026-10-13 04:00:00']);

    Livewire::test('dashboard.operational-calendar')->call('setView', 'agenda')->assertDontSee('Perkiraan tiba kapal (ETA)')->assertDontSee($service->service_number);
})->with(['sailing', 'completed']);

test('inspection and ETA reminders are excluded for other staff', function () {
    $this->actingAs(User::factory()->create());
    SafetyCertificateService::factory()->create(['status' => 'inspection_scheduled', 'inspection_scheduled_at' => '2026-10-13 03:00:00']);
    $vessel = Vessel::factory()->create(['operational_status' => 'sailing', 'sailed_at' => '2026-10-12 02:00:00']);
    SpbService::factory()->for($vessel)->create(['status' => 'sailing', 'actual_departure_at' => '2026-10-12 02:00:00', 'expected_arrival_at' => '2026-10-13 04:00:00']);

    $calendar = Livewire::test('dashboard.operational-calendar')->call('setView', 'agenda')->assertDontSee('Pemeriksaan sertifikat keselamatan')->assertDontSee('Perkiraan tiba kapal (ETA)');
    expect($calendar->get('events'))->toHaveCount(0);
});
