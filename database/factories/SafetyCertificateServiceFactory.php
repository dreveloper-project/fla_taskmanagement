<?php

namespace Database\Factories;

use App\Models\SafetyCertificateService;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SafetyCertificateService> */
class SafetyCertificateServiceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'service_number' => 'SKK-TEST-'.fake()->unique()->uuid(),
            'vessel_id' => Vessel::factory()->state(['vessel_type' => 'passenger']),
            'created_by' => User::factory(), 'assigned_staff_id' => User::factory(),
            'request_type' => 'new_issue', 'priority' => 'medium', 'status' => 'draft',
        ];
    }
}
