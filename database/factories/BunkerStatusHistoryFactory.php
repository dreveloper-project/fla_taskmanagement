<?php

namespace Database\Factories;

use App\Models\BunkerService;
use App\Models\BunkerStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BunkerStatusHistory> */
class BunkerStatusHistoryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'bunker_service_id' => BunkerService::factory(),
            'event' => 'Service Created', 'from_status' => null, 'to_status' => 'draft',
            'changed_by' => User::factory(),
        ];
    }
}
