<?php

use App\Models\SpbService;
use App\Models\User;
use Livewire\Livewire;

test('assigned staff can update the agent fee at any SPB status and the change is recorded', function (string $status) {
    $staff = User::factory()->create();
    $spb = SpbService::factory()->create(['assigned_staff_id' => $staff->id, 'status' => $status, 'agent_fee' => '1000000']);
    Livewire::actingAs($staff)->test('pages::services.spb-detail', ['spb' => $spb])
        ->set('agentFee', '1250000.50')->call('saveAgentFee')->assertHasNoErrors()->assertSee('1.250.000,50');
    expect($spb->fresh()->agent_fee)->toEqual('1250000.50')->and($spb->fresh()->status)->toBe($status);
    $history = $spb->histories()->sole();
    expect($history->event)->toBe('Harga Jasa Agen Diubah')
        ->and($history->changed_by)->toBe($staff->id)
        ->and($history->metadata['previous_agent_fee'])->toEqual('1000000.00')
        ->and($history->metadata['agent_fee'])->toEqual('1250000.50');
})->with(['draft', 'ready_to_apply', 'applied', 'revision_required', 'rejected', 'ready_to_sail', 'sailing', 'completed', 'failed_to_sail']);

test('agent fees reject invalid input without changing the record', function (string $amount) {
    $staff = User::factory()->create();
    $spb = SpbService::factory()->create(['assigned_staff_id' => $staff->id, 'status' => 'completed', 'agent_fee' => '1000000']);
    Livewire::actingAs($staff)->test('pages::services.spb-detail', ['spb' => $spb])
        ->set('agentFee', $amount)->call('saveAgentFee')->assertHasErrors(['agent_fee']);
    expect($spb->fresh()->agent_fee)->toEqual('1000000.00')->and($spb->histories()->count())->toBe(0);
})->with(['-1', 'abc', '1.234']);

test('clearing an agent fee sets its value to zero', function () {
    $staff = User::factory()->create();
    $spb = SpbService::factory()->create(['assigned_staff_id' => $staff->id, 'status' => 'completed', 'agent_fee' => '1000000']);

    Livewire::actingAs($staff)->test('pages::services.spb-detail', ['spb' => $spb])
        ->set('agentFee', '')->call('saveAgentFee')->assertHasNoErrors()->assertSee('0,00');

    expect($spb->fresh()->agent_fee)->toBe('0.00');
});

test('staff who are not assigned cannot change an agent fee', function () {
    $spb = SpbService::factory()->create(['agent_fee' => '1000000']);
    $other = User::factory()->create();
    Livewire::actingAs($other)->test('pages::services.spb-detail', ['spb' => $spb])
        ->assertDontSee('Simpan harga jasa')->set('agentFee', '2000000')->call('saveAgentFee')->assertForbidden();
    expect($spb->fresh()->agent_fee)->toEqual('1000000.00')->and($spb->histories()->count())->toBe(0);
});

test('admin can add a missing fee to an old SPB without producing duplicate history for unchanged amounts', function () {
    $spb = SpbService::factory()->create(['status' => 'completed', 'agent_fee' => null]);
    $page = Livewire::actingAs(User::factory()->admin()->create())->test('pages::services.spb-detail', ['spb' => $spb])
        ->set('agentFee', '500000')->call('saveAgentFee')->assertHasNoErrors();
    $page->set('agentFee', '500000.00')->call('saveAgentFee')->assertHasNoErrors();
    expect($spb->fresh()->agent_fee)->toEqual('500000.00')->and($spb->histories()->count())->toBe(1);
});
