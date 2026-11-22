<?php

use App\Models\Client;
use App\Models\User;
use App\Models\Vessel;
use App\Rules\UploadSize;
use App\Services\EntityProfilePhoto;
use App\Services\ImageOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;

it('compresses a large image before storage and keeps documents unchanged', function (): void {
    Storage::fake('local');
    $image = imagecreatetruecolor(800, 600);
    $noise = 12345;
    for ($y = 0; $y < 600; $y++) {
        for ($x = 0; $x < 800; $x++) {
            $noise = (1103515245 * $noise + 12345) & 0x7FFFFFFF;
            imagesetpixel($image, $x, $y, $noise & 0xFFFFFF);
        }
    }
    $temporary = tempnam(sys_get_temp_dir(), 'photo');
    imagepng($image, $temporary);
    imagedestroy($image);
    $file = new UploadedFile($temporary, 'photo.png', 'image/png', null, true);
    expect($file->getSize())->toBeGreaterThanOrEqual(1024 * 1024)->toBeLessThanOrEqual(4 * 1024 * 1024);
    $path = app(ImageOptimizer::class)->store($file, 'photos');
    expect(Storage::disk('local')->size($path))->toBeLessThan(1024 * 1024)
        ->and(getimagesize(Storage::disk('local')->path($path)))->not->toBeFalse();
    unlink($temporary);

    $document = UploadedFile::fake()->create('document.pdf', 2049, 'application/pdf');
    expect(Validator::make(['file' => $document], ['file' => [new UploadSize]])->fails())->toBeTrue();
    $largePhoto = UploadedFile::fake()->image('large.jpg')->size(4097);
    expect(Validator::make(['file' => $largePhoto], ['file' => [new UploadSize]])->fails())->toBeTrue();
});

it('registers an entity with an optional photo and displays it', function (string $entity): void {
    Storage::fake('local');
    $page = Livewire::actingAs(User::factory()->create())->test('pages::dashboard.'.$entity);
    if ($entity === 'vessel') {
        $page->call('create')->set('form.vessel_name', 'KM Foto')->set('form.vessel_type', 'cargo');
    } else {
        $page->set('name', 'Client Foto');
    }
    $page->set('profilePhoto', UploadedFile::fake()->image('foto.jpg', 200, 200))
        ->assertSee('foto.jpg')->call('save')->assertHasNoErrors();

    $model = $entity === 'vessel' ? Vessel::sole() : Client::sole();
    Storage::disk('local')->assertExists($model->profile_picture_path);
    $route = $entity === 'vessel' ? 'vessels.picture' : 'clients.picture';
    $page->assertSee(route($route, $model), false);
    $this->get(route($route, $model))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
})->with(['vessel', 'clients']);

it('keeps replaces and removes an existing entity photo only when saved', function (string $entity): void {
    Storage::fake('local');
    $model = $entity === 'vessel' ? Vessel::factory()->create() : Client::factory()->create();
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('photos', 'local');
    $model->profile_picture_path = $oldPath;
    $model->save();
    $page = Livewire::actingAs(User::factory()->create())->test('pages::dashboard.'.$entity);
    $page->call('edit', $model->id)->call('save')->assertHasNoErrors();
    expect($model->fresh()->profile_picture_path)->toBe($oldPath);
    $page->call('edit', $model->id)->set('profilePhoto', UploadedFile::fake()->image('new.png'))
        ->call('save')->assertHasNoErrors();
    $newPath = $model->fresh()->profile_picture_path;
    expect($newPath)->not->toBe($oldPath);
    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists($newPath);
    $page->call('edit', $model->id)->call('removeProfilePhoto');
    Storage::disk('local')->assertExists($newPath);
    $page->call('save')->assertHasNoErrors();
    expect($model->fresh()->profile_picture_path)->toBeNull();
    Storage::disk('local')->assertMissing($newPath);
})->with(['vessel', 'clients']);

it('rejects invalid profile photos without replacing the saved photo', function (string $entity, string $invalid): void {
    Storage::fake('local');
    $model = $entity === 'vessel' ? Vessel::factory()->create() : Client::factory()->create();
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('photos', 'local');
    $model->profile_picture_path = $oldPath;
    $model->save();
    $file = match ($invalid) {
        'type' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
        'size' => UploadedFile::fake()->image('large.jpg')->size(4097),
        'dimensions' => UploadedFile::fake()->image('wide.jpg', 4097, 10),
    };
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.'.$entity)
        ->call('edit', $model->id)->set('profilePhoto', $file)->assertHasErrors('profilePhoto')
        ->call('save')->assertHasErrors('profilePhoto')->assertSee(match ($invalid) {
            'type' => 'Foto profil harus berupa gambar.',
            'size' => 'Ukuran foto profil maksimal 4 MB.',
            'dimensions' => 'Dimensi foto profil maksimal 4096 x 4096 piksel.',
        });
    expect($model->fresh()->profile_picture_path)->toBe($oldPath);
    Storage::disk('local')->assertExists($oldPath);
})->with(['vessel', 'clients'])->with(['type', 'size', 'dimensions']);

it('discards pending photo removal when cancelling the form', function (string $entity): void {
    Storage::fake('local');
    $model = $entity === 'vessel' ? Vessel::factory()->create() : Client::factory()->create();
    $path = UploadedFile::fake()->image('old.jpg')->store('photos', 'local');
    $model->profile_picture_path = $path;
    $model->save();
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.'.$entity)
        ->call('edit', $model->id)->call('removeProfilePhoto')
        ->call($entity === 'vessel' ? 'closeForm' : 'cancel')
        ->assertSet('profilePhoto', null)->assertSet('removePhoto', false);
    expect($model->fresh()->profile_picture_path)->toBe($path);
    Storage::disk('local')->assertExists($path);
})->with(['vessel', 'clients']);

it('protects entity photos and returns a missing image response when absent', function (string $entity): void {
    Storage::fake('local');
    $model = $entity === 'vessel' ? Vessel::factory()->create() : Client::factory()->create();
    $url = route($entity === 'vessel' ? 'vessels.picture' : 'clients.picture', $model);
    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['role' => 'external']))->get($url)->assertForbidden();
    $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
})->with(['vessel', 'clients']);

it('cleans up a new image and preserves the old image when persistence fails', function (): void {
    Storage::fake('local');
    $client = Client::factory()->create();
    $path = UploadedFile::fake()->image('old.jpg')->store('clients/photos', 'local');
    $client->profile_picture_path = $path;
    $client->save();
    expect(fn () => app(EntityProfilePhoto::class)->save($client, UploadedFile::fake()->image('new.jpg'), false, function (): void {
        throw new RuntimeException('Persistence failed');
    }))->toThrow(RuntimeException::class);
    expect($client->fresh()->profile_picture_path)->toBe($path);
    expect(Storage::disk('local')->allFiles('clients/photos'))->toBe([$path]);
});

it('preserves the vessel photo when managing documents only', function (): void {
    Storage::fake('local');
    $vessel = Vessel::factory()->create();
    $path = UploadedFile::fake()->image('vessel.jpg')->store('photos', 'local');
    $vessel->profile_picture_path = $path;
    $vessel->save();
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('manageDocuments', $vessel->id)->assertDontSee('Foto profil vessel')
        ->set('removePhoto', true)->call('save')->assertHasNoErrors();
    expect($vessel->fresh()->profile_picture_path)->toBe($path);
    Storage::disk('local')->assertExists($path);
});
