<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

function accountProfileData(User $user, array $overrides = []): array
{
    return array_replace([
        'fullname' => $user->name,
        'username' => $user->username,
        'email' => $user->email,
        'phone' => $user->phone,
    ], $overrides);
}

test('account endpoints require authentication', function (string $method, string $route) {
    $this->{$method}(route($route))->assertRedirectToRoute('login');
})->with([
    ['get', 'account.settings'],
    ['patch', 'account.update'],
    ['put', 'account.password.update'],
]);

test('account endpoints reject non staff users', function (string $method, string $route) {
    $user = User::factory()->create(['role' => 'external']);

    $this->actingAs($user)->{$method}(route($route))->assertForbidden();
})->with([
    ['get', 'account.settings'],
    ['patch', 'account.update'],
    ['put', 'account.password.update'],
]);

test('profile links open settings with the current staff details', function () {
    $user = User::factory()->create(['name' => 'Mateo Rodriguez', 'username' => 'mateo.rodriguez']);

    $this->actingAs($user)->get(route('home'))->assertSee('href="'.route('account.settings').'"', false);
    $this->get(route('account.settings'))->assertSee('Pengaturan akun')
        ->assertSee('value="Mateo Rodriguez"', false)
        ->assertSee('value="mateo.rodriguez"', false)
        ->assertSee($user->email)
        ->assertSee($user->phone)
        ->assertDontSee($user->password);
});

test('updates only the authenticated account and normalizes its login details', function () {
    $user = User::factory()->create();
    $other = User::factory()->create(['name' => 'Other Staff']);
    $oldPassword = $user->password;

    $this->actingAs($user)->patch(route('account.update'), accountProfileData($user, [
        'fullname' => 'Mateo Updated',
        'username' => ' MATEO.UPDATED ',
        'email' => ' MATEO.UPDATED@EXAMPLE.COM ',
        'phone' => '+6281234567890',
        'id' => $other->id,
        'user_id' => $other->id,
        'role' => 'admin',
        'password' => 'InjectedPassword123!',
        'profile_picture_path' => 'private/other.jpg',
    ]))->assertRedirectToRoute('account.settings')
        ->assertSessionHas('profile_status', 'Informasi akun berhasil diperbarui.');

    $this->assertDatabaseHas('users', [
        'id' => $user->id, 'name' => 'Mateo Updated', 'username' => 'mateo.updated',
        'email' => 'mateo.updated@example.com', 'phone' => '+6281234567890',
        'role' => 'staff', 'password' => $oldPassword, 'profile_picture_path' => null, 'email_verified_at' => null,
    ]);
    $this->assertDatabaseHas('users', ['id' => $other->id, 'name' => 'Other Staff', 'username' => $other->username]);
    $this->get(route('account.settings'))->assertSee('Mateo Updated')->assertSee('mateo.updated');
    $this->post(route('logout'));
    $this->post(route('login.store'), ['username' => 'mateo.updated', 'password' => 'password'])
        ->assertRedirectToRoute('home');
    $this->assertAuthenticatedAs($user);
});

test('allows keeping the current username and email without losing verification', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('account.update'), accountProfileData($user))
        ->assertSessionHasNoErrors()->assertRedirectToRoute('account.settings');

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

test('rejects usernames and emails belonging to another account', function () {
    $user = User::factory()->create();
    $other = User::factory()->create(['username' => 'existing.staff', 'email' => 'existing@example.com']);

    $this->actingAs($user)->patch(route('account.update'), accountProfileData($user, [
        'username' => 'EXISTING.STAFF',
        'email' => 'EXISTING@EXAMPLE.COM',
        'id' => $other->id,
    ]))->assertSessionHasErrors(['username' => 'Username sudah digunakan.', 'email' => 'Email sudah digunakan.']);

    $this->assertDatabaseHas('users', ['id' => $user->id, 'username' => $user->username, 'email' => $user->email]);
});

test('requires profile identity fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('account.update'), [])->assertSessionHasErrors([
        'fullname' => 'Nama lengkap wajib diisi.',
        'username' => 'Username wajib diisi.',
        'email' => 'Email wajib diisi.',
        'phone' => 'Nomor telepon wajib diisi.',
    ]);

    $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => $user->name]);
});

test('rejects invalid profile details and preserves submitted fields for correction', function (string $field, string $value, string $message) {
    $user = User::factory()->create();

    $this->actingAs($user)->from(route('account.settings'))->followingRedirects()
        ->patch(route('account.update'), accountProfileData($user, [$field => $value]))
        ->assertSee($message)->assertSee($value);
    $this->assertDatabaseHas('users', ['id' => $user->id, 'username' => $user->username, 'email' => $user->email]);
})->with([
    ['username', 'ab', 'Username minimal 3 karakter.'],
    ['username', 'bad username', 'Username hanya boleh berisi huruf, angka, titik, dan garis bawah.'],
    ['email', 'invalid-email', 'Masukkan alamat email yang valid.'],
    ['phone', 'invalid-phone', 'Masukkan nomor telepon yang valid.'],
]);

test('replaces a private profile picture and deletes the old file', function () {
    Storage::fake('local');
    Storage::disk('local')->put('profile-pictures/old.jpg', 'old');
    $user = User::factory()->create(['profile_picture_path' => 'profile-pictures/old.jpg']);

    $this->actingAs($user)->patch(route('account.update'), accountProfileData($user, [
        'profile_picture' => UploadedFile::fake()->image('new.jpg'),
    ]))->assertRedirectToRoute('account.settings')->assertSessionHasNoErrors();

    $newPath = $user->fresh()->profile_picture_path;
    Storage::disk('local')->assertExists($newPath);
    Storage::disk('local')->assertMissing('profile-pictures/old.jpg');
    $this->get(route('profile.picture'))->assertStreamedContent(Storage::disk('local')->get($newPath));
});

test('keeps the existing photo when no photo change is requested', function () {
    Storage::fake('local');
    Storage::disk('local')->put('profile-pictures/keep.jpg', 'keep');
    $user = User::factory()->create(['profile_picture_path' => 'profile-pictures/keep.jpg']);

    $this->actingAs($user)->patch(route('account.update'), accountProfileData($user))
        ->assertRedirectToRoute('account.settings');

    $this->assertDatabaseHas('users', ['id' => $user->id, 'profile_picture_path' => 'profile-pictures/keep.jpg']);
    Storage::disk('local')->assertExists('profile-pictures/keep.jpg');
});

test('removes the profile picture and returns to initials', function () {
    Storage::fake('local');
    Storage::disk('local')->put('profile-pictures/remove.jpg', 'remove');
    $user = User::factory()->create(['name' => 'Mateo Rodriguez', 'profile_picture_path' => 'profile-pictures/remove.jpg']);

    $this->actingAs($user)->patch(route('account.update'), accountProfileData($user, ['remove_picture' => '1']))
        ->assertRedirectToRoute('account.settings');

    $this->assertDatabaseHas('users', ['id' => $user->id, 'profile_picture_path' => null]);
    Storage::disk('local')->assertMissing('profile-pictures/remove.jpg');
    $this->get(route('account.settings'))->assertSee('MR')->assertDontSee('src="'.route('profile.picture').'"', false);
});

test('rejects invalid photos without changing the existing profile or picture', function (Closure $picture, string $message) {
    Storage::fake('local');
    Storage::disk('local')->put('profile-pictures/keep.jpg', 'keep');
    $user = User::factory()->create(['profile_picture_path' => 'profile-pictures/keep.jpg']);

    $this->actingAs($user)->patch(route('account.update'), accountProfileData($user, [
        'fullname' => 'Unsaved Name', 'profile_picture' => $picture(),
    ]))->assertSessionHasErrors(['profile_picture' => $message]);

    $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => $user->name, 'profile_picture_path' => 'profile-pictures/keep.jpg']);
    Storage::disk('local')->assertExists('profile-pictures/keep.jpg');
    Storage::disk('local')->assertCount('profile-pictures', 1);
})->with([
    'not an image' => [fn () => UploadedFile::fake()->create('photo.jpg', 1, 'text/plain'), 'Foto profil harus berupa gambar.'],
    'svg' => [fn () => UploadedFile::fake()->createWithContent('photo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'), 'Foto profil harus berupa gambar.'],
    'too large' => [fn () => UploadedFile::fake()->image('photo.jpg')->size(4097), 'Ukuran foto profil maksimal 4 MB.'],
    'too wide' => [fn () => UploadedFile::fake()->image('photo.jpg', 4097, 1), 'Dimensi foto profil maksimal 4096 × 4096 piksel.'],
]);

test('rejects simultaneous photo replacement and removal', function () {
    Storage::fake('local');
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('account.update'), accountProfileData($user, [
        'remove_picture' => true, 'profile_picture' => UploadedFile::fake()->image('photo.jpg'),
    ]))->assertSessionHasErrors(['profile_picture' => 'Pilih foto baru atau hapus foto saat ini, bukan keduanya.']);

    Storage::disk('local')->assertDirectoryEmpty('profile-pictures');
});

test('changes the password after verifying the current password', function () {
    $user = User::factory()->create(['username' => 'mateo.rodriguez']);
    $oldRememberToken = $user->remember_token;
    $this->actingAs($user)->withSession(['_token' => 'old-token']);
    $oldSessionId = session()->getId();

    $this->put(route('account.password.update'), [
        'current_password' => 'password',
        'password' => 'NewSecretPassword123!',
        'password_confirmation' => 'NewSecretPassword123!',
    ])->assertRedirectToRoute('account.settings')->assertSessionHas('password_status');

    expect(Hash::check('NewSecretPassword123!', $user->fresh()->password))->toBeTrue();
    expect($user->fresh()->remember_token)->not->toBe($oldRememberToken);
    expect(session()->getId())->not->toBe($oldSessionId);
    $this->assertAuthenticatedAs($user);
    $this->post(route('logout'));
    $this->post(route('login.store'), ['username' => $user->username, 'password' => 'password'])
        ->assertSessionHasErrors('username');
    $this->post(route('login.store'), ['username' => $user->username, 'password' => 'NewSecretPassword123!'])
        ->assertRedirectToRoute('home');
    $this->assertAuthenticatedAs($user);
});

test('rejects invalid password changes without altering the password', function (array $overrides, string $field, string $message) {
    $user = User::factory()->create();
    $oldPassword = $user->password;

    $this->actingAs($user)->from(route('account.settings'))->followingRedirects()->put(route('account.password.update'), array_replace([
        'current_password' => 'password',
        'password' => 'NewSecretPassword123!',
        'password_confirmation' => 'NewSecretPassword123!',
    ], $overrides))->assertSee($message)->assertDontSee('value="NewSecretPassword123!"', false);

    $this->assertDatabaseHas('users', ['id' => $user->id, 'password' => $oldPassword]);
})->with([
    [['current_password' => 'wrong-password'], 'current_password', 'Password saat ini tidak sesuai.'],
    [['current_password' => ''], 'current_password', 'Password saat ini wajib diisi.'],
    [['password' => '', 'password_confirmation' => ''], 'password', 'Password baru wajib diisi.'],
    [['password' => 'short', 'password_confirmation' => 'short'], 'password', 'Password baru minimal 8 karakter.'],
    [['password_confirmation' => 'mismatch'], 'password', 'Konfirmasi password tidak cocok.'],
    [['password' => 'password', 'password_confirmation' => 'password'], 'password', 'Password baru harus berbeda dari password saat ini.'],
]);

test('rate limits password change attempts', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $this->actingAs($user);

    for ($attempt = 0; $attempt < 6; $attempt++) {
        $this->put(route('account.password.update'), [])->assertSessionHasErrors('current_password');
    }

    $this->put(route('account.password.update'), [])->assertTooManyRequests();
    $this->assertDatabaseHas('users', ['id' => $user->id, 'password' => $user->password]);
});

test('escapes profile information in settings', function () {
    $user = User::factory()->create(['name' => '<script>alert(1)</script>']);

    $this->actingAs($user)->get(route('account.settings'))
        ->assertSee('<script>alert(1)</script>')
        ->assertDontSee('<script>alert(1)</script>', false);
});
