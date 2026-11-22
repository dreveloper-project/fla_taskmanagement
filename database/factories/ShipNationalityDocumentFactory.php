<?php

namespace Database\Factories;

use App\Models\ShipNationalityDocument;
use App\Models\ShipNationalityService;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShipNationalityDocument> */
class ShipNationalityDocumentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ship_nationality_service_id' => ShipNationalityService::factory(),
            'document_type' => 'application_letter',
            'file_path' => 'ship-nationality/'.fake()->uuid().'.pdf',
            'original_filename' => 'application.pdf',
            'uploaded_by' => User::factory(), 'uploaded_at' => now(),
        ];
    }
}
