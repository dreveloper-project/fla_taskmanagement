<?php

use App\Models\User;
use Livewire\Livewire;

test('failed vessel validation retains submitted fields and does not reinitialize its date controls', function (): void {
    $initializers = function (string $html): array {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $initializers = [];
        foreach ((new DOMXPath($document))->query('//*[@data-date-time-input]') as $control) {
            $name = $control->getElementsByTagName('input')->item(0)->getAttribute('name');
            $initializers[$name] = $control->getAttribute('x-data');
        }

        return $initializers;
    };
    $page = Livewire::actingAs(User::factory()->create())
        ->test('pages::dashboard.vessel', ['registrationPage' => true]);
    $before = $initializers($page->html());

    $page->set('form.vessel_name', 'KM Isian Tetap')
        ->set('form.vessel_type', 'passenger')
        ->set('form.gross_tonnage', '123.45')
        ->set('documents.grosse_akta.issued_date', '2026-10-01')
        ->set('documents.grosse_akta.expired_date', '2027-10-01')
        ->call('save')
        ->assertHasErrors(['documents.grosse_akta.file'])
        ->assertSet('showForm', true)
        ->assertSet('form.vessel_name', 'KM Isian Tetap')
        ->assertSet('form.vessel_type', 'passenger')
        ->assertSet('form.gross_tonnage', '123.45')
        ->assertSet('documents.grosse_akta.issued_date', '2026-10-01')
        ->assertSet('documents.grosse_akta.expired_date', '2027-10-01')
        ->assertSee('data-initial-value="2026-10-01"', false);

    expect($initializers($page->html()))->toBe($before);
    $this->assertDatabaseCount('vessels', 0);
});

test('date controls offer direct typing and a keyboard accessible calendar', function (): void {
    $this->blade('<x-date-time-input name="issued_at" value="2026-10-27" wire:model.live="form.issued_at" />')
        ->assertSee('value="27/10/2026"', false)
        ->assertSee('wire:model.live="form.issued_at"', false)
        ->assertSee('placeholder="DD/MM/YYYY"', false)
        ->assertSee('popover="auto"', false)
        ->assertSee('role="grid"', false)
        ->assertSee('aria-label="Bulan"', false)
        ->assertSee('aria-label="Tahun"', false)
        ->assertSee('Hari ini')
        ->assertDontSee('type="date"', false);
});

test('datetime controls retain boundaries and offer a separate 24 hour WITA field', function (): void {
    $this->blade('<x-date-time-input name="departed_at" type="datetime-local" value="2026-10-27T14:30" min="2026-10-26T10:00" max="2026-10-28T15:00" required wire:model="departure.actual_departure_at" />')
        ->assertSee('data-min="2026-10-26T10:00"', false)
        ->assertSee('data-max="2026-10-28T15:00"', false)
        ->assertSee('data-required="true"', false)
        ->assertSee('wire:model="departure.actual_departure_at"', false)
        ->assertSee('id="departed_at-time"', false)
        ->assertSee('placeholder="HH:MM"', false)
        ->assertSee('WITA')
        ->assertSee('Sekarang')
        ->assertDontSee('type="datetime-local"', false);
});

test('disabled date controls prevent changes through calendar and shortcuts', function (): void {
    $this->blade('<x-date-time-input name="issued_at" value="2026-10-27" disabled />')
        ->assertSee('disabled', false)
        ->assertDontSee('x-on:click="useNow()"', false)
        ->assertDontSee('x-on:click="clear()"', false);
});

test('date controls escape untrusted values in markup and Alpine configuration', function (): void {
    $value = '\"><script>alert(1)</script>';

    $this->blade('<x-date-time-input name="issued_at" :value="$value" />', ['value' => $value])
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;', false);
});

test('all service draft forms use the shared date and time controls', function (string $routeName): void {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route($routeName))
        ->assertSee('data-date-time-input', false)
        ->assertSee('placeholder="HH:MM"', false)
        ->assertSee('Sekarang')
        ->assertDontSee('type="datetime-local"', false);
})->with(['spb.create', 'bunker.create', 'safety-certificates.create', 'sijil.create', 'ship-nationality.create']);

test('service and dashboard date filters use the shared calendar', function (string $routeName): void {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route($routeName))
        ->assertSee('data-date-time-input', false)
        ->assertSee('Hari ini')
        ->assertDontSee('type="date"', false);
})->with(['spb.index', 'bunker.index', 'safety-certificates.index', 'sijil.index', 'ship-nationality.index', 'reports.index', 'dashboard.task-monitoring']);
