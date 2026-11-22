<?php

namespace Database\Factories;

use App\Models\SpbDocument;
use App\Models\SpbService;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SpbDocument> */
class SpbDocumentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'spb_service_id' => SpbService::factory(),
            'document_type' => 'spb_request_letter',
            'file_path' => 'spb/'.fake()->uuid().'.pdf',
            'original_filename' => 'permohonan.pdf',
            'uploaded_by' => User::factory(),
            'uploaded_at' => now(),
        ];
    }
}
