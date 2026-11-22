<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Task> */
class TaskFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['title' => fake()->sentence(4), 'created_by' => User::factory(), 'priority' => 'medium', 'status' => 'pending', 'progress' => 0];
    }
}
