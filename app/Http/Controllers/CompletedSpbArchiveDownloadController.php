<?php

namespace App\Http\Controllers;

use App\Models\SpbService;
use App\Models\Vessel;
use App\Services\ArchiveDownloadResponse;
use App\Services\ServiceArchive;
use App\Services\SpbServiceFilters;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompletedSpbArchiveDownloadController extends Controller
{
    public function __invoke(Request $request, SpbServiceFilters $filtering, ServiceArchive $archive, ArchiveDownloadResponse $download): BinaryFileResponse|RedirectResponse|StreamedResponse
    {
        Gate::authorize('access-system');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in([...array_keys(SpbService::STATUSES), 'pending'])],
            'vessel' => ['nullable', 'integer', 'min:1'],
            'vesselType' => ['nullable', 'string', Rule::in(array_keys(Vessel::TYPES))],
            'dateFrom' => ['nullable', 'date_format:Y-m-d'],
            'dateTo' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $query = $filtering->apply(SpbService::query(), $filters);
        $filename = 'SPB-Completed-'.now(config('spb.timezone'))->format('Y-m-d-His').'.zip';

        if ($request->isMethod('post')) {
            return $download->stream(fn (Closure $progress, Closure $cancelled): string => $archive->createCompletedSpbs($query, $progress, $cancelled), $filename);
        }

        try {
            $path = $archive->createCompletedSpbs($query);
        } catch (ValidationException $exception) {
            return redirect()->route('spb.index', $filters)->with('spb_archive_error', $exception->errors()['archive'][0]);
        }

        return response()->download($path, $filename, [
            'Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ])->setPrivate()->deleteFileAfterSend();
    }
}
