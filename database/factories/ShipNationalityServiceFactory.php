<?php

namespace Database\Factories;

use App\Models\ShipNationalityService;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShipNationalityService> */
class ShipNationalityServiceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'service_number' => 'SN-TEST-'.fake()->unique()->uuid(),
            'vessel_id' => Vessel::factory(),
            'created_by' => User::factory(), 'assigned_staff_id' => User::factory(),
            'document_type' => 'surat_laut', 'request_type' => 'new_issue', 'priority' => 'medium', 'status' => 'draft',
        ];
    }
}
