<?php

namespace Database\Factories;

use App\Models\Vessel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vessel>
 */
class VesselFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vessel_name' => 'KM. '.fake()->unique()->company(),
            'vessel_type' => 'passenger',
            'flag' => 'Indonesia',
            'operational_status' => 'berthed',
        ];
    }
}
