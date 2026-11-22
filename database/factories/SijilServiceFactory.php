<?php

namespace Database\Factories;

use App\Models\SijilService;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SijilService> */
class SijilServiceFactory extends Factory
{
    public function definition(): array
    {
        return ['service_number' => 'SJL-'.fake()->unique()->numerify('########'), 'vessel_id' => Vessel::factory(), 'created_by' => User::factory(), 'assigned_staff_id' => User::factory(), 'process_type' => 'sign_on', 'priority' => 'medium', 'status' => 'draft'];
    }
}
