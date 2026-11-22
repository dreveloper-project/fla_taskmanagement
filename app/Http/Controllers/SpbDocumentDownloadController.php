<?php

namespace App\Http\Controllers;

use App\Models\SpbDocument;
use App\Models\SpbService;
use App\Services\DocumentFilename;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpbDocumentDownloadController extends Controller
{
    public function __invoke(SpbService $spb, SpbDocument $document): StreamedResponse
    {
        Gate::authorize('access-system');
        abort_unless($document->spb_service_id === $spb->id, 404);
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, DocumentFilename::forService($document, $spb->vessel), [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
