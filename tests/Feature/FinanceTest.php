<?php

use App\Models\FinanceEntry;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;

test('finance does not expose a manual transaction action', function () {
    $admin = User::factory()->admin()->create();
    $page = Livewire::actingAs($admin)->test('pages::dashboard.finance')->assertDontSee('Catat transaksi');

    expect(fn () => $page->call('save'))->toThrow(MethodNotFoundException::class);
    expect(FinanceEntry::count())->toBe(0);
});

test('historical transaction proofs remain accessible only to admins', function () {
    Storage::fake('local');
    Storage::disk('local')->put('finance/old-proof.pdf', 'old proof');
    $admin = User::factory()->admin()->create();
    $entry = FinanceEntry::factory()->create(['description' => 'Transaksi sebelumnya', 'proof_path' => 'finance/old-proof.pdf', 'proof_filename' => 'bukti-lama.pdf']);
    Livewire::actingAs($admin)->test('pages::dashboard.finance')->assertSee('Transaksi sebelumnya');
    $this->actingAs($admin)->get(route('finance.proof', $entry))->assertDownload('bukti-lama.pdf');
    $staff = User::factory()->create();
    $this->actingAs($staff)->get(route('finance.index'))->assertForbidden();
    $this->get(route('finance.proof', $entry))->assertForbidden();
});
