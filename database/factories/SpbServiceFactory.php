<?php

namespace Database\Factories;

use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SpbService> */
class SpbServiceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'service_number' => 'SPB-TEST-'.fake()->unique()->uuid(),
            'service_type' => 'spb',
            'vessel_id' => Vessel::factory()->state(['vessel_type' => 'passenger', 'operational_status' => 'berthed']),
            'created_by' => User::factory(),
            'priority' => 'medium',
            'total_crew' => 15,
            'total_passengers' => 100,
            'departure_port' => 'Makassar',
            'destination' => 'Surabaya',
            'estimated_departure_at' => now()->addDay(),
            'estimated_port_activity_completion_at' => now()->addHours(12),
            'status' => 'draft',
            'sailing_result' => 'pending',
        ];
    }
}
