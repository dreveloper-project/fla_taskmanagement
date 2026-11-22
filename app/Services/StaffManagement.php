<?php

namespace App\Services;

use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class StaffManagement
{
    /** @param array<string, mixed> $input */
    public function save(?User $existing, array $input, ?UploadedFile $picture = null): User
    {
        Gate::authorize('access-admin');
        abort_if($existing && $existing->isSuperadmin(), 403);
        $input['role'] ??= $existing?->role ?? 'staff';
        foreach (['username', 'email'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = strtolower(trim($input[$field]));
            }
        }
        $request = new RegisterRequest;
        $rules = $request->rules();
        $rules['username'][5] = Rule::unique('users', 'username')->ignore($existing?->id);
        $rules['email'][4] = Rule::unique('users', 'email')->ignore($existing?->id);
        $rules['role'] = ['required', Rule::in(['admin', 'staff', 'finance'])];
        if ($existing) {
            unset($rules['password']);
            $rules['status'] = ['required', Rule::in(['active', 'inactive'])];
        }
        $data = Validator::make(array_replace($input, ['profile_picture' => $picture]), $rules, $request->messages(), $request->attributes())->validate();
        $path = $picture ? app(ImageOptimizer::class)->store($picture, 'profile-pictures') : null;
        abort_if($path === false, 500, 'Foto profil gagal disimpan.');
        $oldPath = null;
        try {
            $user = DB::transaction(function () use ($existing, $data, $path, &$oldPath): User {
                $user = $existing ? User::query()->lockForUpdate()->findOrFail($existing->id) : new User;
                abort_if($user->exists && $user->isSuperadmin(), 403);
                $before = $user->only(['name', 'username', 'email', 'phone', 'status', 'role']);
                $oldPath = $user->profile_picture_path;
                $user->fill(['name' => $data['fullname'], 'username' => $data['username'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null]);
                if ($user->isDirty('email')) {
                    $user->email_verified_at = null;
                }
                if (! $user->exists) {
                    $user->password = $data['password'];
                }
                if ($path) {
                    $user->profile_picture_path = $path;
                }
                $user->role = $data['role'];
                $user->status = $existing ? $data['status'] : 'active';
                $user->save();
                $this->history($user, $existing ? 'staff_updated' : 'staff_created', ['before' => $before, 'after' => $user->only(array_keys($before))]);
                if ($existing && $before['status'] !== $user->status) {
                    $this->history($user, $user->isActive() ? 'staff_activated' : 'staff_deactivated');
                }

                return $user;
            });
        } catch (Throwable $error) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
        if ($path && $oldPath && $oldPath !== $path) {
            Storage::disk('local')->delete($oldPath);
        }

        return $user;
    }

    public function setStatus(User $staff, string $status): void
    {
        Gate::authorize('access-admin');
        Validator::make(['status' => $status], ['status' => ['required', Rule::in(['active', 'inactive'])]])->validate();
        DB::transaction(function () use ($staff, $status): void {
            $staff = User::query()->lockForUpdate()->findOrFail($staff->id);
            abort_if($staff->isSuperadmin(), 403);
            if ($staff->status === $status) {
                return;
            }
            $staff->status = $status;
            $staff->save();
            $this->history($staff, $status === 'active' ? 'staff_activated' : 'staff_deactivated');
        });
    }

    /** @return array{services: int} */
    public function workload(User $staff): array
    {
        Gate::authorize('access-admin');
        $services = 0;
        foreach (TaskServiceRegistry::TYPES as $class) {
            $services += $class::query()->where('assigned_staff_id', $staff->id)->whereIn('status', $class::ACTIVE)->count();
        }

        return ['services' => $services];
    }

    /** @param array<string, mixed> $metadata */
    private function history(User $staff, string $event, array $metadata = []): void
    {
        DB::table('staff_histories')->insert(['user_id' => $staff->id, 'changed_by' => auth()->id(), 'event' => $event,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
