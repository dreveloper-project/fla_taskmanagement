<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $throttleKey = 'login:'.hash('sha256', $request->validated('username').'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'username' => 'Terlalu banyak percobaan login. Coba lagi dalam '.RateLimiter::availableIn($throttleKey).' detik.',
            ]);
        }

        $inactive = false;
        if (! Auth::attemptWhen([
            'username' => $request->validated('username'),
            'password' => $request->validated('password'),
            'role' => ['superadmin', 'admin', 'staff', 'finance'],
        ], function (User $user) use (&$inactive): bool {
            $inactive = ! $user->isActive();

            return ! $inactive;
        }, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'username' => $inactive ? 'Akun tidak aktif. Hubungi Admin.' : 'Username atau password salah.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();
        $request->session()->put('show_document_expiry_summary', true);

        return redirect()->intended(route('home'))->with('auth_status', 'Login berhasil. Selamat datang kembali.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login')->with('auth_status', 'Anda berhasil logout.');
    }
}
