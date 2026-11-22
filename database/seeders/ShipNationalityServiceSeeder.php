<?php

namespace Database\Seeders;

use App\Models\ShipNationalityService;
use Illuminate\Database\Seeder;

class ShipNationalityServiceSeeder extends Seeder
{
    public function run(): void
    {
        ShipNationalityService::factory()->count(3)->sequence(
            ['document_type' => 'surat_laut'], ['document_type' => 'pas_besar'], ['document_type' => 'pas_kecil'],
        )->create();
    }
}
