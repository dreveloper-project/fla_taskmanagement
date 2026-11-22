<?php

namespace Database\Factories;

use App\Models\SijilDocument;
use App\Models\SijilService;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SijilDocument> */
class SijilDocumentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sijil_service_id' => SijilService::factory(),
            'document_type' => 'application_letter',
            'file_path' => 'sijil/'.fake()->uuid().'.pdf',
            'original_filename' => 'application.pdf',
            'uploaded_by' => User::factory(), 'uploaded_at' => now(),
        ];
    }
}
