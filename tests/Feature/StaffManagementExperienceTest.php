<?php

use App\Models\SpbService;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

test('account filters work together and can be reset', function () {
    User::factory()->create(['name' => 'Rina Finance', 'role' => 'finance', 'status' => 'active']);
    User::factory()->create(['name' => 'Rina Staff', 'role' => 'staff', 'status' => 'inactive']);
    Livewire::test('pages::dashboard.staff-management')
        ->assertSee('Daftar akun')->assertSee('Rina Finance')->assertSee('Rina Staff')
        ->set('search', 'Rina')->set('role', 'finance')->set('status', 'active')
        ->assertSee('Rina Finance')->assertDontSee('Rina Staff')
        ->call('resetFilters')->assertSet('search', '')->assertSet('role', '')->assertSet('status', '')
        ->assertSee('Rina Finance')->assertSee('Rina Staff');
});

test('an administrator can create and edit an account from the redesigned form', function () {
    $page = Livewire::test('pages::dashboard.staff-management')->call('create')
        ->assertSee('Tambah akun')->set('form.fullname', 'Akun Baru')
        ->set('form.username', 'akun.baru')->set('form.email', 'akun.baru@example.test')
        ->set('form.password', 'Password123!')->set('form.password_confirmation', 'Password123!')
        ->call('save')->assertHasNoErrors()->assertSee('Akun Baru');
    $account = User::where('email', 'akun.baru@example.test')->sole();
    $page->call('edit', $account->id)->set('form.fullname', 'Nama Diperbarui')
        ->call('save')->assertHasNoErrors()->assertSee('Nama Diperbarui');
    expect($account->fresh()->name)->toBe('Nama Diperbarui');
});

test('active service details can be opened and closed', function () {
    $staff = User::factory()->create(['name' => 'Staf Layanan', 'role' => 'staff']);
    SpbService::factory()->create(['assigned_staff_id' => $staff->id, 'status' => 'draft', 'service_number' => 'SPB-STAFF']);
    Livewire::test('pages::dashboard.staff-management')
        ->call('viewWorkload', $staff->id)->assertSee('Layanan aktif · Staf Layanan')->assertSee('SPB-STAFF')
        ->call('closeWorkload')->assertSet('workloadId', null)->assertDontSee('SPB-STAFF');
});
