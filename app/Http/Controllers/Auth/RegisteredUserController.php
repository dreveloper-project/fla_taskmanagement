<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\ImageOptimizer;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class RegisteredUserController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if (User::query()->exists()) {
            return to_route('login');
        }

        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $profilePicturePath = $request->file('profile_picture') ? app(ImageOptimizer::class)->store($request->file('profile_picture'), 'profile-pictures') : null;
        abort_if($profilePicturePath === false, 500, 'Foto profil gagal disimpan. Silakan coba lagi.');

        try {
            $store = Cache::store('database')->getStore();
            if (! $store instanceof LockProvider) {
                throw new \RuntimeException('Database cache must support atomic registration locks.');
            }
            $user = $store->lock('initial-admin-registration', 120)->block(5, function () use ($request, $profilePicturePath): User {
                return DB::transaction(function () use ($request, $profilePicturePath): User {
                    abort_if(User::query()->exists(), 403, 'Registrasi awal sudah ditutup.');
                    $user = new User([
                        'name' => $request->validated('fullname'),
                        'username' => $request->validated('username'),
                        'email' => $request->validated('email'),
                        'phone' => $request->validated('phone'),
                        'password' => $request->validated('password'),
                        'profile_picture_path' => $profilePicturePath,
                    ]);
                    $user->role = 'superadmin';
                    $user->status = 'active';
                    $user->save();

                    return $user;
                });
            });
        } catch (Throwable $exception) {
            if ($profilePicturePath) {
                Storage::disk('local')->delete($profilePicturePath);
            }

            if ($exception instanceof LockTimeoutException) {
                throw ValidationException::withMessages(['username' => 'Registrasi awal sedang diproses. Silakan coba kembali.']);
            }
            throw $exception;
        }

        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();

        return to_route('home')->with('auth_status', 'Akun berhasil dibuat. Selamat datang.');
    }
}
