<?php

use App\Models\BunkerService;
use App\Models\SafetyCertificateService;
use App\Models\ShipNationalityService;
use App\Models\SijilService;
use App\Models\SpbService;
use App\Models\Task;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;

test('failed service validation retains vessel text selections dates and temporary uploads', function (string $component, string $model, ?string $dateField, string $documentType) {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $vessel = Vessel::factory()->create();
    $notes = str_repeat('Catatan ', 626);

    $page = Livewire::actingAs($admin)->test($component)
        ->set('form.vessel_id', $vessel->id)
        ->set('form.notes', $notes);
    if ($model !== SijilService::class) {
        $page->set('form.priority', 'high');
    }
    if ($dateField !== null) {
        $page->set('form.'.$dateField, '2026-10-27T14:30');
    }
    $documentField = 'documents.'.$documentType;
    if ($model === SijilService::class) {
        $page->call('addCrew');
        $documentField = 'crewDocuments.0.'.$documentType;
    }
    $page
        ->set($documentField, UploadedFile::fake()->image('permohonan.png'))
        ->call('save')
        ->assertHasErrors(['notes' => 'max'])
        ->assertSet('form.vessel_id', $vessel->id)
        ->assertSet('form.notes', $notes)
        ->assertSet($documentField, fn (mixed $file): bool => $file instanceof UploadedFile && $file->getClientOriginalName() === 'permohonan.png');
    if ($dateField !== null) {
        $page->assertSet('form.'.$dateField, '2026-10-27T14:30');
    }
    if ($model !== SijilService::class) {
        $page->assertSet('form.priority', 'high');
    }

    $this->assertDatabaseCount((new $model)->getTable(), 0);
})->with([
    'SPB' => ['pages::services.spb-form', SpbService::class, 'estimated_departure_at', 'crew_list'],
    'Bunker' => ['pages::services.bunker-form', BunkerService::class, 'scheduled_at', 'application_letter'],
    'Sertifikat Keselamatan' => ['pages::services.safety-certificate-form', SafetyCertificateService::class, 'due_at', 'application_letter'],
    'Sijil' => ['pages::services.sijil-form', SijilService::class, null, 'seafarer_book'],
    'Kebangsaan' => ['pages::services.ship-nationality-form', ShipNationalityService::class, 'due_at', 'application_letter'],
]);

function expectErrorsAtFormStart(string $html, string $action, string $attribute = 'wire:submit'): void
{
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    try {
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    $alerts = (new DOMXPath($document))->query('//*[@role="alert"]');
    expect($alerts->length)->toBe(1);
    $summary = $alerts->item(0);
    $form = $summary->parentNode;
    expect($form->nodeName)->toBe('form');
    $expression = $form->getAttribute($attribute);
    if ($attribute === 'wire:submit' && ! str_contains($action, '(')) {
        $expression = explode('(', $expression, 2)[0];
    }
    expect($expression)->toBe($action);
    expect($form->firstElementChild->isSameNode($summary))->toBeTrue();
}

test('service draft forms include the shared loading popup and a saving indicator inside the submit button', function (string $routeName) {
    $this->actingAs(User::factory()->admin()->create());

    $response = $this->get(route($routeName))
        ->assertSee('data-form-loading', false)
        ->assertSee('Memproses data');

    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    try {
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    $xpath = new DOMXPath($document);
    expect($xpath->query('//dialog[@data-form-loading]')->length)->toBe(1);
    $button = $xpath->query('//form[@*[name()="wire:submit"]="save" or starts-with(@*[name()="wire:submit"], "save(")]//button[@type="submit"]')->item(0);
    expect($button)->not->toBeNull();
    expect($xpath->query('.//span[@*[name()="wire:loading.inline-flex"] and @*[name()="wire:target"]="save"]', $button)->item(0)->textContent)
        ->toBe('Menyimpan...');
})->with(['spb.create', 'bunker.create', 'safety-certificates.create', 'sijil.create', 'ship-nationality.create']);

test('single form validation messages are grouped before fields', function (string $component, ?string $open, string $action) {
    $this->actingAs(User::factory()->admin()->create());
    $page = Livewire::test($component);
    if ($open !== null) {
        $page->call($open);
    }

    $page->call($action)->assertHasErrors()->assertSee('Form belum dapat diproses.');

    expectErrorsAtFormStart($page->html(), $action);
})->with([
    ['pages::dashboard.clients', null, 'save'],
    ['pages::dashboard.vessel', 'create', 'save'],
    ['pages::dashboard.staff-management', 'create', 'save'],
    ['pages::dashboard.task-monitoring', 'create', 'save'],
    ['pages::services.spb-form', null, 'save'],
    ['pages::services.bunker-form', null, 'save'],
    ['pages::services.safety-certificate-form', null, 'save'],
    ['pages::services.sijil-form', null, 'save'],
    ['pages::services.ship-nationality-form', null, 'save'],
]);

test('login errors appear above the credentials', function () {
    $response = $this->followingRedirects()->from(route('login'))->post(route('login.store'), [])
        ->assertSee('Form belum dapat diproses.');

    expectErrorsAtFormStart($response->getContent(), route('login.store'), 'action');
});

test('registration errors appear above the profile and credentials', function () {
    $response = $this->followingRedirects()->from(route('register'))->post(route('register.store'), [])
        ->assertSee('Form belum dapat diproses.');

    expectErrorsAtFormStart($response->getContent(), route('register.store'), 'action');
});

test('account validation stays in the profile or password form that failed', function (string $routeName, string $method) {
    $this->actingAs(User::factory()->create());
    $response = $this->followingRedirects()->from(route('account.settings'))->{$method}(route($routeName), [])
        ->assertSee('Form belum dapat diproses.');

    expectErrorsAtFormStart($response->getContent(), route($routeName), 'action');
})->with([
    ['account.update', 'patch'],
    ['account.password.update', 'put'],
]);

test('task errors stay with the submitted form and are cleared on the next action', function () {
    $this->actingAs(User::factory()->admin()->create());
    $task = Task::factory()->create();
    $page = Livewire::test('pages::dashboard.task-detail', ['task' => $task])->set('editing', true);

    $page->call('uploadAttachment')->assertHasErrors('attachment');
    expectErrorsAtFormStart($page->html(), 'uploadAttachment');
    $page->call('saveNotes')->assertHasErrors('notes')->assertHasNoErrors('attachment');
    expectErrorsAtFormStart($page->html(), 'saveNotes');
    $page->set('form.title', '')->call('save')->assertHasErrors('title');
    expectErrorsAtFormStart($page->html(), 'save');

    expect($task->fresh())->title->toBe($task->title)->progress->toBe(0);
});

test('service cancellation errors do not leak into the submission form', function () {
    $this->actingAs(User::factory()->create());
    $service = BunkerService::factory()->create(['status' => 'ready_to_apply', 'assigned_staff_id' => auth()->id()]);
    $page = Livewire::test('pages::services.bunker-detail', ['service' => $service]);

    $page->call('cancel')->assertHasErrors('cancellation_reason');
    expectErrorsAtFormStart($page->html(), 'cancel');
    $page->call('submit')->assertHasErrors('submitted_at')->assertHasNoErrors('cancellation_reason');
    expectErrorsAtFormStart($page->html(), 'submit');

    expect($service->fresh()->status)->toBe('ready_to_apply');
});

test('service submission errors appear above the relevant action fields', function (string $component, string $class, string $parameter, string $action) {
    $this->actingAs(User::factory()->create());
    $service = $class::factory()->create(['status' => 'ready_to_apply', 'assigned_staff_id' => auth()->id()]);
    $page = Livewire::test($component, [$parameter => $service]);

    $page->call($action)->assertHasErrors();

    expectErrorsAtFormStart($page->html(), $action);
    expect($service->fresh()->status)->toBe('ready_to_apply');
})->with([
    ['pages::services.spb-detail', SpbService::class, 'spb', 'recordResult'],
    ['pages::services.safety-certificate-detail', SafetyCertificateService::class, 'service', 'apply'],
    ['pages::services.ship-nationality-detail', ShipNationalityService::class, 'service', 'apply'],
    ['pages::services.sijil-detail', SijilService::class, 'service', 'submit'],
]);

test('temporary upload errors appear at the upload form before submission', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create());
    $service = BunkerService::factory()->create(['status' => 'under_review', 'assigned_staff_id' => auth()->id()]);
    $page = Livewire::test('pages::services.bunker-detail', ['service' => $service]);

    $page->set('documents.revision_attachment', UploadedFile::fake()->create('large.pdf', 13000, 'application/pdf'))
        ->assertHasErrors('documents.revision_attachment');

    expectErrorsAtFormStart($page->html(), 'recordReview');
});

test('each report filter form displays its own errors', function () {
    $this->actingAs(User::factory()->admin()->create());
    $page = Livewire::test('pages::dashboard.reports')->call('selectTab', 'vessels');

    $page->set('filters.expiry_from', 'invalid')->call('applyFilters', 'documents')->assertHasErrors('expiry_from');
    expectErrorsAtFormStart($page->html(), "applyFilters('documents')");
    $page->set('filters.expiry_from', '')->set('filters.date_from', 'invalid')->call('applyFilters')->assertHasErrors('date_from');
    expectErrorsAtFormStart($page->html(), 'applyFilters');
});

test('PIC assignment errors appear above the matching service controls', function () {
    $this->actingAs(User::factory()->admin()->create());
    $service = BunkerService::factory()->create();
    $page = Livewire::test('pages::dashboard.staff-management')->call('viewWorkload', $service->assigned_staff_id);

    $page->call('reassign', 'bunker', $service->id)->assertHasErrors('assignments.bunker_'.$service->id);

    expectErrorsAtFormStart($page->html(), "reassign('bunker', {$service->id})");
    expect($service->fresh()->assigned_staff_id)->toBe($service->assigned_staff_id);
});

test('error summaries include nested upload failures and escape message content', function () {
    $errors = (new ViewErrorBag)->put('default', new MessageBag([
        'documents.application_letter' => ['<script>alert(1)</script>'],
        'cancellation_reason' => ['Cancellation belongs to another form'],
    ]));

    $view = $this->withViewErrors($errors->getMessages())->blade('<x-form-errors :fields="[\'documents.*\']" />');

    $view->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('Cancellation belongs to another form')->assertSee('role="alert"', false);
});

test('popup contains validation messages safely and takes precedence over success flashes', function () {
    session()->flash('vessel_status', 'Vessel berhasil disimpan.');

    $view = $this->withViewErrors(['form.vessel_name' => '<script>alert(1)</script>'])
        ->blade('<x-form-notifications />');

    $view->assertSee('data-form-popup', false)->assertSee('&quot;type&quot;:&quot;error&quot;', false)
        ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('Vessel berhasil disimpan.');
});

test('popup receives success messages from redirected form submissions', function () {
    session()->flash('profile_status', 'Informasi akun berhasil diperbarui.');

    $view = $this->withViewErrors([])->blade('<x-form-notifications />');

    $view->assertSee('data-form-popup', false)->assertSee('&quot;type&quot;:&quot;success&quot;', false)
        ->assertSee('Informasi akun berhasil diperbarui.');
});
