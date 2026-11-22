<?php

namespace Database\Factories;

use App\Models\FinanceInvoice;
use App\Models\FinanceInvoiceItem;
use App\Models\FinanceInvoiceItemReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceInvoiceItemReview>
 */
class FinanceInvoiceItemReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'finance_invoice_item_id' => FinanceInvoiceItem::factory()->state([
                'finance_invoice_id' => FinanceInvoice::factory()->state(['settled_at' => now()]),
                'settled_amount' => '1000000.00',
            ]),
            'reviewed_by' => User::factory()->admin(),
            'previous_fee' => '1000000.00',
            'reviewed_fee' => '1200000.00',
            'notes' => 'Selisih ditinjau secara manual.',
        ];
    }
}
