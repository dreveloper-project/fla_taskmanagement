<?php

namespace App\Http\Controllers;

use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Services\DocumentFilename;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VesselDocumentDownloadController extends Controller
{
    public function __invoke(Vessel $vessel, VesselDocument $document): StreamedResponse
    {
        Gate::authorize('access-system');
        abort_unless($document->vessel_id === $vessel->id, 404);
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, DocumentFilename::forVessel($document, $vessel, $document->document_type), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
