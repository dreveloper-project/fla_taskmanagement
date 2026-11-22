<?php

namespace App\Http\Controllers;

use App\Services\ArchiveDownloadResponse;
use App\Services\ServiceArchive;
use App\Services\TaskServiceRegistry;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ServiceArchiveDownloadController extends Controller
{
    public function __invoke(Request $request, string $type, int $service, ServiceArchive $archive, ArchiveDownloadResponse $download): BinaryFileResponse|RedirectResponse|StreamedResponse
    {
        Gate::authorize('access-system');
        $model = TaskServiceRegistry::TYPES[$type] ?? null;
        abort_if($model === null, 404);
        $record = $model::query()->findOrFail($service);
        abort_unless($record->status === 'completed', 404);
        $filename = 'Arsip-'.(Str::slug($record->service_number) ?: $type.'-'.$record->id).'.zip';

        if ($request->isMethod('post')) {
            return $download->stream(fn (Closure $progress, Closure $cancelled): string => $archive->create($record, $progress, $cancelled), $filename);
        }

        try {
            $path = $archive->create($record);
        } catch (ValidationException $exception) {
            return redirect()->route($type.'.show', $record)->with('archive_error', $exception->errors()['archive'][0]);
        }

        return response()->download($path, $filename, [
            'Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ])->setPrivate()->deleteFileAfterSend();
    }
}
