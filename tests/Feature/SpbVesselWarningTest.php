<?php

use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Services\SpbReadinessService;
use App\Services\SpbWorkflow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
});

function warningSpb(int $passengers = 0): SpbService
{
    $vessel = Vessel::factory()->create(['vessel_type' => 'passenger']);

    $spb = app(SpbWorkflow::class)->saveDraft(null, [
        'vessel_id' => $vessel->id,
        'total_passengers' => $passengers,
        'passengers_none' => $passengers === 0,
        'cargo_status' => 'not_applicable',
        'port_cargo_activity_applicable' => false,
        'departure_port' => 'Makassar',
        'destination' => 'Surabaya',
        'estimated_departure_at' => now(config('spb.timezone'))->addDay()->format('Y-m-d\TH:i'),
    ], ['spb_file' => UploadedFile::fake()->create('spb.pdf', 10, 'application/pdf')]);
    $spb->forceFill(['status' => 'draft', 'completed_at' => null, 'archived_at' => null])->save();

    return $spb->fresh();
}

test('incomplete vessel documents and equipment only warn throughout SPB processing', function () {
    $spb = warningSpb();
    $readiness = app(SpbReadinessService::class)->check($spb);
    expect($readiness['complete'])->toBeTrue()
        ->and(collect($readiness['vessel_checks'])->where('complete', false)->count())->toBeGreaterThan(0);

    $now = now(config('spb.timezone'))->format('Y-m-d\TH:i');
    $page = Livewire::test('pages::services.spb-detail', ['spb' => $spb])
        ->assertSee('Peringatan: dokumen atau perlengkapan kapal belum lengkap. Proses SPB tetap dapat dilanjutkan.')
        ->call('ready')->assertHasNoErrors();
    expect($spb->fresh()->status)->toBe('ready_to_apply');

    $page->set('form.submitted_at', $now)->call('apply')->assertHasNoErrors();
    expect($spb->fresh()->status)->toBe('applied');

    $page->set('form.result', 'approved')
        ->set('form.spb_issued_at', $now)
        ->set('documents.issued_spb', UploadedFile::fake()->create('issued.pdf', 10, 'application/pdf'))
        ->call('recordResult')->assertHasNoErrors()
        ->assertSee('Peringatan: dokumen atau perlengkapan kapal belum lengkap. Proses SPB tetap dapat dilanjutkan.');
    expect($spb->fresh()->status)->toBe('ready_to_sail');
});

test('SPB document requirements still prevent processing when missing', function () {
    $spb = warningSpb(10);
    $readiness = app(SpbReadinessService::class)->check($spb);
    expect($readiness['complete'])->toBeFalse()
        ->and($readiness['missing'])->toContain('Passenger Manifest');

    Livewire::test('pages::services.spb-detail', ['spb' => $spb])
        ->assertSee('Peringatan: dokumen atau perlengkapan kapal belum lengkap.')
        ->call('ready')->assertHasErrors('readiness');
    expect($spb->fresh()->status)->toBe('draft');
});

test('expired vessel certificates remain visible as warnings without blocking readiness', function () {
    $spb = warningSpb();
    $document = VesselDocument::factory()->for($spb->vessel)->create([
        'document_type' => 'safety_certificate',
        'expired_date' => now()->subDay(),
    ]);
    Storage::disk('local')->put($document->file_path, '%PDF-1.4 fixture');
    $readiness = app(SpbReadinessService::class)->check($spb->fresh());
    $certificate = collect($readiness['vessel_checks'])->first(fn (array $check) => in_array('safety_certificate', $check['types'], true));
    expect($certificate['status'])->toBe('Kedaluwarsa')
        ->and($certificate['complete'])->toBeFalse()
        ->and($readiness['complete'])->toBeTrue();

    Livewire::test('pages::services.spb-detail', ['spb' => $spb])
        ->assertSee('Kedaluwarsa')->call('ready')->assertHasNoErrors();
});
