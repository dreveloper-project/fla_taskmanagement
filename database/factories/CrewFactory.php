<?php

namespace Database\Factories;

use App\Models\Crew;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Crew> */
class CrewFactory extends Factory
{
    public function definition(): array
    {
        return ['full_name' => fake()->name(), 'seafarer_book_number' => fake()->unique()->bothify('SB-########'), 'competency_certificate_number' => fake()->bothify('COC-######')];
    }
}
