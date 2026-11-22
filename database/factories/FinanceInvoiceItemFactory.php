<?php

namespace Database\Factories;

use App\Models\FinanceInvoice;
use App\Models\FinanceInvoiceItem;
use App\Models\SpbService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceInvoiceItem>
 */
class FinanceInvoiceItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'finance_invoice_id' => FinanceInvoice::factory(),
            'service_type' => 'spb',
            'service_id' => fn (array $attributes) => SpbService::factory()->create([
                'client_id' => FinanceInvoice::findOrFail($attributes['finance_invoice_id'])->client_id,
                'status' => 'completed', 'agent_fee' => '1000000.00',
            ])->id,
            'service_number' => fn (array $attributes) => SpbService::findOrFail($attributes['service_id'])->service_number,
            'vessel_name' => fn (array $attributes) => SpbService::findOrFail($attributes['service_id'])->vessel->vessel_name,
            'original_agent_fee' => '1000000.00',
            'prepaid_amount' => '0.00',
            'active_service_key' => fn (array $attributes) => $attributes['service_type'].':'.$attributes['service_id'],
        ];
    }
}
