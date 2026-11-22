<?php

namespace Database\Seeders;

use App\Models\FinanceInvoice;
use Illuminate\Database\Seeder;

class FinanceInvoiceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        FinanceInvoice::factory()->hasItems(2)->count(3)->create();
    }
}
