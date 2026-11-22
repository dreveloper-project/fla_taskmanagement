<?php

namespace App\Http\Controllers;

use App\Models\Vessel;
use App\Models\VesselDocumentVersion;
use App\Services\DocumentFilename;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VesselDocumentVersionDownloadController extends Controller
{
    public function __invoke(Vessel $vessel, VesselDocumentVersion $version): StreamedResponse
    {
        Gate::authorize('access-system');
        abort_unless($version->vesselDocument->vessel_id === $vessel->id, 404);
        abort_unless(Storage::disk('local')->exists($version->file_path), 404);

        return Storage::disk('local')->download($version->file_path, DocumentFilename::forVessel($version, $vessel, $version->vesselDocument->document_type), [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
