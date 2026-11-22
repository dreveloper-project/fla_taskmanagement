<?php

use App\Models\SpbService;
use App\Models\Task;
use App\Models\User;
use App\Models\VesselDocument;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

require_once __DIR__.'/../ReportsFixtures.php';

test('reports route and livewire actions require an authorized account', function () {
    $this->get(route('reports.index'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['role' => 'external']))->get(route('reports.index'))->assertForbidden();
    Livewire::test('pages::dashboard.reports')->assertForbidden();
});

test('admin sees real operational summary current month default and active reports link', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 08:00:00', 'UTC'));
    $this->actingAs(User::factory()->admin()->create());
    SpbService::factory()->create(['created_at' => now()]);
    Livewire::test('pages::dashboard.reports')->assertSet('applied.date_from', '2026-10-01')
        ->assertSet('applied.date_to', '2026-10-31')->assertSet('summary.total', 1)
        ->assertSee('Operational Summary')->assertSee('Completion Rate')->assertSee(route('reports.index'), false);
});

test('all report sections render real rows and empty states without modifying data', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 08:00:00', 'UTC'));
    $this->actingAs(User::factory()->admin()->create());
    $service = SpbService::factory()->create(['created_at' => now()]);
    VesselDocument::factory()->for($service->vessel)->create();
    $task = Task::factory()->create(['created_at' => now(), 'due_at' => now()->subHour()]);
    $page = Livewire::test('pages::dashboard.reports')
        ->call('selectTab', 'services')->assertSee($service->service_number)->assertSee(route('spb.show', $service), false)
        ->assertSee('Export XLSX')->assertDontSee('Export CSV')
        ->call('selectTab', 'vessels')->assertSee($service->vessel->vessel_name)->assertSee('Document Report')->assertSee('Client Report')
        ->call('selectTab', 'staff')->assertSee('Active Tasks')->assertSee($task->title);
    $page->set('filters.date_from', '2020-01-01')->set('filters.date_to', '2020-01-31')->call('applyFilters')
        ->call('selectTab', 'services')->assertSee('Tidak ada data laporan untuk periode/filter yang dipilih.');
    expect($service->fresh()->status)->toBe('draft');
    $this->assertDatabaseCount('spb_services', 1);
    $this->assertDatabaseCount('tasks', 1);
    $this->assertDatabaseCount('task_histories', 0);
});

test('apply and reset filters preserve active export scope and paginate the combined table', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 08:00:00', 'UTC'));
    $this->actingAs(User::factory()->admin()->create());
    SpbService::factory()->count(11)->create(['created_at' => now()]);
    $page = Livewire::test('pages::dashboard.reports')->call('selectTab', 'services')
        ->assertSee('Halaman 1 / 2')->call('gotoPage', 2, 'servicesPage')->assertSee('Halaman 2 / 2')
        ->set('filters.service_type', 'bunker')->assertSet('applied.service_type', '')
        ->call('applyFilters')->assertSet('applied.service_type', 'bunker')->assertSet('paginators.servicesPage', 1)
        ->assertSee('Tidak ada data laporan untuk periode/filter yang dipilih.')
        ->assertSee('service_type=bunker', false);
    $page->call('resetFilters')->assertSet('applied.service_type', '')->assertSee('Halaman 1 / 2');
});

test('selecting a service type shows its configured detail columns', function (string $type, string $label) {
    $this->actingAs(User::factory()->admin()->create());
    Livewire::test('pages::dashboard.reports')->set('filters.service_type', $type)->call('applyFilters')
        ->call('selectTab', 'services')->assertSee($label);
})->with([['spb', 'Destination'], ['safety-certificates', 'Certificate Number'], ['bunker', 'Bunker Type'], ['sijil', 'Total Crew Affected'], ['ship-nationality', 'Issued Document Number']]);

test('invalid page filters leave the previously applied report intact', function () {
    $this->actingAs(User::factory()->admin()->create());
    $page = Livewire::test('pages::dashboard.reports');
    $before = $page->get('applied');
    $page->set('filters.date_from', 'invalid')->call('applyFilters')->assertHasErrors('date_from')->assertSet('applied', $before);
});

test('report renders escaped names and document version selectors', function () {
    $this->actingAs(User::factory()->admin()->create());
    SpbService::factory()->create(['service_number' => '<script>alert(1)</script>']);
    Livewire::test('pages::dashboard.reports')->call('selectTab', 'services')
        ->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false)
        ->call('selectTab', 'vessels')->assertSee('Current & Versions')->assertSee('Expiry From');
});
