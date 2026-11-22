<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function view(User $user, Task $task): bool
    {
        return $user->isActive() && ($user->isAdmin() || ($user->isStaff() && $task->assigned_to === $user->id));
    }

    public function create(User $user): bool
    {
        return $user->isActive() && $user->isAdmin();
    }

    public function update(User $user, Task $task): bool
    {
        return $this->create($user);
    }

    public function work(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }
}
