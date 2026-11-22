<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

function staffRegistrationData(array $overrides = []): array
{
    return array_replace([
        'fullname' => 'Andi Pratama',
        'username' => 'andi.pratama',
        'email' => 'andi@example.com',
        'phone' => '+6281234567890',
        'password' => 'SecretPassword123!',
        'password_confirmation' => 'SecretPassword123!',
    ], $overrides);
}

test('guests can open authentication forms', function (string $route, string $heading) {
    $this->get(route($route))->assertSee($heading);
})->with([
    ['login', 'Selamat datang kembali'],
    ['register', 'Buat Superadmin pertama'],
]);

test('guests are redirected from staff pages', function (string $route) {
    $this->get(route($route))->assertRedirectToRoute('login');
})->with(['home', 'dashboard.vessel', 'dashboard.task-monitoring', 'profile.picture']);

test('registers the first superadmin with normalized credentials and a private profile picture', function () {
    Storage::fake('local');
    Event::fake([Registered::class]);
    $picture = UploadedFile::fake()->image('portrait.jpg', 120, 120);
    $this->withSession(['_token' => 'old-token']);
    $oldSessionId = session()->getId();

    $this->post(route('register.store'), staffRegistrationData([
        'username' => ' Andi.Pratama ',
        'email' => 'ANDI@example.com',
        'profile_picture' => $picture,
        'role' => 'admin',
        'email_verified_at' => now()->toDateTimeString(),
    ]))->assertRedirectToRoute('home');

    $user = User::sole();
    $this->assertDatabaseHas('users', [
        'name' => 'Andi Pratama',
        'username' => 'andi.pratama',
        'email' => 'andi@example.com',
        'phone' => '+6281234567890',
        'role' => 'superadmin',
        'email_verified_at' => null,
    ]);
    expect(Hash::check('SecretPassword123!', $user->password))->toBeTrue();
    expect(session()->getId())->not->toBe($oldSessionId);
    Storage::disk('local')->assertExists($user->profile_picture_path);
    Event::assertDispatched(Registered::class, fn (Registered $event): bool => $event->user->is($user));
    $this->assertAuthenticatedAs($user);
    $this->get(route('home'))->assertSee('Andi Pratama')->assertSee(route('profile.picture'));
});

test('registration allows omitting the profile picture', function () {
    $this->post(route('register.store'), staffRegistrationData())
        ->assertRedirectToRoute('home')->assertSessionHas('auth_status', 'Akun berhasil dibuat. Selamat datang.');

    $this->assertDatabaseHas('users', ['username' => 'andi.pratama', 'profile_picture_path' => null, 'role' => 'superadmin']);
    $this->assertAuthenticated();
});

test('registration requires staff identity and credentials', function () {
    $this->post(route('register.store'), [])->assertSessionHasErrors([
        'fullname' => 'Nama lengkap wajib diisi.',
        'username' => 'Username wajib diisi.',
        'email' => 'Email wajib diisi.',
        'password' => 'Password wajib diisi.',
    ]);

    $this->assertDatabaseCount('users', 0);
    $this->assertGuest();
});

test('registration rejects invalid identity and password fields', function (array $overrides, string $field, string $message) {
    $this->post(route('register.store'), staffRegistrationData($overrides))
        ->assertSessionHasErrors([$field => $message]);

    $this->assertDatabaseCount('users', 0);
    $this->assertGuest();
})->with([
    'short username' => [['username' => 'ab'], 'username', 'Username minimal 3 karakter.'],
    'username characters' => [['username' => 'andi space'], 'username', 'Username hanya boleh berisi huruf, angka, titik, dan garis bawah.'],
    'long username' => [['username' => str_repeat('a', 51)], 'username', 'Username melebihi batas yang diizinkan.'],
    'long fullname' => [['fullname' => str_repeat('a', 256)], 'fullname', 'Nama lengkap melebihi batas yang diizinkan.'],
    'invalid email' => [['email' => 'invalid-email'], 'email', 'Masukkan alamat email yang valid.'],
    'invalid phone' => [['phone' => 'not-a-phone'], 'phone', 'Masukkan nomor telepon yang valid.'],
    'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password', 'Password minimal 8 karakter.'],
    'mismatched confirmation' => [['password_confirmation' => 'different'], 'password', 'Konfirmasi password tidak cocok.'],
]);

test('registration rejects existing usernames and emails regardless of input casing', function () {
    User::factory()->create(['username' => 'andi.pratama', 'email' => 'andi@example.com']);

    $this->post(route('register.store'), staffRegistrationData([
        'username' => 'ANDI.PRATAMA', 'email' => 'ANDI@EXAMPLE.COM',
    ]))->assertForbidden();

    $this->assertDatabaseCount('users', 1);
    $this->assertGuest();
});

test('registration rejects unsafe or excessive profile pictures', function (Closure $picture, string $message) {
    Storage::fake('local');

    $this->post(route('register.store'), staffRegistrationData(['profile_picture' => $picture()]))
        ->assertSessionHasErrors(['profile_picture' => $message]);

    $this->assertDatabaseCount('users', 0);
    Storage::disk('local')->assertDirectoryEmpty('profile-pictures');
})->with([
    'text disguised as image' => [fn () => UploadedFile::fake()->createWithContent('portrait.jpg', 'not an image')->mimeType('text/plain'), 'Foto profil harus berupa gambar.'],
    'svg' => [fn () => UploadedFile::fake()->createWithContent('portrait.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'Foto profil harus berupa gambar.'],
    'oversized' => [fn () => UploadedFile::fake()->image('portrait.jpg')->size(4097), 'Ukuran foto profil maksimal 4 MB.'],
    'excessive dimensions' => [fn () => UploadedFile::fake()->image('portrait.jpg', 4097, 1), 'Dimensi foto profil maksimal 4096 × 4096 piksel.'],
]);

test('staff can login with username and return to their intended page', function () {
    $user = User::factory()->create(['username' => 'andi.pratama']);
    $this->get(route('dashboard.vessel'))->assertRedirectToRoute('login');
    $oldSessionId = session()->getId();

    $this->post(route('login.store'), [
        'username' => ' ANDI.PRATAMA ',
        'password' => 'password',
        'remember' => '1',
    ])->assertRedirectToRoute('dashboard.vessel')->assertSessionHas('auth_status', 'Login berhasil. Selamat datang kembali.');

    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($oldSessionId);
    expect($user->fresh()->remember_token)->not->toBeNull();
});

test('login rejects incorrect credentials and non staff accounts', function (string $username, string $password, string $role) {
    User::factory()->create(['username' => 'andi.pratama', 'email' => 'andi@example.com', 'role' => $role]);

    $this->post(route('login.store'), compact('username', 'password'))
        ->assertSessionHasErrors(['username' => 'Username atau password salah.']);

    $this->assertGuest();
})->with([
    'wrong password' => ['andi.pratama', 'wrong-password', 'staff'],
    'email is not username' => ['andi@example.com', 'password', 'staff'],
    'unknown user' => ['nobody', 'password', 'staff'],
    'non staff user' => ['andi.pratama', 'password', 'external'],
]);

test('login requires username and password', function () {
    $this->post(route('login.store'), [])->assertSessionHasErrors([
        'username' => 'Username wajib diisi.',
        'password' => 'Password wajib diisi.',
    ]);

    $this->assertGuest();
});

test('login throttles failed attempts and allows retry after one minute', function () {
    $this->freezeTime();
    $user = User::factory()->create(['username' => 'andi.pratama']);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->post(route('login.store'), ['username' => 'andi.pratama', 'password' => 'wrong'])
            ->assertSessionHasErrors(['username' => 'Username atau password salah.']);
    }

    $this->post(route('login.store'), ['username' => 'ANDI.PRATAMA', 'password' => 'password'])
        ->assertSessionHasErrors(['username' => 'Terlalu banyak percobaan login. Coba lagi dalam 60 detik.']);
    $this->assertGuest();

    $this->travel(61)->seconds();

    $this->post(route('login.store'), ['username' => 'andi.pratama', 'password' => 'password'])
        ->assertRedirectToRoute('home');
    $this->assertAuthenticatedAs($user);
});

test('authenticated staff can access dashboard pages', function (string $route) {
    $user = User::factory()->create(['name' => 'Andi Pratama']);

    $this->actingAs($user)->get(route($route))->assertSee('Andi Pratama')->assertSee('Keluar');
})->with(['home', 'dashboard.vessel', 'dashboard.task-monitoring']);

test('the system gate grants access to admin and staff', function (string $role, bool $allowed) {
    $user = User::factory()->make(['role' => $role]);

    expect(Gate::forUser($user)->allows('access-system'))->toBe($allowed);
})->with([['staff', true], ['external', false], ['admin', true]]);

test('authenticated non staff users cannot access protected pages', function (string $route) {
    $user = User::factory()->create(['role' => 'external']);

    $this->actingAs($user)->get(route($route))->assertForbidden();
})->with(['home', 'dashboard.vessel', 'dashboard.task-monitoring', 'profile.picture']);

test('authenticated users are redirected from guest forms', function (string $route) {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route($route))->assertRedirectToRoute('home');
})->with(['login', 'register']);

test('staff can log out and lose access to the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['private-data' => 'value', '_token' => 'old-token']);

    $this->post(route('logout'))->assertRedirectToRoute('login')
        ->assertSessionMissing('private-data')->assertSessionHas('auth_status', 'Anda berhasil logout.');

    $this->assertGuest();
    expect(session()->token())->not->toBe('old-token');
    $this->get(route('home'))->assertRedirectToRoute('login');
});

test('serves only the current staff members private picture', function () {
    Storage::fake('local');
    Storage::disk('local')->put('profile-pictures/own.jpg', 'own picture');
    Storage::disk('local')->put('profile-pictures/other.jpg', 'other picture');
    $user = User::factory()->create(['profile_picture_path' => 'profile-pictures/own.jpg']);

    $this->actingAs($user)->get(route('profile.picture', ['path' => 'profile-pictures/other.jpg']))
        ->assertStreamedContent('own picture')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    Storage::disk('local')->assertExists(['profile-pictures/own.jpg', 'profile-pictures/other.jpg']);
});

test('returns not found when a staff picture is absent', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.picture'))->assertNotFound();
});

test('escapes staff names in the dashboard', function () {
    $user = User::factory()->create(['name' => '<script>alert(1)</script>']);

    $this->actingAs($user)->get(route('home'))
        ->assertSee('<script>alert(1)</script>')
        ->assertDontSee('<script>alert(1)</script>', false);
});
