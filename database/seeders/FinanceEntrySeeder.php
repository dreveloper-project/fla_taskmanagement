<?php

namespace Database\Seeders;

use App\Models\FinanceEntry;
use Illuminate\Database\Seeder;

class FinanceEntrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        FinanceEntry::factory()->count(5)->create();
    }
}
