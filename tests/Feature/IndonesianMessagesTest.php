<?php

use App\Models\BunkerService;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('login returns Indonesian messages for default validation rules', function () {
    $this->postJson(route('login.store'), ['username' => str_repeat('a', 51), 'password' => 'secret'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.username.0', 'Username maksimal 50 karakter.');
});

test('service validation and upload failures use Indonesian attribute names', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $service = BunkerService::factory()->create(['status' => 'ready_to_apply', 'assigned_staff_id' => $staff->id]);

    Livewire::actingAs($staff)->test('pages::services.bunker-detail', ['service' => $service])
        ->call('submit')->assertHasErrors('submitted_at')->assertSee('Tanggal pengajuan wajib diisi.');
    Livewire::actingAs($staff)->test('pages::services.bunker-form')
        ->set('documents.application_letter', UploadedFile::fake()->create('large.pdf', 13000, 'application/pdf'))
        ->assertHasErrors('documents.application_letter')
        ->assertSee('Ukuran File unggahan maksimal 12288 KB.');
});

test('forbidden pages display Indonesian access warnings', function () {
    $this->actingAs(User::factory()->create())->get(route('staff.index'))
        ->assertForbidden()->assertSee('Anda tidak memiliki izin untuk menjalankan tindakan ini.');
});

test('task history displays Indonesian messages without changing stored event names', function () {
    $staff = User::factory()->create();
    $task = Task::factory()->create(['assigned_to' => $staff->id]);
    $task->histories()->create(['event' => 'attachment_added', 'changed_by' => $staff->id, 'to_status' => $task->status]);

    Livewire::actingAs($staff)->test('pages::dashboard.task-detail', ['task' => $task])
        ->assertSee('Lampiran ditambahkan')->assertDontSee('attachment_added');

    expect($task->histories()->sole()->event)->toBe('attachment_added');
});
