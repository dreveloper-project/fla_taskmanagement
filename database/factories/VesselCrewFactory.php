<?php

namespace Database\Factories;

use App\Models\Crew;
use App\Models\SijilService;
use App\Models\Vessel;
use App\Models\VesselCrew;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VesselCrew> */
class VesselCrewFactory extends Factory
{
    public function definition(): array
    {
        return ['vessel_id' => Vessel::factory(), 'crew_id' => Crew::factory(), 'active_crew_id' => fn (array $attributes) => $attributes['crew_id'], 'full_name' => fake()->name(), 'rank' => 'AB', 'sign_on_at' => today()->subMonth(), 'last_changed_at' => today()->subMonth(), 'sign_on_service_id' => SijilService::factory(), 'revision' => 1];
    }
}
