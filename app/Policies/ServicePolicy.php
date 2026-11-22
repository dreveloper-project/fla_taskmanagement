<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ServicePolicy
{
    public function delete(User $user, Model $service): bool
    {
        return $this->work($user, $service) && $service->getAttribute('status') !== 'completed';
    }

    public function work(User $user, Model $service): bool
    {
        return $service->exists && $user->isActive()
            && ($user->isAdmin() || ($user->isStaff() && $service->getAttribute('assigned_staff_id') === $user->id));
    }
}
