<?php

use App\Models\Client;
use App\Models\FinanceEntry;
use App\Models\SpbService;
use App\Models\User;
use App\Services\SpbPaymentService;
use App\Services\SpbWorkflow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function spbFinanceProof(): UploadedFile
{
    return UploadedFile::fake()->create('bukti.pdf', 10, 'application/pdf');
}

test('finance shows completed unpaid services and records partial and full payment', function () {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $client = Client::factory()->create();
    $spb = SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '1000000']);
    $draft = SpbService::factory()->create(['status' => 'draft', 'agent_fee' => '2000000']);
    $page = Livewire::actingAs($admin)->test('pages::dashboard.finance')
        ->assertSee($spb->service_number)->assertDontSee($draft->service_number)
        ->call('startPayment', $spb->id)->assertSet('paymentAmount', '1000000.00')
        ->set('paymentAmount', '400000')->set('paymentDate', '2026-11-01')->set('paymentProof', spbFinanceProof())
        ->call('savePayment')->assertHasNoErrors()->assertSee('600.000,00')->assertSee('Dibayar sebagian');
    $first = FinanceEntry::firstOrFail();
    expect($first->spb_service_id)->toBe($spb->id)->and($first->client_id)->toBe($client->id)->and($first->type)->toBe('income');
    Storage::disk('local')->assertExists($first->proof_path);
    $this->get(route('finance.proof', $first))->assertDownload('bukti.pdf');
    $page->call('startPayment', $spb->id)->assertSet('paymentAmount', '600000.00')
        ->set('paymentProof', spbFinanceProof())->call('savePayment')->assertHasNoErrors()
        ->call('setServiceBillTab', 'paid')->assertSee('Lunas');
    expect($spb->payments()->count())->toBe(2)->and((float) $spb->payments()->sum('amount'))->toBe(1000000.0);
    $page->call('setServiceBillTab', 'unpaid')->assertDontSee($spb->service_number);
});

test('payment requires proof and cannot exceed the outstanding fee', function () {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $spb = SpbService::factory()->create(['status' => 'completed', 'agent_fee' => '100']);
    $page = Livewire::actingAs($admin)->test('pages::dashboard.finance')->call('startPayment', $spb->id);
    $page->call('savePayment')->assertHasErrors(['paymentProof']);
    $page->set('paymentAmount', '100.01')->set('paymentProof', spbFinanceProof())->call('savePayment')->assertHasErrors(['paymentAmount']);
    expect(FinanceEntry::count())->toBe(0);
});

test('a repeated payment request cannot create a duplicate entry or proof', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $spb = SpbService::factory()->create(['status' => 'completed', 'agent_fee' => '100']);
    $token = (string) Str::uuid();
    $service = app(SpbPaymentService::class);
    $first = $service->record($spb->id, '20', '2026-11-01', spbFinanceProof(), $token);
    $second = $service->record($spb->id, '20', '2026-11-01', spbFinanceProof(), $token);
    expect($first->id)->toBe($second->id)->and($spb->payments()->count())->toBe(1)
        ->and(Storage::disk('local')->files('finance'))->toHaveCount(1);
});

test('recording is restricted to administrators and completed services', function () {
    Storage::fake('local');
    $spb = SpbService::factory()->create(['status' => 'draft', 'agent_fee' => '100']);
    $this->actingAs(User::factory()->admin()->create());
    expect(fn () => app(SpbPaymentService::class)->record($spb->id, '100', '2026-11-01', spbFinanceProof(), (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(FinanceEntry::count())->toBe(0)->and(Storage::disk('local')->files('finance'))->toBeEmpty();
    $this->actingAs(User::factory()->create())->get(route('finance.index'))->assertForbidden();
});

test('missing fees count as zero and overpayments remain visible', function () {
    $admin = User::factory()->admin()->create();
    $missing = SpbService::factory()->create(['status' => 'completed', 'agent_fee' => null]);
    $paid = SpbService::factory()->create(['status' => 'completed', 'agent_fee' => '10']);
    FinanceEntry::factory()->create(['spb_service_id' => $paid->id, 'type' => 'income', 'amount' => '20']);
    Livewire::actingAs($admin)->test('pages::dashboard.finance')->assertDontSee($missing->service_number)
        ->call('setServiceBillTab', 'paid')->assertSee($missing->service_number)->assertSee($paid->service_number)->assertSee('Kelebihan bayar');
});

test('changes to a completed SPB fee update the outstanding balance without changing payments', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $spb = SpbService::factory()->create(['status' => 'completed', 'agent_fee' => '100']);
    FinanceEntry::factory()->create(['spb_service_id' => $spb->id, 'type' => 'income', 'amount' => '100']);
    $workflow = app(SpbWorkflow::class);
    $workflow->updateAgentFee($spb, '150');
    Livewire::test('pages::dashboard.finance')->assertSee($spb->service_number)->assertSee('Dibayar sebagian')->assertSee('50,00');
    $workflow->updateAgentFee($spb, '80');
    Livewire::test('pages::dashboard.finance')->call('setServiceBillTab', 'paid')->assertSee('Kelebihan bayar')->assertSee('20,00');
    expect($spb->payments()->count())->toBe(1);
});
