<?php

namespace Database\Factories;

use App\Models\ShipNationalityService;
use App\Models\ShipNationalityStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShipNationalityStatusHistory> */
class ShipNationalityStatusHistoryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ship_nationality_service_id' => ShipNationalityService::factory(),
            'event' => 'Service Created', 'from_status' => null, 'to_status' => 'draft',
            'changed_by' => User::factory(),
        ];
    }
}
