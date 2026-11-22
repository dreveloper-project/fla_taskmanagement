<?php

namespace Database\Factories;

use App\Models\BunkerDocument;
use App\Models\BunkerService;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BunkerDocument> */
class BunkerDocumentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'bunker_service_id' => BunkerService::factory(),
            'document_type' => 'application_letter',
            'file_path' => 'bunker/'.fake()->uuid().'.pdf',
            'original_filename' => 'application.pdf',
            'uploaded_by' => User::factory(), 'uploaded_at' => now(),
        ];
    }
}
