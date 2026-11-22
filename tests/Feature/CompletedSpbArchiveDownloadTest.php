<?php

use App\Models\SpbDocument;
use App\Models\SpbService;
use App\Models\Task;
use App\Models\User;
use App\Models\Vessel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

function completedSpbFile(SpbService $service, string $content): SpbDocument
{
    $document = SpbDocument::factory()->for($service, 'spbService')->create(['document_type' => 'issued_spb']);
    Storage::disk('local')->put($document->file_path, $content);

    return $document;
}

/** @param TestResponse<BinaryFileResponse> $response
 * @return array<string, string> */
function completedSpbZipEntries(TestResponse $response): array
{
    $path = $response->baseResponse->getFile()->getPathname();
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    try {
        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entries[$zip->getNameIndex($index)] = $zip->getFromIndex($index);
        }
    } finally {
        $zip->close();
        ob_start();
        try {
            $response->baseResponse->sendContent();
        } finally {
            ob_end_clean();
        }
    }
    expect(is_file($path))->toBeFalse();

    return $entries;
}

/** @param array<string, string> $entries
 * @return list<int> */
function completedSpbZipIds(array $entries): array
{
    $ids = [];
    foreach ($entries as $name => $content) {
        if (str_ends_with($name, '/Data-SPB.json')) {
            $ids[] = json_decode($content, true, flags: JSON_THROW_ON_ERROR)['service']['id'];
        }
    }

    return $ids;
}

test('all completed SPBs and their files download beyond the first table page', function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00', 'Asia/Makassar'));
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $services = SpbService::factory()->count(12)->create(['status' => 'completed', 'assigned_staff_id' => $staff->id]);
    $draft = SpbService::factory()->create(['status' => 'draft']);
    completedSpbFile($services->first(), 'first official file');
    completedSpbFile($services->last(), 'last official file');
    completedSpbFile($draft, 'draft must be excluded');

    $page = Livewire::test('pages::services.spb')
        ->assertSee('Unduh ZIP Completed (12)')->assertSee('Semua SPB Completed');
    expect(substr_count($page->html(), 'href="'.route('spb.archives.download').'"'))->toBe(1);

    $response = $this->get(route('spb.archives.download', ['page' => 2]))
        ->assertDownload('SPB-Completed-2026-10-26-120000.zip')
        ->assertHeader('Content-Type', 'application/zip')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $entries = completedSpbZipEntries($response);

    expect(completedSpbZipIds($entries))->toEqualCanonicalizing($services->modelKeys());
    expect($entries)->toHaveCount(14);
    expect(array_values($entries))->toContain('first official file', 'last official file')->not->toContain('draft must be excluded');
    expect($services->first()->fresh()->status)->toBe('completed');
});

test('completed SPB archive keeps billing codes without obsolete financial fields', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $service = SpbService::factory()->create(['status' => 'completed']);
    $service->forceFill([
        'agent_fee' => '1000', 'pnbp_labuh' => '200', 'arrival_pnbp_labuh' => '300',
        'billing_code_labuh' => '00001234', 'arrival_billing_code_rambu' => 'ARR-001',
    ])->save();

    $entries = completedSpbZipEntries($this->get(route('spb.archives.download'))->assertOk());
    $json = collect($entries)->first(fn (string $content, string $name): bool => str_ends_with($name, '/Data-SPB.json'));
    $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR)['service'];
    expect($data)->toHaveKeys(['billing_code_labuh', 'arrival_billing_code_rambu'])
        ->and($data['billing_code_labuh'])->toBe('00001234')
        ->and($data['arrival_billing_code_rambu'])->toBe('ARR-001')
        ->and($data)->not->toHaveKeys(['agent_fee', 'billable', 'pnbp_labuh', 'pnbp_tambat', 'pnbp_mastercable', 'arrival_pnbp_labuh', 'arrival_pnbp_mastercable', 'client_id', 'priority']);
});

test('completed archives and the SPB table use the same active filters', function (string $field, string $value) {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $vessel = Vessel::factory()->create(['vessel_type' => 'cargo']);
    $matching = SpbService::factory()->for($vessel)->create(['status' => 'completed', 'priority' => 'high', 'destination' => 'Tujuan Target']);
    $other = SpbService::factory()->create(['status' => 'completed', 'priority' => 'low', 'destination' => 'Tujuan Lain']);
    $filters = [$field => $field === 'vessel' ? (string) $vessel->id : $value];
    completedSpbFile($matching, 'matching file');
    completedSpbFile($other, 'excluded file');

    $page = Livewire::withQueryParams($filters)->test('pages::services.spb')
        ->assertSee('Unduh ZIP Completed (1)')->assertSee('Completed sesuai filter aktif')
        ->assertSee(route('spb.archives.download', $filters));
    expect(substr_count($page->html(), 'href="'.route('spb.archives.download', $filters).'"'))->toBe(1);
    $page->assertDontSee('href="'.route('spb.archives.download').'"', false);
    expect($page->get('services')->pluck('id')->all())->toBe([$matching->id]);
    expect($page->get('completedArchiveCount'))->toBe(1);
    $entries = completedSpbZipEntries($this->get(route('spb.archives.download', $filters))->assertDownload());

    expect(completedSpbZipIds($entries))->toBe([$matching->id]);
    expect(array_values($entries))->toContain('matching file')->not->toContain('excluded file');
})->with([
    'search' => ['search', 'Tujuan Target'],
    'vessel' => ['vessel', ''],
    'vessel type' => ['vesselType', 'cargo'],
]);

test('completed archives use inclusive WITA departure dates', function () {
    $this->actingAs(User::factory()->create());
    $before = SpbService::factory()->create(['status' => 'completed', 'estimated_departure_at' => '2026-10-25 15:59:59']);
    $first = SpbService::factory()->create(['status' => 'completed', 'estimated_departure_at' => '2026-10-25 16:00:00']);
    $last = SpbService::factory()->create(['status' => 'completed', 'estimated_departure_at' => '2026-10-26 15:59:59']);
    $after = SpbService::factory()->create(['status' => 'completed', 'estimated_departure_at' => '2026-10-26 16:00:00']);

    $entries = completedSpbZipEntries($this->get(route('spb.archives.download', ['dateFrom' => '2026-10-26', 'dateTo' => '2026-10-26']))->assertDownload());

    expect(completedSpbZipIds($entries))->toEqualCanonicalizing([$first->id, $last->id]);
});

test('the status filter cannot export services which are not completed', function (string $status) {
    $this->actingAs(User::factory()->create());
    SpbService::factory()->create(['status' => 'completed']);
    SpbService::factory()->create(['status' => $status === 'pending' ? 'draft' : $status]);

    $this->get(route('spb.archives.download', ['status' => $status]))
        ->assertRedirect(route('spb.index', ['status' => $status]))
        ->assertSessionHas('spb_archive_error', 'Tidak ada SPB Completed yang sesuai dengan filter.');
    $page = Livewire::withQueryParams(['status' => $status])->test('pages::services.spb')
        ->assertSee('Unduh ZIP Completed (0)')
        ->assertSee('Tidak ada SPB Completed yang sesuai dengan filter.')
        ->assertSee('disabled aria-describedby="spb-export-scope"', false);
    expect($page->html())->not->toContain('href="'.route('spb.archives.download'));
})->with(['draft', 'pending', 'sailing']);

test('empty completed selections show a helpful error on the SPB page', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('spb.archives.download'))->assertRedirectToRoute('spb.index')
        ->assertSessionHas('spb_archive_error', 'Tidak ada SPB Completed yang sesuai dengan filter.');
    $this->get(route('spb.index'))->assertSee('Tidak ada SPB Completed yang sesuai dengan filter.');
});

test('bulk archives require authenticated system access', function () {
    $url = route('spb.archives.download');
    $this->get($url)->assertRedirectToRoute('login');
    $this->actingAs(User::factory()->create(['role' => 'external']))->get($url)->assertForbidden();
});

test('invalid archive filter input is rejected before downloading', function (string $field, mixed $value) {
    $this->actingAs(User::factory()->create());

    $this->from(route('spb.index'))->get(route('spb.archives.download', [$field => $value]))
        ->assertRedirectToRoute('spb.index')->assertSessionHasErrors($field);
})->with([
    'search array' => ['search', ['unexpected']],
    'unknown status' => ['status', 'unknown'],
    'invalid vessel' => ['vessel', '../private'],
    'unknown vessel type' => ['vesselType', 'unknown'],
    'invalid date' => ['dateFrom', '2026-02-30'],
]);

test('missing files cancel the whole bulk archive and preserve its filters', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create());
    $service = SpbService::factory()->create(['status' => 'completed', 'priority' => 'high', 'service_number' => 'SPB-MISSING']);
    $document = completedSpbFile($service, 'removed file');
    Storage::disk('local')->delete($document->file_path);

    $this->get(route('spb.archives.download', ['search' => $service->service_number]))
        ->assertRedirect(route('spb.index', ['search' => $service->service_number]))
        ->assertSessionHas('spb_archive_error', fn (string $message): bool => str_contains($message, 'SPB-MISSING') && str_contains($message, 'tidak ditemukan'));
});

test('similar service numbers keep their data and documents in distinct folders', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create());
    $first = SpbService::factory()->create(['status' => 'completed', 'service_number' => 'SPB/A']);
    $second = SpbService::factory()->create(['status' => 'completed', 'service_number' => 'SPB-A']);
    completedSpbFile($first, 'first file');
    completedSpbFile($second, 'second file');

    $entries = completedSpbZipEntries($this->get(route('spb.archives.download'))->assertDownload());

    expect($entries)->toHaveCount(4);
    expect(completedSpbZipIds($entries))->toBe([$first->id, $second->id]);
    expect(array_values($entries))->toContain('first file', 'second file');
    foreach (array_keys($entries) as $name) {
        expect($name)->not->toContain('..', '\\');
    }
});

test('bulk downloads keep task attachments restricted to their permitted viewers', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($staff);
    $service = SpbService::factory()->create(['status' => 'completed']);
    foreach ([$staff, $other] as $owner) {
        $task = Task::factory()->for($service, 'taskable')->create(['assigned_to' => $owner->id, 'status' => 'completed']);
        $path = 'tasks/'.$task->id.'/attachment.pdf';
        Storage::disk('local')->put($path, 'attachment-'.$owner->id);
        $task->attachments()->create(['file_path' => $path, 'original_filename' => 'attachment.pdf', 'uploaded_by' => $owner->id]);
    }

    $entries = completedSpbZipEntries($this->get(route('spb.archives.download'))->assertDownload());

    expect(array_values($entries))->toContain('attachment-'.$staff->id)->not->toContain('attachment-'.$other->id);
});
