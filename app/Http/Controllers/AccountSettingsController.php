<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAccountRequest;
use App\Models\User;
use App\Services\ImageOptimizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class AccountSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        return view('pages.account.settings', ['user' => $request->user()]);
    }

    public function update(UpdateAccountRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $oldPicturePath = $user->profile_picture_path;
        $newPicturePath = $request->file('profile_picture') ? app(ImageOptimizer::class)->store($request->file('profile_picture'), 'profile-pictures') : null;
        abort_if($newPicturePath === false, 500, 'Foto profil gagal disimpan. Silakan coba lagi.');

        try {
            $user->fill([
                'name' => $request->validated('fullname'),
                'username' => $request->validated('username'),
                'email' => $request->validated('email'),
                'phone' => $request->validated('phone'),
            ]);

            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }

            if ($newPicturePath) {
                $user->profile_picture_path = $newPicturePath;
            } elseif ($request->boolean('remove_picture')) {
                $user->profile_picture_path = null;
            }

            $user->save();
        } catch (Throwable $exception) {
            if ($newPicturePath) {
                Storage::disk('local')->delete($newPicturePath);
            }

            throw $exception;
        }

        if ($oldPicturePath && $oldPicturePath !== $user->profile_picture_path) {
            Storage::disk('local')->delete($oldPicturePath);
        }

        return to_route('account.settings')->with('profile_status', 'Informasi akun berhasil diperbarui.');
    }
}
