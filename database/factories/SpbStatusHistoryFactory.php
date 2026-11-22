<?php

namespace Database\Factories;

use App\Models\SpbService;
use App\Models\SpbStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SpbStatusHistory> */
class SpbStatusHistoryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'spb_service_id' => SpbService::factory(),
            'event' => 'SPB Created',
            'from_status' => null,
            'to_status' => 'draft',
            'changed_by' => User::factory(),
        ];
    }
}
