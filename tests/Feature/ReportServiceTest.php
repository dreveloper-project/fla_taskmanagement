<?php

use App\Models\BunkerService;
use App\Models\Client;
use App\Models\SafetyCertificateService;
use App\Models\ShipNationalityService;
use App\Models\SijilService;
use App\Models\SpbService;
use App\Models\Task;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Services\ReportFilters;
use App\Services\ReportService;
use App\Services\SijilReadinessService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../ReportsFixtures.php';

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

test('operational summary aggregates five services and excludes cancelled from completion denominator', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 08:00:00', 'UTC'));
    SpbService::factory()->create(['status' => 'completed', 'created_at' => '2026-10-01 00:00:00', 'completed_at' => '2026-10-03 00:00:00']);
    SafetyCertificateService::factory()->create(['status' => 'completed', 'created_at' => '2026-10-01 00:00:00', 'completed_at' => '2026-10-04 00:00:00']);
    BunkerService::factory()->create(['status' => 'draft', 'created_at' => '2026-10-02', 'completed_at' => '2026-10-06']);
    SijilService::factory()->create(['status' => 'revision_required', 'created_at' => '2026-10-02']);
    ShipNationalityService::factory()->create(['status' => 'cancelled', 'created_at' => '2026-10-02']);
    $report = reports();
    $summary = $report->summary();
    expect($summary)->toMatchArray(['total' => 5, 'completed' => 2, 'active' => 2, 'cancelled' => 1, 'eligible' => 4, 'completion_rate' => 50.0]);
    expect($summary['average_seconds'])->toBe(216000.0);
    expect(ReportService::duration($summary['average_seconds']))->toBe('2d 12h');
    expect($report->breakdown())->toHaveCount(5);
    expect($report->services()->count())->toBe(5);
    expect($report->distribution()->sum('total'))->toBe(5);
});

test('date filtering includes the full WITA local day and excludes adjacent instants', function () {
    foreach (['2026-09-30 15:59:59', '2026-09-30 16:00:00', '2026-10-01 15:59:59', '2026-10-01 16:00:00'] as $time) {
        SpbService::factory()->create(['created_at' => $time]);
    }
    $report = reports(['date_to' => '2026-10-01']);
    expect($report->services()->pluck('services.created_at')->all())->toBe(['2026-10-01 15:59:59', '2026-09-30 16:00:00']);
});

test('service vessel pic and status filters combine on real records', function (string $filter) {
    $staff = User::factory()->create();
    $client = Client::factory()->create();
    $vessel = Vessel::factory()->for($client)->create();
    $match = SpbService::factory()->for($vessel)->create(['client_id' => $client->id, 'assigned_staff_id' => $staff->id, 'status' => 'completed', 'created_at' => '2026-10-01', 'completed_at' => '2026-10-02']);
    BunkerService::factory()->create(['created_at' => '2026-10-01']);
    $filters = ['service_type' => 'spb', 'vessel_id' => $vessel->id, 'client_id' => $client->id, 'staff_id' => $staff->id, 'status' => 'completed'];
    $report = reports([$filter => $filters[$filter]]);
    expect($report->services()->pluck('service_number')->all())->toBe([$match->service_number]);
    expect($report->summary()['completed'])->toBe(1);
})->with(['service_type', 'vessel_id', 'staff_id', 'status']);

test('empty and cancelled only cohorts have safe rates and no fabricated average', function () {
    expect(reports()->summary())->toMatchArray(['total' => 0, 'completion_rate' => 0, 'average_seconds' => null]);
    BunkerService::factory()->create(['status' => 'cancelled', 'created_at' => '2026-10-01']);
    expect(reports()->summary())->toMatchArray(['total' => 1, 'eligible' => 0, 'completion_rate' => 0, 'average_seconds' => null]);
});

test('only completed services with valid completion timestamps contribute duration', function () {
    SpbService::factory()->create(['created_at' => '2026-10-02', 'completed_at' => '2026-10-05', 'status' => 'draft']);
    SpbService::factory()->create(['created_at' => '2026-10-02', 'completed_at' => null, 'status' => 'completed']);
    SpbService::factory()->create(['created_at' => '2026-10-02', 'completed_at' => '2026-10-01', 'status' => 'completed']);
    SpbService::factory()->create(['created_at' => '2026-10-02', 'completed_at' => '2026-10-03 04:00:00', 'status' => 'completed']);
    expect(reports()->summary()['average_seconds'])->toBe(100800.0);
});

test('monthly completion trend includes services created before the period and uses WITA months', function () {
    SpbService::factory()->create(['created_at' => '2026-09-01', 'completed_at' => '2026-09-30 16:00:00', 'status' => 'completed']);
    SpbService::factory()->create(['created_at' => '2026-10-31 16:00:00']);
    $trend = reports()->trend()->sole();
    expect($trend->month)->toBe('2026-10');
    expect((int) $trend->created)->toBe(0);
    expect((int) $trend->completed)->toBe(1);
    expect(reports()->summary()['total'])->toBe(0);
});

test('report attention uses the overview service rules including incomplete sijil readiness', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 08:00:00', 'UTC'));
    SpbService::factory()->create(['created_at' => now(), 'status' => 'revision_required']);
    $safety = SafetyCertificateService::factory()->create(['created_at' => now(), 'status' => 'draft', 'due_at' => now()->subHour()]);
    $bunker = BunkerService::factory()->create(['created_at' => now(), 'status' => 'rejected']);
    $sijil = SijilService::factory()->create(['created_at' => now(), 'status' => 'draft']);
    $nationality = ShipNationalityService::factory()->create(['created_at' => now(), 'status' => 'draft', 'due_at' => now()->subHour()]);
    expect(SafetyCertificateService::needsAttention()->whereKey($safety->id)->exists())->toBeTrue();
    expect(BunkerService::needsAttention()->whereKey($bunker->id)->exists())->toBeTrue();
    expect(app(SijilReadinessService::class)->needsAttention($sijil))->toBeTrue();
    expect(ShipNationalityService::needsAttention()->whereKey($nationality->id)->exists())->toBeTrue();
    expect(reports()->summary()['service_attention'])->toBe(5);
});

test('document classification reuses master boundaries including unknown and archived versions', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 20:00:00', 'UTC'));
    config(['vessels.expiration_warning_days' => 10]);
    $vessel = Vessel::factory()->create();
    foreach (['2026-10-06', '2026-10-07', '2026-10-17', '2026-10-18', null] as $i => $expiry) {
        $doc = VesselDocument::factory()->for($vessel)->create(['document_type' => array_keys(VesselDocument::TYPES)[$i], 'expired_date' => $expiry]);
        $record = reports()->documents()->where('documents.id', $doc->id)->first();
        expect($record->document_status)->toBe($doc->status());
    }
    $current = VesselDocument::where('document_type', 'surat_ukur')->firstOrFail();
    $current->versions()->create(['file_path' => 'private/archive.pdf', 'original_filename' => 'archive.pdf', 'expired_date' => '2026-10-01', 'status' => 'superseded', 'archived_at' => now()]);
    expect(reports()->documentSummary())->toBe(['valid' => 1, 'expiring_soon' => 2, 'expired' => 1, 'unknown' => 1]);
    expect(reports(['document_scope' => 'all'])->documents()->count())->toBe(6);
    expect(reports(['document_scope' => 'versions'])->documents()->sole()->document_status)->toBe('expired');
    expect(reports(['document_scope' => 'all'])->documentSummary()['expired'])->toBe(1);
    expect(reports(['document_type' => 'surat_ukur', 'document_status' => 'expired', 'document_scope' => 'all', 'expiry_from' => '2026-10-01', 'expiry_to' => '2026-10-01'])->documents()->count())->toBe(1);
});

test('vessel aggregates avoid counting joined documents as extra services', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 08:00:00', 'UTC'));
    $client = Client::factory()->create();
    $vessel = Vessel::factory()->for($client)->create(['operational_status' => 'berthed']);
    SpbService::factory()->for($vessel)->create(['client_id' => $client->id, 'created_at' => '2026-10-01', 'status' => 'draft']);
    BunkerService::factory()->for($vessel)->create(['client_id' => $client->id, 'created_at' => '2026-10-03', 'status' => 'completed', 'completed_at' => '2026-10-04']);
    VesselDocument::factory()->for($vessel)->create(['document_type' => 'surat_ukur', 'expired_date' => '2026-10-06']);
    VesselDocument::factory()->for($vessel)->create(['document_type' => 'pas_besar', 'expired_date' => '2026-10-08']);
    $ship = reports()->vessels()->sole();
    expect((int) $ship->total)->toBe(2);
    expect((int) $ship->active)->toBe(1);
    expect((int) $ship->expired)->toBe(1);
    expect((int) $ship->completed)->toBe(1);
    expect(reports()->vesselSummary()['berthed'])->toBe(1);
});

test('staff and task metrics use real assignments and exclude closed tasks from overdue', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 08:00:00', 'UTC'));
    $staff = User::factory()->create();
    SpbService::factory()->create(['assigned_staff_id' => $staff->id, 'created_at' => '2026-10-01', 'status' => 'completed', 'completed_at' => '2026-10-03']);
    SpbService::factory()->create(['assigned_staff_id' => $staff->id, 'created_at' => '2026-10-01', 'status' => 'revision_required']);
    Task::factory()->create(['assigned_to' => $staff->id, 'created_at' => now(), 'due_at' => now()->subHour()]);
    Task::factory()->create(['assigned_to' => $staff->id, 'created_at' => now(), 'due_at' => now()->subDay(), 'status' => 'completed', 'completed_at' => now(), 'progress' => 100]);
    Task::factory()->create(['assigned_to' => $staff->id, 'created_at' => now(), 'due_at' => now()->subDay(), 'status' => 'cancelled']);
    $row = reports()->staff()->sole();
    expect((int) $row->active)->toBe(1);
    expect((int) $row->completed)->toBe(1);
    expect((int) $row->attention)->toBe(2);
    expect((float) $row->average_seconds)->toBe(172800.0);
    expect(reports()->taskSummary())->toBe(['active' => 1, 'completed' => 1, 'overdue' => 1, 'due_today' => 1]);
    expect(reports()->overdueTasks()->count())->toBe(1);
});

test('staff performance breaks down completed services by type and respects filters', function () {
    $staff = User::factory()->create();
    $attributes = ['assigned_staff_id' => $staff->id, 'created_at' => '2026-10-05', 'status' => 'completed', 'completed_at' => '2026-10-06'];
    foreach ([SpbService::class, SafetyCertificateService::class, BunkerService::class, SijilService::class, ShipNationalityService::class] as $service) {
        $service::factory()->create($attributes);
    }
    SpbService::factory()->create(array_replace($attributes, ['status' => 'draft']));
    BunkerService::factory()->create(array_replace($attributes, ['created_at' => '2026-09-01']));

    $report = reports(['staff_id' => $staff->id]);
    $row = $report->row('staff', $report->staff()->sole());
    expect($row)->toMatchArray(['completed' => 5, 'completed_spb' => 1, 'completed_skkp' => 1, 'completed_bunkering' => 1, 'completed_sijil' => 1, 'completed_endorsement' => 1]);

    $filtered = reports(['staff_id' => $staff->id, 'service_type' => 'bunker']);
    expect($filtered->row('staff', $filtered->staff()->sole()))->toMatchArray(['completed' => 1, 'completed_spb' => 0, 'completed_bunkering' => 1]);
});

test('task service filters follow polymorphic links without changing task statuses', function () {
    $service = SpbService::factory()->create(['created_at' => '2026-10-01', 'status' => 'revision_required']);
    Task::factory()->for($service, 'taskable')->create(['created_at' => '2026-10-02']);
    Task::factory()->create(['created_at' => '2026-10-02']);
    expect(reports(['service_type' => 'spb', 'status' => 'revision_required'])->taskSummary()['active'])->toBe(1);
    expect(Task::where('status', 'pending')->count())->toBe(2);
});

test('report filters reject invalid ranges and unknown service types', function (array $invalid) {
    expect(fn () => ReportFilters::validate($invalid))->toThrow(ValidationException::class);
})->with([
    [['date_from' => '2026-10-08', 'date_to' => '2026-10-01']],
    [['service_type' => 'arbitrary_table']], [['document_scope' => 'private_files']],
    [['date_from' => 'not-date']], [['expiry_from' => '2026-10-08', 'expiry_to' => '2026-10-01']],
]);

test('task staff filtering does not require the service to have the same assigned staff', function () {
    $service = SpbService::factory()->create(['created_at' => '2026-10-01']);
    $taskStaff = User::factory()->create();
    Task::factory()->for($service, 'taskable')->create(['assigned_to' => $taskStaff->id, 'created_at' => '2026-10-02']);
    $report = reports(['service_type' => 'spb', 'staff_id' => $taskStaff->id]);
    expect($report->taskSummary()['active'])->toBe(1);
    expect($report->summary()['total'])->toBe(0);
    expect($report->staff()->sole()->id)->toBe($taskStaff->id);
});

test('SPB service report distinguishes outcomes without billing', function () {
    $completed = SpbService::factory()->create(['status' => 'completed', 'sailing_result' => 'not_sailing', 'created_at' => '2026-10-01']);
    $pending = SpbService::factory()->create(['status' => 'ready_to_sail', 'sailing_result' => 'pending', 'created_at' => '2026-10-01']);
    $report = reports(['service_type' => 'spb']);
    $rows = $report->services()->get()->keyBy('id');
    expect($report->row('services', $rows[$completed->id]))->toMatchArray(['sailing_result' => 'Not Sailing'])
        ->and($report->row('services', $rows[$pending->id]))->toMatchArray(['sailing_result' => 'Menunggu hasil'])
        ->and($report->row('services', $rows[$completed->id]))->not->toHaveKey('billable');
});

test('service reports omit priority for all services', function () {
    $sijil = SijilService::factory()->create(['priority' => 'urgent', 'created_at' => '2026-10-02']);
    $spb = SpbService::factory()->create(['priority' => 'high', 'created_at' => '2026-10-02']);
    $report = reports();
    $rows = $report->services()->get()->keyBy(fn ($row) => $row->report_type.':'.$row->id);

    expect($report->row('services', $rows['sijil:'.$sijil->id]))->not->toHaveKey('priority');
    expect($report->row('services', $rows['spb:'.$spb->id]))->not->toHaveKey('priority');
});
