<?php

use App\Models\SpbDocument;
use App\Models\SpbService;
use App\Models\User;
use App\Services\ArchiveDownloadResponse;
use App\Services\ServiceArchive;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/** @return array{events: list<array<string, mixed>>, zip: string} */
function archiveProgressPayload(string $content): array
{
    $events = [];
    while (($newline = strpos($content, "\n")) !== false) {
        $event = json_decode(substr($content, 0, $newline), true, flags: JSON_THROW_ON_ERROR);
        $content = substr($content, $newline + 1);
        $events[] = $event;
        if ($event['type'] === 'file') {
            return ['events' => $events, 'zip' => $content];
        }
    }

    return ['events' => $events, 'zip' => ''];
}

test('the preparation stream reports progress and sends a complete filtered ZIP', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $service = SpbService::factory()->create(['status' => 'completed', 'priority' => 'high']);
    SpbService::factory()->create(['status' => 'completed', 'priority' => 'low']);
    $document = SpbDocument::factory()->for($service, 'spbService')->create();
    Storage::disk('local')->put($document->file_path, 'official file');

    $response = $this->postJson(route('spb.archives.download', ['priority' => 'high']))
        ->assertHeader('Content-Type', 'application/vnd.fla.archive-stream')
        ->assertHeader('X-Accel-Buffering', 'no');
    $payload = archiveProgressPayload($response->streamedContent());

    $progress = collect($payload['events'])->where('type', 'progress')->pluck('percent')->all();
    expect($progress[0])->toBe(0);
    expect(end($progress))->toBe(100);
    expect($progress)->toBe(collect($progress)->sort()->values()->all());
    $file = collect($payload['events'])->last();
    expect($file['size'])->toBe(strlen($payload['zip']));
    $path = tempnam(sys_get_temp_dir(), 'archive-test-');
    file_put_contents($path, $payload['zip']);
    $zip = new ZipArchive;
    try {
        expect($zip->open($path))->toBeTrue();
        expect($zip->numFiles)->toBe(2);
        $data = json_decode($zip->getFromIndex(0), true, flags: JSON_THROW_ON_ERROR);
        expect($data['service']['id'])->toBe($service->id);
        expect($zip->getFromIndex(1))->toBe('official file');
    } finally {
        $zip->close();
        unlink($path);
    }
});

test('individual service ZIPs support preparation progress', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create());
    $service = SpbService::factory()->create(['status' => 'completed']);
    $document = SpbDocument::factory()->for($service, 'spbService')->create();
    Storage::disk('local')->put($document->file_path, 'individual file');

    $response = $this->postJson(route('service-archives.download', ['type' => 'spb', 'service' => $service->id]));
    $payload = archiveProgressPayload($response->streamedContent());

    expect(collect($payload['events'])->last()['type'])->toBe('file');
    expect($payload['zip'])->toStartWith('PK');
});

test('missing documents produce an error event instead of a partial ZIP', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create());
    $service = SpbService::factory()->create(['status' => 'completed']);
    SpbDocument::factory()->for($service, 'spbService')->create();

    $response = $this->postJson(route('spb.archives.download'));
    $payload = archiveProgressPayload($response->streamedContent());

    expect(collect($payload['events'])->last())->toMatchArray(['type' => 'error']);
    expect(collect($payload['events'])->last()['message'])->toContain('tidak ditemukan');
    expect($payload['zip'])->toBe('');
});

test('completed streams delete their temporary file after transfer', function () {
    $path = tempnam(sys_get_temp_dir(), 'archive-test-');
    file_put_contents($path, 'ZIP bytes');
    $response = TestResponse::fromBaseResponse(app(ArchiveDownloadResponse::class)->stream(
        fn (Closure $progress, Closure $cancelled): string => $path, 'test.zip'));

    $payload = archiveProgressPayload($response->streamedContent());

    expect($payload['zip'])->toBe('ZIP bytes');
    expect(is_file($path))->toBeFalse();
});

test('cancellation during compression stops completion and removes the temporary ZIP', function () {
    Storage::fake('local');
    SpbService::factory()->create(['status' => 'completed']);
    $cancelled = false;
    $progress = [];
    $before = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'fla-archive-*');

    expect(fn () => app(ServiceArchive::class)->createCompletedSpbs(SpbService::query(),
        function (int $percent, string $message) use (&$cancelled, &$progress): void {
            $progress[] = $percent;
            if ($percent > 20) {
                $cancelled = true;
            }
        }, function () use (&$cancelled): bool {
            return $cancelled;
        }))->toThrow(RuntimeException::class, 'Pembuatan ZIP dibatalkan.');

    expect($progress)->not->toContain(100);
    expect(glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'fla-archive-*'))->toBe($before);
});

test('cancellation before collection removes the temporary ZIP', function () {
    $before = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'fla-archive-*');

    expect(fn () => app(ServiceArchive::class)->createCompletedSpbs(SpbService::query(), cancelled: fn (): bool => true))
        ->toThrow(RuntimeException::class, 'Pembuatan ZIP dibatalkan.');

    expect(glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'fla-archive-*'))->toBe($before);
});

test('preparation requires authenticated system access', function (string $route, array $parameters) {
    $url = route($route, $parameters);
    $this->postJson($url)->assertUnauthorized();
    $this->actingAs(User::factory()->create(['role' => 'external']))->postJson($url)->assertForbidden();
})->with([
    'bulk' => ['spb.archives.download', []],
    'individual' => ['service-archives.download', ['type' => 'spb', 'service' => 1]],
]);

test('invalid filters are rejected before preparation begins', function () {
    $this->actingAs(User::factory()->create());

    $this->postJson(route('spb.archives.download'), ['status' => 'invalid'])
        ->assertUnprocessable()->assertJsonValidationErrors('status');
});

test('draft services cannot be prepared through the streaming endpoint', function () {
    $this->actingAs(User::factory()->create());
    $service = SpbService::factory()->create(['status' => 'draft']);

    $this->postJson(route('service-archives.download', ['type' => 'spb', 'service' => $service->id]))->assertNotFound();
});
