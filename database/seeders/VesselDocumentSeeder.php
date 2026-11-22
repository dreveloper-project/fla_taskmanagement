<?php

namespace Database\Seeders;

use App\Models\Vessel;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class VesselDocumentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        $path = 'vessels/seed-example.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\nExample vessel document for development.\n%%EOF");
        $file = new UploadedFile(Storage::disk('local')->path($path), 'example.pdf', 'application/pdf', test: true);

        Vessel::factory()->create()->saveWithDocuments([], [
            'surat_laut' => ['file' => $file, 'expired_date' => now()->addDays(15)->toDateString()],
        ]);
        Storage::disk('local')->delete($path);
    }
}
