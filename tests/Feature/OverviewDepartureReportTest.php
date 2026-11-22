<?php

use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('overview shows completed SPBs on their scheduled departure day in WITA', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00', 'Asia/Makassar'));
    $this->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->create(['vessel_name' => 'Kapal Laporan']);
    $success = SpbService::factory()->for($vessel)->create(['status' => 'completed', 'sailing_result' => 'success', 'estimated_departure_at' => '2026-11-03 16:00:00']);
    $notSailing = SpbService::factory()->for($vessel)->create(['status' => 'completed', 'sailing_result' => 'not_sailing', 'estimated_departure_at' => '2026-11-04 04:00:00']);
    $failed = SpbService::factory()->for($vessel)->create(['status' => 'completed', 'sailing_result' => 'failed', 'failure_reason' => 'Cuaca buruk', 'estimated_departure_at' => '2026-11-04 15:59:59']);
    $previous = SpbService::factory()->for($vessel)->create(['status' => 'completed', 'sailing_result' => 'success', 'estimated_departure_at' => '2026-11-03 15:59:59']);
    $next = SpbService::factory()->for($vessel)->create(['status' => 'completed', 'sailing_result' => 'success', 'estimated_departure_at' => '2026-11-04 16:00:00']);
    SpbService::factory()->for($vessel)->create(['status' => 'draft', 'estimated_departure_at' => '2026-11-04 04:00:00']);

    $page = Livewire::test('pages::dashboard.overview')
        ->assertSet('departureReportDate', '2026-11-04')
        ->assertSee('Laporan keberangkatan')->assertSee('Kapal Laporan')
        ->assertSee('Berangkat')->assertDontSee('Berhasil berlayar')->assertSee('Tidak berlayar')->assertSee('Gagal berlayar')
        ->assertSee('Cuaca buruk')
        ->assertSee(route('spb.show', $failed), false);
    expect($page->get('departureReports')->modelKeys())->toBe([$success->id, $notSailing->id, $failed->id]);

    $page->set('departureReportDate', '2026-11-05');
    expect($page->get('departureReports')->modelKeys())->toBe([$next->id]);
    $page->set('departureReportDate', '2026-11-03');
    expect($page->get('departureReports')->modelKeys())->toBe([$previous->id]);
    $page->set('departureReportDate', '')->assertSee('Tidak ada SPB selesai dengan jadwal keberangkatan pada tanggal ini.');
});

test('a newly saved SPB appears on its scheduled day and moves when the date changes', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00', 'Asia/Makassar'));
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $vessel = Vessel::factory()->create(['vessel_name' => 'Kapal Arsip']);
    Livewire::test('pages::services.spb-form')
        ->set('form.vessel_id', $vessel->id)
        ->set('form.total_passengers', 10)
        ->set('form.departure_port', 'Makassar')
        ->set('form.destination', 'Surabaya')
        ->set('form.estimated_departure_at', '2026-11-05T00:15')
        ->set('documents.spb_file', UploadedFile::fake()->create('spb.pdf', 10, 'application/pdf'))
        ->call('save')->assertHasNoErrors();

    $spb = SpbService::query()->sole();
    expect($spb->status)->toBe('completed')->and($spb->actual_departure_at)->toBeNull();
    $overview = Livewire::test('pages::dashboard.overview');
    expect($overview->get('departureReports')->modelKeys())->not->toContain($spb->id);
    $overview->set('departureReportDate', '2026-11-05')
        ->assertSee($spb->service_number)->assertSee('SPB selesai diarsipkan')->assertSee('Perkiraan 00:15 WITA')
        ->assertDontSee('Berhasil berlayar');
    expect($overview->get('departureReports')->modelKeys())->toContain($spb->id);

    Livewire::test('pages::services.spb-form', ['spb' => $spb])
        ->set('form.estimated_departure_at', '2026-11-06T09:00')->call('save')->assertHasNoErrors();
    $overview->set('departureReportDate', '2026-11-06')->assertSee($spb->service_number);
    $overview->set('departureReportDate', '2026-11-05');
    expect($overview->get('departureReports')->modelKeys())->not->toContain($spb->id);
});
