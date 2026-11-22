<?php

use App\Models\BunkerService;
use App\Models\SafetyCertificateService;
use App\Models\ShipNationalityService;
use App\Models\SijilService;
use App\Models\SpbService;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

dataset('service archive modules', [
    'SPB' => [SpbService::class, 'spb', 'issued_spb'],
    'Safety' => [SafetyCertificateService::class, 'safety-certificates', 'issued_certificate'],
    'Bunker' => [BunkerService::class, 'bunker', 'approval_document'],
    'Sijil' => [SijilService::class, 'sijil', 'official_result'],
    'Nationality' => [ShipNationalityService::class, 'ship-nationality', 'issued_document'],
]);

function archiveDocument(Model $service, string $type, string $name, string $content): Model
{
    $path = 'archive-tests/'.$service->getTable().'/'.$service->id.'/'.$name;
    Storage::disk('local')->put($path, $content);

    return $service->documents()->create([
        'document_type' => $type, 'file_path' => $path, 'original_filename' => 'scan.pdf',
        'uploaded_by' => auth()->id(), 'uploaded_at' => now(),
    ]);
}

/**
 * @param  TestResponse<BinaryFileResponse>  $response
 * @return array<string, string>
 */
function serviceArchiveEntries(TestResponse $response): array
{
    $path = $response->baseResponse->getFile()->getPathname();
    $zip = new ZipArchive;

    try {
        expect($zip->open($path))->toBeTrue();
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

test('completed services download only their own files including historical versions', function (string $class, string $type, string $issuedType) {
    Storage::fake('local');
    $this->freezeTime();
    $this->actingAs(User::factory()->admin()->create());
    $service = $class::factory()->create(['service_number' => 'SERVICE-2026-001', 'status' => 'completed']);
    $other = $class::factory()->for($service->vessel)->create(['service_number' => 'SERVICE-2026-002', 'status' => 'completed']);
    $applicationType = $class === SpbService::class ? 'spb_request_letter' : 'application_letter';
    archiveDocument($service, $applicationType, 'old.pdf', 'old version');
    archiveDocument($service, $applicationType, 'new.pdf', 'new version');
    archiveDocument($service, $issuedType, 'issued.pdf', 'official document');
    archiveDocument($service, 'vessel_reference', 'reference.pdf', 'vessel snapshot');
    archiveDocument($other, $issuedType, 'other.pdf', 'another transaction');

    $response = $this->get(route('service-archives.download', ['type' => $type, 'service' => $service->id]))
        ->assertDownload('Arsip-service-2026-001.zip')->assertHeader('Content-Type', 'application/zip')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $entries = serviceArchiveEntries($response);

    expect($entries)->toHaveCount(4);
    expect(array_values($entries))->toEqualCanonicalizing(['old version', 'new version', 'official document', 'vessel snapshot']);
    foreach (array_keys($entries) as $name) {
        expect($name)->toStartWith('service-2026-001/Berkas/')->toEndWith('.pdf');
    }
    expect($service->fresh()->status)->toBe('completed');
})->with('service archive modules');

test('each completed transaction has its own archive button on the list and detail pages', function (string $class, string $type) {
    $this->actingAs(User::factory()->admin()->create());
    $first = $class::factory()->create(['status' => 'completed']);
    $second = $class::factory()->create(['status' => 'completed']);
    $draft = $class::factory()->create(['status' => 'draft']);
    $firstUrl = route('service-archives.download', ['type' => $type, 'service' => $first->id]);
    $secondUrl = route('service-archives.download', ['type' => $type, 'service' => $second->id]);
    $draftUrl = route('service-archives.download', ['type' => $type, 'service' => $draft->id]);

    $this->get(route($type.'.index'))->assertSee($firstUrl, false)->assertSee($secondUrl, false)->assertDontSee($draftUrl, false);
    $this->get(route($type.'.show', $first))->assertSee($firstUrl, false)->assertSee('Unduh arsip ZIP');
    $this->get(route($type.'.show', $draft))->assertDontSee($draftUrl, false);
})->with('service archive modules');

test('services that are not completed cannot be archived even with a direct URL', function (string $class, string $type) {
    $this->actingAs(User::factory()->admin()->create());
    $service = $class::factory()->create(['status' => 'draft']);

    $this->get(route('service-archives.download', ['type' => $type, 'service' => $service->id]))->assertNotFound();
})->with('service archive modules');

test('service archives require an authenticated staff or admin account', function () {
    $url = route('service-archives.download', ['type' => 'spb', 'service' => 1]);
    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['role' => 'external']))->get($url)->assertForbidden();
});

test('inactive accounts are signed out before an archive can be downloaded', function (string $role) {
    $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'inactive']));

    $this->get(route('service-archives.download', ['type' => 'spb', 'service' => 1]))
        ->assertRedirect(route('login'))->assertSessionHasErrors(['username' => 'Akun tidak aktif. Hubungi Admin.']);
    $this->assertGuest();
})->with(['staff', 'admin']);

test('unknown archive types and missing services return not found', function () {
    $this->actingAs(User::factory()->create());
    $this->get(route('service-archives.download', ['type' => 'unknown', 'service' => 1]))->assertNotFound();
    $this->get(route('service-archives.download', ['type' => 'spb', 'service' => 99999]))->assertNotFound();
});

test('archives include task attachments according to the existing task access rules', function () {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->create();
    $this->actingAs($admin);
    $service = SpbService::factory()->create(['status' => 'completed']);
    archiveDocument($service, 'issued_spb', 'issued.pdf', 'official');
    foreach ([$staff, $admin] as $owner) {
        $task = Task::factory()->for($service, 'taskable')->create(['assigned_to' => $owner->id, 'status' => 'completed']);
        $path = 'tasks/'.$task->id.'/evidence.pdf';
        Storage::disk('local')->put($path, 'attachment for '.$owner->role);
        $task->attachments()->create(['file_path' => $path, 'original_filename' => '../../evidence.pdf', 'uploaded_by' => $owner->id]);
    }
    $url = route('service-archives.download', ['type' => 'spb', 'service' => $service->id]);

    $adminEntries = serviceArchiveEntries($this->get($url)->assertDownload());
    expect(array_values($adminEntries))->toEqualCanonicalizing(['official', 'attachment for staff', 'attachment for admin']);
    foreach (array_keys($adminEntries) as $name) {
        expect($name)->not->toContain('..', '\\');
    }

    $this->actingAs($staff);
    $staffEntries = serviceArchiveEntries($this->get($url)->assertDownload());
    expect(array_values($staffEntries))->toEqualCanonicalizing(['official', 'attachment for staff']);
});

test('missing files cancel the download and show an error on the service detail page', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create());
    $service = SpbService::factory()->create(['status' => 'completed']);
    archiveDocument($service, 'spb_request_letter', 'available.pdf', 'available');
    $missing = archiveDocument($service, 'issued_spb', 'missing.pdf', 'missing');
    Storage::disk('local')->delete($missing->file_path);

    $this->get(route('service-archives.download', ['type' => 'spb', 'service' => $service->id]))
        ->assertRedirect(route('spb.show', $service))->assertSessionHas('archive_error');
    $this->get(route('spb.show', $service))->assertSee('Ada berkas layanan yang tidak ditemukan.');
});

test('completed services without files show an explanation instead of an empty ZIP', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create());
    $service = SpbService::factory()->create(['status' => 'completed']);

    $this->get(route('service-archives.download', ['type' => 'spb', 'service' => $service->id]))
        ->assertRedirect(route('spb.show', $service))
        ->assertSessionHas('archive_error', 'Belum ada berkas yang dapat diunduh untuk layanan ini.');
});

test('archive files cannot reference a path outside the private storage disk', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $service = SpbService::factory()->create(['status' => 'completed']);
    $outside = tempnam(dirname(Storage::disk('local')->path('')), 'fla-outside-');
    file_put_contents($outside, 'outside private storage');
    $service->documents()->create([
        'document_type' => 'issued_spb', 'file_path' => '../'.basename($outside),
        'original_filename' => 'secret.pdf', 'uploaded_by' => auth()->id(), 'uploaded_at' => now(),
    ]);

    try {
        $this->get(route('service-archives.download', ['type' => 'spb', 'service' => $service->id]))
            ->assertRedirect(route('spb.show', $service))->assertSessionHas('archive_error');
    } finally {
        unlink($outside);
    }
});
