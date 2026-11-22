<?php

namespace Database\Factories;

use App\Models\SafetyCertificateDocument;
use App\Models\SafetyCertificateService;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SafetyCertificateDocument> */
class SafetyCertificateDocumentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'safety_certificate_service_id' => SafetyCertificateService::factory(),
            'document_type' => 'application_letter',
            'file_path' => 'safety-certificates/'.fake()->uuid().'.pdf',
            'original_filename' => 'application.pdf',
            'uploaded_by' => User::factory(), 'uploaded_at' => now(),
        ];
    }
}
