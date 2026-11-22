<?php

namespace Database\Factories;

use App\Models\SijilService;
use App\Models\SijilStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SijilStatusHistory> */
class SijilStatusHistoryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sijil_service_id' => SijilService::factory(),
            'event' => 'Service Created', 'from_status' => null, 'to_status' => 'draft',
            'changed_by' => User::factory(),
        ];
    }
}
