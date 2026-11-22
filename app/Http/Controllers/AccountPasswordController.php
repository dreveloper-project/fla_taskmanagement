<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePasswordRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class AccountPasswordController extends Controller
{
    public function update(UpdatePasswordRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->password = $request->validated('password');
        $user->setRememberToken(Str::random(60));
        $user->save();

        $request->session()->regenerate();

        return to_route('account.settings')->with('password_status', 'Password berhasil diperbarui. Gunakan password baru saat login berikutnya.');
    }
}
