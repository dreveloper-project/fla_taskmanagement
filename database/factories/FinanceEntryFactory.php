<?php

namespace Database\Factories;

use App\Models\FinanceEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceEntry>
 */
class FinanceEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'created_by' => User::factory()->admin(),
            'type' => 'income',
            'amount' => fake()->randomFloat(2, 1, 1000000),
            'transaction_date' => today(),
            'description' => fake()->sentence(),
        ];
    }
}
