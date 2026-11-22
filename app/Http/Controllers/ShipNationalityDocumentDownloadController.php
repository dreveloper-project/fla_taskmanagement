<?php

namespace App\Http\Controllers;

use App\Models\ShipNationalityDocument;
use App\Models\ShipNationalityService;
use App\Services\DocumentFilename;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShipNationalityDocumentDownloadController extends Controller
{
    public function __invoke(ShipNationalityService $service, ShipNationalityDocument $document): StreamedResponse
    {
        Gate::authorize('access-system');
        abort_unless($document->ship_nationality_service_id === $service->id, 404);
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, DocumentFilename::forService($document, $service->vessel, $service->document_type), [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
