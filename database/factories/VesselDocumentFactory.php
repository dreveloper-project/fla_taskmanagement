<?php

namespace Database\Factories;

use App\Models\Vessel;
use App\Models\VesselDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VesselDocument>
 */
class VesselDocumentFactory extends Factory
{
    public function readyEquipment(string $type): static
    {
        return $this->state(fn (): array => [
            'document_type' => $type,
            'issued_date' => now()->subYear()->toDateString(),
            'expired_date' => in_array($type, ['liferaft', 'fire_fighting'], true) ? now()->addYear()->toDateString() : null,
            'equipment' => ['condition' => 'ready', 'quantity' => 200, 'capacity' => $type === 'liferaft' ? 200 : null, 'notes' => 'Pemeriksaan alat selesai'],
        ]);
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vessel_id' => Vessel::factory(),
            'document_type' => 'surat_laut',
            'file_path' => 'vessels/'.fake()->uuid().'.pdf',
            'original_filename' => 'surat-laut.pdf',
            'issued_date' => null,
            'expired_date' => null,
        ];
    }
}
