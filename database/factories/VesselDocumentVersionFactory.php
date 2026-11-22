<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VesselDocument;
use App\Models\VesselDocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VesselDocumentVersion> */
class VesselDocumentVersionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'vessel_document_id' => VesselDocument::factory()->state(['document_type' => 'safety_certificate']),
            'certificate_number' => 'OLD-'.fake()->uuid(),
            'file_path' => 'vessels/'.fake()->uuid().'.pdf', 'original_filename' => 'previous.pdf',
            'issued_date' => today()->subYear(), 'expired_date' => today(),
            'status' => 'superseded', 'archived_at' => now(), 'archived_by' => User::factory(),
        ];
    }
}
