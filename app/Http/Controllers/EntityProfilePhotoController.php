<?php

namespace App\Http\Controllers;

use App\Models\Vessel;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EntityProfilePhotoController extends Controller
{
    public function vessel(Vessel $vessel): StreamedResponse
    {
        return $this->show($vessel->profile_picture_path);
    }

    private function show(?string $path): StreamedResponse
    {
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
