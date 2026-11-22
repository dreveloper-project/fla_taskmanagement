<?php

namespace Database\Factories;

use App\Models\SijilService;
use App\Models\SijilServiceCrew;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SijilServiceCrew> */
class SijilServiceCrewFactory extends Factory
{
    public function definition(): array
    {
        return ['sijil_service_id' => SijilService::factory(), 'full_name' => fake()->name(), 'rank' => 'AB', 'action_type' => 'sign_on'];
    }
}
