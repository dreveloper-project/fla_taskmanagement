<?php

use App\Models\BunkerService;
use App\Models\SafetyCertificateService;
use App\Models\ShipNationalityService;
use App\Models\SijilService;
use App\Models\SijilServiceCrew;
use App\Models\SpbService;
use App\Models\Task;
use App\Models\User;
use App\Models\VesselCrew;
use App\Models\VesselDocument;
use App\Models\VesselDocumentVersion;
use App\Services\ServiceDeletion;
use App\Services\TaskServiceRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

$deletionModules = [
    'SPB' => [SpbService::class, 'spb', 'spb', 'spb', 'spb_status'],
    'Safety' => [SafetyCertificateService::class, 'safety-certificates', 'safety-certificate', 'service', 'safety_status'],
    'Bunker' => [BunkerService::class, 'bunker', 'bunker', 'service', 'bunker_status'],
    'Sijil' => [SijilService::class, 'sijil', 'sijil', 'service', 'sijil_status'],
    'Nationality' => [ShipNationalityService::class, 'ship-nationality', 'ship-nationality', 'service', 'nationality_status'],
];
dataset('deletable service modules', $deletionModules);
$deletableStates = [];
foreach (TaskServiceRegistry::TYPES as $type => $class) {
    foreach (array_keys($class::STATUSES) as $status) {
        if ($status !== 'completed') {
            $deletableStates[$type.' '.$status] = [$class, $status];
        }
    }
}
dataset('deletable service states', $deletableStates);

function deletionDocument(Model $service, string $path): Model
{
    return $service->documents()->create([
        'document_type' => $service instanceof SpbService ? 'spb_request_letter' : 'application_letter', 'file_path' => $path,
        'original_filename' => 'dokumen.pdf', 'uploaded_by' => auth()->id(), 'uploaded_at' => now(),
    ]);
}

test('every service state other than completed can be deleted by its PIC', function (string $class, string $status) {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $service = $class::factory()->create(['created_by' => $owner->id, 'assigned_staff_id' => $owner->id, 'status' => $status]);

    app(ServiceDeletion::class)->delete($service);

    $this->assertModelMissing($service);
    $this->assertModelExists($service->vessel);
})->with('deletable service states');

test('PIC can delete through each detail page and all service files and tasks are removed', function (string $class, string $route, string $component, string $computed, string $flash) {
    Storage::fake('local');
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $service = $class::factory()->create(['created_by' => $owner->id, 'assigned_staff_id' => $owner->id]);
    $documents = collect(['old.pdf', 'new.pdf'])->map(fn (string $name): Model => deletionDocument($service, $route.'/'.$service->id.'/'.$name));
    $history = $service->histories()->create(['event' => 'Created', 'to_status' => 'draft', 'changed_by' => $owner->id]);
    $task = Task::factory()->create(['taskable_type' => $service->getMorphClass(), 'taskable_id' => $service->id, 'assigned_to' => $owner->id]);
    $taskHistory = $task->histories()->create(['event' => 'created', 'to_status' => 'pending']);
    $attachment = $task->attachments()->create(['file_path' => 'tasks/'.$task->id.'/attachment.pdf', 'original_filename' => 'attachment.pdf', 'uploaded_by' => $owner->id]);
    $paths = $documents->pluck('file_path')->push($attachment->file_path);
    foreach ($paths as $path) {
        Storage::disk('local')->put($path, 'dokumen service');
    }
    $vesselDocument = VesselDocument::factory()->for($service->vessel)->create();
    Storage::disk('local')->put($vesselDocument->file_path, 'dokumen vessel');
    $unrelated = Task::factory()->create(['vessel_id' => $service->vessel_id]);

    Livewire::test('pages::services.'.$component.'-detail', [$computed => $service])
        ->assertSee('Hapus service')->assertSee('Dokumen milik Vessel tetap disimpan.')
        ->call('deleteService')->assertHasNoErrors()->assertRedirect(route($route.'.index'));

    $this->assertModelMissing($service);
    foreach ($documents as $document) {
        $this->assertModelMissing($document);
    }
    $this->assertModelMissing($history);
    $this->assertModelMissing($task);
    $this->assertModelMissing($taskHistory);
    $this->assertModelMissing($attachment);
    $this->assertModelExists($unrelated);
    $this->assertModelExists($vesselDocument);
    expect(Storage::disk('local')->allFiles())->toBe([$vesselDocument->file_path]);
    expect(session($flash))->toContain('berhasil dihapus');
})->with('deletable service modules');

test('completed services reject deletion even by admin', function (string $class, string $route, string $component, string $computed) {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $service = $class::factory()->create(['status' => 'completed']);
    $document = deletionDocument($service, $route.'/'.$service->id.'/saved.pdf');
    Storage::disk('local')->put($document->file_path, 'completed');

    Livewire::test('pages::services.'.$component.'-detail', [$computed => $service])
        ->assertDontSee('Hapus service')->call('deleteService')->assertForbidden();

    $this->assertModelExists($service);
    $this->assertModelExists($document);
    Storage::disk('local')->assertExists($document->file_path);
})->with('deletable service modules');

test('a different PIC cannot delete another service', function (string $class, string $route, string $component, string $computed) {
    $this->actingAs(User::factory()->create());
    $service = $class::factory()->create();

    Livewire::test('pages::services.'.$component.'-detail', [$computed => $service])
        ->assertDontSee('Hapus service')->call('deleteService')->assertForbidden();

    $this->assertModelExists($service);
})->with('deletable service modules');

test('deletion checks the current completion state after a detail page has been opened', function (string $class, string $route, string $component, string $computed) {
    $this->actingAs(User::factory()->admin()->create());
    $service = $class::factory()->create();
    $page = Livewire::test('pages::services.'.$component.'-detail', [$computed => $service])->assertSee('Hapus service');
    $service->update(['status' => 'completed']);

    $page->call('deleteService')->assertForbidden();

    $this->assertModelExists($service);
})->with('deletable service modules');

test('reassignment revokes deletion from a previously assigned PIC', function (string $class, string $route, string $component, string $computed) {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $service = $class::factory()->create(['assigned_staff_id' => $owner->id]);
    $page = Livewire::test('pages::services.'.$component.'-detail', [$computed => $service])->assertSee('Hapus service');
    $service->update(['assigned_staff_id' => User::factory()->create()->id]);

    $page->call('deleteService')->assertForbidden();

    $this->assertModelExists($service);
})->with('deletable service modules');

test('deleting issued services keeps current and historical vessel documents', function (string $class, string $type, string $source, string $issued) {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $service = $class::factory()->create(['status' => 'ready_for_handover']);
    $upload = deletionDocument($service, $type.'/'.$service->id.'/issued.pdf');
    $service->forceFill([$issued => $upload->id])->save();
    $current = VesselDocument::factory()->for($service->vessel)->create([$source => $service->id]);
    $version = VesselDocumentVersion::factory()->create(['vessel_document_id' => $current->id, $source => $service->id]);
    foreach ([$upload->file_path, $current->file_path, $version->file_path] as $path) {
        Storage::disk('local')->put($path, 'sertifikat');
    }

    app(ServiceDeletion::class)->delete($service);

    $this->assertModelMissing($service);
    $this->assertModelMissing($upload);
    expect($current->fresh()->getAttribute($source))->toBeNull();
    expect($version->fresh()->getAttribute($source))->toBeNull();
    Storage::disk('local')->assertMissing($upload->file_path);
    Storage::disk('local')->assertExists([$current->file_path, $version->file_path]);
})->with([
    [SafetyCertificateService::class, 'safety-certificates', 'source_safety_certificate_service_id', 'issued_certificate_document_id'],
    [ShipNationalityService::class, 'ship-nationality', 'source_ship_nationality_service_id', 'issued_document_id'],
]);

test('vessel files are protected even when a service document points to the same owned path', function (string $owner) {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $service = SpbService::factory()->create();
    $path = 'spb/'.$service->id.'/shared.pdf';
    $document = deletionDocument($service, $path);
    $vesselDocument = VesselDocument::factory()->for($service->vessel)->create(['file_path' => $owner === 'current' ? $path : 'vessels/current.pdf']);
    if ($owner === 'version') {
        VesselDocumentVersion::factory()->create(['vessel_document_id' => $vesselDocument->id, 'file_path' => $path]);
    }
    Storage::disk('local')->put($path, 'vessel-owned');

    app(ServiceDeletion::class)->delete($service);

    $this->assertModelMissing($document);
    $this->assertModelExists($vesselDocument);
    Storage::disk('local')->assertExists($path);
})->with(['current', 'version']);

test('a failed database deletion restores files and all service data', function (string $class, string $route) {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $service = $class::factory()->create();
    $document = deletionDocument($service, $route.'/'.$service->id.'/saved.pdf');
    Storage::disk('local')->put($document->file_path, 'original document');
    $task = Task::factory()->create(['taskable_type' => $service->getMorphClass(), 'taskable_id' => $service->id]);
    $class::deleting(function (): void {
        throw new RuntimeException('Database failure');
    });

    expect(fn () => app(ServiceDeletion::class)->delete($service))->toThrow(RuntimeException::class, 'Database failure');

    $this->assertModelExists($service);
    $this->assertModelExists($document);
    $this->assertModelExists($task);
    expect(Storage::disk('local')->get($document->file_path))->toBe('original document');
    expect(Storage::disk('local')->allFiles())->toBe([$document->file_path]);
})->with('deletable service modules');

test('sijil deletion includes removed crew rows and preserves the vessel crew master', function () {
    $this->actingAs(User::factory()->admin()->create());
    $service = SijilService::factory()->create();
    $row = SijilServiceCrew::factory()->for($service, 'sijilService')->create();
    $removed = SijilServiceCrew::factory()->for($service, 'sijilService')->create(['removed_at' => now()]);
    $assignment = VesselCrew::factory()->for($service->vessel)->create();
    $row->update(['crew_id' => $assignment->crew_id, 'vessel_crew_id' => $assignment->id]);

    app(ServiceDeletion::class)->delete($service);

    $this->assertModelMissing($service);
    $this->assertModelMissing($row);
    $this->assertModelMissing($removed);
    $this->assertModelExists($service->vessel);
    $this->assertModelExists($assignment);
    $this->assertModelExists($assignment->crew);
});

test('a file preparation failure restores files already moved and leaves the service unchanged', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');
    $this->actingAs(User::factory()->admin()->create());
    $service = SpbService::factory()->create();
    $first = deletionDocument($service, 'spb/'.$service->id.'/first.pdf');
    $second = deletionDocument($service, 'spb/'.$service->id.'/second.pdf');
    $disk->put($first->file_path, 'first');
    $disk->put($second->file_path, 'second');
    $proxy = Mockery::mock($disk);
    $proxy->shouldReceive('move')->andReturnUsing(fn (string $from, string $to): bool => $from === $second->file_path ? false : $disk->move($from, $to));
    Storage::shouldReceive('disk')->with('local')->andReturn($proxy);

    expect(fn () => app(ServiceDeletion::class)->delete($service))->toThrow(RuntimeException::class, 'Dokumen service gagal disiapkan untuk dihapus.');

    $this->assertModelExists($service);
    $this->assertModelExists($first);
    $this->assertModelExists($second);
    expect($disk->get($first->file_path))->toBe('first');
    expect($disk->get($second->file_path))->toBe('second');
    expect($disk->allFiles())->toHaveCount(2);
});

test('the detail page displays an Indonesian deletion error when persistence is refused', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $service = SpbService::factory()->create();
    $document = deletionDocument($service, 'spb/'.$service->id.'/saved.pdf');
    Storage::disk('local')->put($document->file_path, 'saved');
    SpbService::deleting(fn (): bool => false);

    Livewire::test('pages::services.spb-detail', ['spb' => $service])
        ->call('deleteService')->assertHasErrors('deletion')
        ->assertSee('Service belum dapat dihapus. Silakan coba lagi.');

    $this->assertModelExists($service);
    $this->assertModelExists($document);
    Storage::disk('local')->assertExists($document->file_path);
});

test('deletion can remove records for missing files and ignores paths outside the service', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $service = SpbService::factory()->create();
    deletionDocument($service, 'spb/'.$service->id.'/missing.pdf');
    deletionDocument($service, 'spb/'.($service->id + 1).'/other-service.pdf');
    deletionDocument($service, 'vessels/shared.pdf');
    Storage::disk('local')->put('spb/'.($service->id + 1).'/other-service.pdf', 'other');
    Storage::disk('local')->put('vessels/shared.pdf', 'vessel');

    app(ServiceDeletion::class)->delete($service);

    $this->assertModelMissing($service);
    Storage::disk('local')->assertExists(['spb/'.($service->id + 1).'/other-service.pdf', 'vessels/shared.pdf']);
});

test('deletion permission rejects inactive and external users', function (string $role, string $status) {
    $user = User::factory()->create(['role' => $role, 'status' => $status]);
    $this->actingAs($user);
    $service = SpbService::factory()->create(['assigned_staff_id' => $user->id]);

    expect(Gate::forUser($user)->allows('delete', $service))->toBeFalse();
    expect(fn () => app(ServiceDeletion::class)->delete($service))->toThrow(AuthorizationException::class);

    $this->assertModelExists($service);
})->with([['staff', 'inactive'], ['admin', 'inactive'], ['external', 'active']]);

test('a duplicate deletion leaves a different service untouched', function () {
    $this->actingAs(User::factory()->admin()->create());
    $service = SpbService::factory()->create();
    $other = SpbService::factory()->create();
    app(ServiceDeletion::class)->delete($service);

    expect(fn () => app(ServiceDeletion::class)->delete($service))->toThrow(ModelNotFoundException::class);

    $this->assertModelExists($other);
});
