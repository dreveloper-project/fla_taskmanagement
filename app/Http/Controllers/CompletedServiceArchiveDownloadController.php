<?php

namespace App\Http\Controllers;

use App\Services\ArchiveDownloadResponse;
use App\Services\ServiceArchive;
use App\Services\ServiceListFilters;
use App\Services\TaskServiceRegistry;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompletedServiceArchiveDownloadController extends Controller
{
    public function __invoke(Request $request, string $type, ServiceListFilters $filtering, ServiceArchive $archive, ArchiveDownloadResponse $download): BinaryFileResponse|RedirectResponse|StreamedResponse
    {
        Gate::authorize('access-system');
        $class = TaskServiceRegistry::TYPES[$type] ?? null;
        abort_if($class === null, 404);
        $filters = $request->validate($filtering->rules($type));
        $query = $filtering->apply($class::query(), $filters);
        $filename = Str::studly($type).'-Completed-'.now(config($type.'.timezone'))->format('Y-m-d-His').'.zip';

        if ($request->isMethod('post')) {
            return $download->stream(fn (Closure $progress, Closure $cancelled): string => $archive->createCompletedServices($query, $progress, $cancelled), $filename);
        }

        try {
            $path = $archive->createCompletedServices($query);
        } catch (ValidationException $exception) {
            return redirect()->route($type.'.index', $filters)->with('completed_archive_error', $exception->errors()['archive'][0]);
        }

        return response()->download($path, $filename, [
            'Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ])->setPrivate()->deleteFileAfterSend();
    }
}
