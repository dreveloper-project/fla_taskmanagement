<?php

use App\Models\Client;
use App\Models\User;
use App\Services\ClientBillingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('weekly and monthly schedules use the next configured day', function (string $frequency, int $day, string $from, string $expected) {
    $schedule = app(ClientBillingSchedule::class);
    expect($schedule->nextDate($frequency, $day, CarbonImmutable::parse($from))->toDateString())->toBe($expected);
})->with([
    ['weekly', 1, '2026-11-01', '2026-11-02'], ['weekly', 7, '2026-11-01', '2026-11-01'],
    ['monthly', 31, '2026-02-01', '2026-02-28'], ['monthly', 15, '2026-11-16', '2026-12-15'],
]);

test('only admins can configure a client schedule and invalid days are rejected', function () {
    $client = Client::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
    $schedule = app(ClientBillingSchedule::class);
    expect(fn () => $schedule->save($client->id, 'weekly', 8))->toThrow(ValidationException::class);
    $saved = $schedule->save($client->id, 'weekly', 3);
    expect($saved->billing_frequency)->toBe('weekly')->and($saved->billing_day)->toBe(3)->and($saved->next_billing_on)->not->toBeNull();
    $schedule->save($client->id, 'manual', null);
    expect($client->fresh()->next_billing_on)->toBeNull();
    $this->actingAs(User::factory()->create());
    expect(fn () => $schedule->save($client->id, 'monthly', 1))->toThrow(AuthorizationException::class);
});

test('finance can set and change a client billing schedule', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00', 'Asia/Makassar'));
    $client = Client::factory()->create();
    $page = Livewire::actingAs(User::factory()->admin()->create())->test('pages::dashboard.finance')
        ->set('scheduleClientId', (string) $client->id)->set('billingFrequency', 'weekly')
        ->set('billingDay', '1')->call('saveSchedule')->assertHasNoErrors();
    expect($client->fresh()->next_billing_on->toDateString())->toBe('2026-11-02');
    $page->set('billingFrequency', 'monthly')->set('billingDay', '31')->call('saveSchedule')->assertHasNoErrors();
    expect($client->fresh()->next_billing_on->toDateString())->toBe('2026-11-30');
});
