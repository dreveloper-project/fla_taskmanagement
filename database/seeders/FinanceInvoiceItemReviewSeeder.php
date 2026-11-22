<?php

namespace Database\Seeders;

use App\Models\FinanceInvoiceItemReview;
use Illuminate\Database\Seeder;

class FinanceInvoiceItemReviewSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        FinanceInvoiceItemReview::factory()->count(3)->create();
    }
}
