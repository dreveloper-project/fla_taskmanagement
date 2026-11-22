<?php

namespace Database\Seeders;

use App\Models\FinanceInvoiceItem;
use Illuminate\Database\Seeder;

class FinanceInvoiceItemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        FinanceInvoiceItem::factory()->count(3)->create();
    }
}
