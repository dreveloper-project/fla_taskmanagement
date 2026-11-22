<?php

namespace Database\Factories;

use App\Models\BunkerService;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BunkerService> */
class BunkerServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'service_number' => 'BKR-'.fake()->unique()->numerify('########'),
            'vessel_id' => Vessel::factory(), 'created_by' => User::factory(), 'assigned_staff_id' => User::factory(),
            'supplier' => fake()->company(), 'bunker_location' => 'Pelabuhan Labuan Bajo',
            'bunker_type' => 'HSD', 'quantity' => '1500.000', 'unit' => 'liter',
            'scheduled_at' => now()->addDay(), 'priority' => 'medium', 'status' => 'draft',
        ];
    }
}
