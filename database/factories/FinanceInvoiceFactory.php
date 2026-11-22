<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\FinanceInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceInvoice>
 */
class FinanceInvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_number' => 'INV-'.fake()->unique()->uuid(),
            'client_id' => Client::factory(),
            'frequency' => 'manual',
            'due_on' => today(),
        ];
    }
}
