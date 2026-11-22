<?php

namespace Database\Seeders;

use App\Models\Vessel;
use Illuminate\Database\Seeder;

class VesselProfileExampleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Vessel::query()->chunkById(100, function ($vessels): void {
            foreach ($vessels as $vessel) {
                Vessel::query()->whereKey($vessel->id)->whereNull('passenger_capacity')
                    ->update(['passenger_capacity' => $vessel->vessel_type === 'passenger' ? 50 + ($vessel->id % 10) * 25 : 0]);
            }
        });
    }
}
