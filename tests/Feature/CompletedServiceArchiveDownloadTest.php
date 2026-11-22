<?php

use App\Models\BunkerService;
use App\Models\SafetyCertificateService;
use App\Models\ShipNationalityService;
use App\Models\SijilService;
use App\Models\SijilServiceCrew;
use App\Models\Task;
use App\Models\User;
use App\Models\VesselDocument;
use App\Services\TaskServiceRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

dataset('completed service modules', [
    'bunker' => [BunkerService::class, 'bunker', 'scheduled_at'],
    'safety' => [SafetyCertificateService::class, 'safety-certificates', 'created_at'],
    'sijil' => [SijilService::class, 'sijil', 'submitted_at'],
    'nationality' => [ShipNationalityService::class, 'ship-nationality', 'created_at'],
]);

function completedServiceDocument(Model $service, string $content): Model
{
    $relation = $service->documents();
    $class = $relation->getRelated()::class;
    $document = $class::factory()->create([$relation->getForeignKeyName() => $service->id, 'uploaded_by' => auth()->id()]);
    Storage::disk('local')->put($document->file_path, $content);

    return $document;
}

/**
 * @param  TestResponse<BinaryFileResponse>  $response
 * @return array<string, string>
 */
function completedServiceEntries(TestResponse $response): array
{
    $path = $response->baseResponse->getFile()->getPathname();
    $archive = new ZipArchive;
    try {
        expect($archive->open($path))->toBeTrue();
        $entries = [];
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $entries[$archive->getNameIndex($index)] = $archive->getFromIndex($index);
        }
    } finally {
        $archive->close();
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

/**
 * @param  array<string, string>  $entries
 * @return list<int>
 */
function completedServiceIds(array $entries): array
{
    $ids = [];
    foreach ($entries as $name => $content) {
        if (str_ends_with($name, '/Data-Layanan.json')) {
            $ids[] = json_decode($content, true, flags: JSON_THROW_ON_ERROR)['service']['id'];
        }
    }

    return $ids;
}

test('every service page exports all completed records beyond the first page with their files', function (string $class, string $type) {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $services = $class::factory()->count(12)->create(['status' => 'completed']);
    $draft = $class::factory()->create(['status' => 'draft']);
    completedServiceDocument($services->first(), 'first version');
    completedServiceDocument($services->first(), 'latest version');
    completedServiceDocument($services->last(), 'last page file');
    completedServiceDocument($draft, 'draft file');

    $page = Livewire::test('pages::services.'.$type)
        ->assertSee('Unduh ZIP Completed (12)')->assertSee('Semua layanan Completed')
        ->assertSee('data-archive-download', false);
    $url = route('service-archives.completed', ['type' => $type]);
    expect(substr_count($page->html(), 'href="'.$url.'"'))->toBe(1);
    $entries = completedServiceEntries($this->get($url)->assertDownload()->assertHeader('Content-Type', 'application/zip'));

    expect(completedServiceIds($entries))->toEqualCanonicalizing($services->modelKeys());
    expect($entries)->toHaveCount(15);
    expect(array_values($entries))->toContain('first version', 'latest version', 'last page file')->not->toContain('draft file');
    $data = json_decode(reset($entries), true, flags: JSON_THROW_ON_ERROR);
    expect($data['service_type'])->toBe($type);
})->with('completed service modules');

test('bulk service downloads and tables share search and vessel filters', function (string $class, string $type) {
    $this->actingAs(User::factory()->admin()->create());
    $matching = $class::factory()->create(['status' => 'completed', 'service_number' => 'EXPORT-TARGET', 'priority' => 'high']);
    $otherPriority = $class::factory()->for($matching->vessel)->create(['status' => 'completed', 'service_number' => 'EXPORT-TARGET-LOW', 'priority' => 'low']);
    $class::factory()->create(['status' => 'completed', 'priority' => 'high']);
    $filters = ['search' => 'EXPORT-TARGET', 'vessel' => (string) $matching->vessel_id];

    $expectedIds = [$matching->id, $otherPriority->id];
    $page = Livewire::withQueryParams($filters)->test('pages::services.'.$type)
        ->assertSee('Unduh ZIP Completed ('.count($expectedIds).')')->assertSee('Completed sesuai filter aktif');
    $url = route('service-archives.completed', ['type' => $type, ...$filters]);
    $page->assertSee($url);
    expect($page->get('services')->modelKeys())->toEqualCanonicalizing($expectedIds);
    expect($page->get('completedArchiveCount'))->toBe(count($expectedIds));
    $entries = completedServiceEntries($this->get($url)->assertDownload());

    expect(completedServiceIds($entries))->toEqualCanonicalizing($expectedIds);
})->with('completed service modules');

test('each service uses its own inclusive WITA date field when exporting', function (string $class, string $type, string $dateColumn) {
    $this->actingAs(User::factory()->create());
    $class::factory()->create(['status' => 'completed', $dateColumn => '2026-10-25 15:59:59']);
    $first = $class::factory()->create(['status' => 'completed', $dateColumn => '2026-10-25 16:00:00']);
    $last = $class::factory()->create(['status' => 'completed', $dateColumn => '2026-10-26 15:59:59']);
    $class::factory()->create(['status' => 'completed', $dateColumn => '2026-10-26 16:00:00']);
    $filters = ['dateFrom' => '2026-10-26', 'dateTo' => '2026-10-26'];

    $page = Livewire::withQueryParams($filters)->test('pages::services.'.$type);
    expect($page->get('services')->modelKeys())->toEqualCanonicalizing([$first->id, $last->id]);
    $entries = completedServiceEntries($this->get(route('service-archives.completed', ['type' => $type, ...$filters]))->assertDownload());

    expect(completedServiceIds($entries))->toEqualCanonicalizing([$first->id, $last->id]);
})->with('completed service modules');

test('active attention and draft filters do not silently export completed records outside the filter', function (string $class, string $type) {
    $this->actingAs(User::factory()->create());
    $class::factory()->create(['status' => 'completed']);
    $class::factory()->create(['status' => 'revision_required']);
    $statuses = $type === 'safety-certificates' ? ['attention', 'draft'] : ['active', 'attention', 'draft'];

    foreach ($statuses as $status) {
        $page = Livewire::withQueryParams(['status' => $status])->test('pages::services.'.$type)
            ->assertSee('Unduh ZIP Completed (0)')->assertSee('disabled aria-describedby', false);
        expect($page->get('completedArchiveCount'))->toBe(0);
        $this->get(route('service-archives.completed', ['type' => $type, 'status' => $status]))
            ->assertRedirect(route($type.'.index', ['status' => $status]))
            ->assertSessionHas('completed_archive_error', 'Tidak ada layanan Completed yang sesuai dengan filter.');
    }
})->with('completed service modules');

test('document nationality and request type filters select only matching completed services', function () {
    $this->actingAs(User::factory()->create());
    $matching = ShipNationalityService::factory()->create(['status' => 'completed', 'document_type' => 'pas_kecil', 'request_type' => 'renewal']);
    ShipNationalityService::factory()->create(['status' => 'completed', 'document_type' => 'pas_besar', 'request_type' => 'renewal']);
    ShipNationalityService::factory()->create(['status' => 'completed', 'document_type' => 'pas_kecil', 'request_type' => 'new_issue']);
    $filters = ['documentType' => 'pas_kecil', 'requestType' => 'renewal'];

    $page = Livewire::withQueryParams($filters)->test('pages::services.ship-nationality');
    expect($page->get('services')->modelKeys())->toBe([$matching->id]);
    $entries = completedServiceEntries($this->get(route('service-archives.completed', ['type' => 'ship-nationality', ...$filters]))->assertDownload());

    expect(completedServiceIds($entries))->toBe([$matching->id]);
});

test('safety certificate expiry and request type filters select matching vessels', function (string $expiry, string $date) {
    $this->travelTo('2026-10-26 12:00:00');
    $this->actingAs(User::factory()->create());
    $matching = SafetyCertificateService::factory()->create(['status' => 'completed', 'request_type' => 'renewal']);
    VesselDocument::factory()->for($matching->vessel)->create(['document_type' => 'safety_certificate', 'expired_date' => $date]);
    SafetyCertificateService::factory()->create(['status' => 'completed', 'request_type' => 'renewal']);
    SafetyCertificateService::factory()->for($matching->vessel)->create(['status' => 'completed', 'request_type' => 'new_issue']);
    $filters = ['certificateExpiry' => $expiry, 'requestType' => 'renewal'];

    $page = Livewire::withQueryParams($filters)->test('pages::services.safety-certificates');
    expect($page->get('services')->modelKeys())->toBe([$matching->id]);
    $entries = completedServiceEntries($this->get(route('service-archives.completed', ['type' => 'safety-certificates', ...$filters]))->assertDownload());

    expect(completedServiceIds($entries))->toBe([$matching->id]);
})->with(['expired' => ['expired', '2026-10-25'], 'expiring' => ['expiring_soon', '2026-10-26'], 'valid' => ['valid', '2028-10-26']]);

test('service specific search fields also constrain completed ZIPs', function (string $class, string $type, string $field) {
    $this->actingAs(User::factory()->create());
    $matching = $class::factory()->create(['status' => 'completed', $field => 'SEARCH-UNIQUE']);
    $class::factory()->create(['status' => 'completed']);

    $page = Livewire::withQueryParams(['search' => 'SEARCH-UNIQUE'])->test('pages::services.'.$type);
    expect($page->get('services')->modelKeys())->toBe([$matching->id]);
    $entries = completedServiceEntries($this->get(route('service-archives.completed', ['type' => $type, 'search' => 'SEARCH-UNIQUE']))->assertDownload());

    expect(completedServiceIds($entries))->toBe([$matching->id]);
})->with([
    'supplier' => [BunkerService::class, 'bunker', 'supplier'],
    'bunker location' => [BunkerService::class, 'bunker', 'bunker_location'],
    'bunker type' => [BunkerService::class, 'bunker', 'bunker_type'],
    'certificate number' => [SafetyCertificateService::class, 'safety-certificates', 'certificate_number'],
    'document number' => [ShipNationalityService::class, 'ship-nationality', 'issued_document_number'],
]);

test('sijil crew search also constrains completed ZIPs', function () {
    $this->actingAs(User::factory()->create());
    $matching = SijilService::factory()->create(['status' => 'completed']);
    SijilServiceCrew::factory()->for($matching, 'sijilService')->create(['full_name' => 'EXPORT CREW']);
    SijilService::factory()->create(['status' => 'completed']);

    $page = Livewire::withQueryParams(['search' => 'EXPORT CREW'])->test('pages::services.sijil');
    expect($page->get('services')->modelKeys())->toBe([$matching->id]);
    $entries = completedServiceEntries($this->get(route('service-archives.completed', ['type' => 'sijil', 'search' => 'EXPORT CREW']))->assertDownload());

    expect(completedServiceIds($entries))->toBe([$matching->id]);
});

test('every module can stream bulk ZIP preparation progress and the ZIP payload', function (string $class, string $type) {
    $this->actingAs(User::factory()->create());
    $class::factory()->create(['status' => 'completed']);

    $response = $this->postJson(route('service-archives.completed', ['type' => $type]))
        ->assertHeader('Content-Type', 'application/vnd.fla.archive-stream')->assertHeader('X-Accel-Buffering', 'no');
    $content = $response->streamedContent();

    expect($content)->toContain('"percent":0', '"percent":100', '"type":"file"', "\nPK");
})->with('completed service modules');

test('bulk ZIPs keep task attachments restricted to permitted viewers', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($staff);
    $service = BunkerService::factory()->create(['status' => 'completed']);
    foreach ([$staff, $other] as $owner) {
        $task = Task::factory()->for($service, 'taskable')->create(['assigned_to' => $owner->id, 'status' => 'completed']);
        $path = 'tasks/'.$task->id.'/file.pdf';
        Storage::disk('local')->put($path, 'attachment-'.$owner->id);
        $task->attachments()->create(['file_path' => $path, 'original_filename' => 'file.pdf', 'uploaded_by' => $owner->id]);
    }

    $entries = completedServiceEntries($this->get(route('service-archives.completed', ['type' => 'bunker']))->assertDownload());

    expect(array_values($entries))->toContain('attachment-'.$staff->id)->not->toContain('attachment-'.$other->id);
});

test('missing files cancel the complete archive and retain its filters', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create());
    $service = BunkerService::factory()->create(['status' => 'completed', 'priority' => 'high']);
    $document = completedServiceDocument($service, 'missing');
    Storage::disk('local')->delete($document->file_path);

    $this->get(route('service-archives.completed', ['type' => 'bunker', 'search' => $service->service_number]))
        ->assertRedirect(route('bunker.index', ['search' => $service->service_number]))
        ->assertSessionHas('completed_archive_error', fn (string $message): bool => str_contains($message, $service->service_number) && str_contains($message, 'tidak ditemukan'));
});

test('bulk export endpoints require authenticated system access for every service', function (string $type) {
    $url = route('service-archives.completed', ['type' => $type]);
    $this->get($url)->assertRedirectToRoute('login');
    $this->postJson($url)->assertUnauthorized();
    $this->actingAs(User::factory()->create(['role' => 'external']))->postJson($url)->assertForbidden();
})->with(array_keys(TaskServiceRegistry::TYPES));

test('unknown service types cannot be exported', function () {
    $this->actingAs(User::factory()->create());

    $this->postJson(route('service-archives.completed', ['type' => 'unknown']))->assertNotFound();
});

test('invalid module specific filters are rejected before streaming', function (string $type, string $field, mixed $value) {
    $this->actingAs(User::factory()->create());

    $this->postJson(route('service-archives.completed', ['type' => $type]), [$field => $value])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'search array' => ['bunker', 'search', ['unexpected']],
    'invalid status' => ['sijil', 'status', 'unknown'],
    'invalid vessel' => ['bunker', 'vessel', '../private'],
    'invalid date' => ['sijil', 'dateFrom', '2026-02-30'],
    'invalid request type' => ['safety-certificates', 'requestType', 'unknown'],
    'invalid expiry' => ['safety-certificates', 'certificateExpiry', 'unknown'],
    'invalid document type' => ['ship-nationality', 'documentType', 'unknown'],
]);
