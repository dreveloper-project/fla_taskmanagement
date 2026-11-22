<?php

namespace App\Http\Controllers;

use App\Services\ReportFilters;
use App\Services\ReportService;
use App\Services\XlsxReportWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    public function __invoke(Request $request, string $kind, XlsxReportWriter $writer): StreamedResponse
    {
        Gate::authorize('access-reports');
        abort_unless(array_key_exists($kind, ReportService::REPORTS), 404);
        $filters = ReportFilters::validate($request->query());
        $report = new ReportService($filters);
        $filename = $kind.'-report-'.$filters['date_from'].'_'.$filters['date_to'].'.xlsx';

        return response()->streamDownload(function () use ($report, $kind, $writer): void {
            $rows = $report->reportQuery($kind)->lazy(250)
                ->map(fn (\stdClass $record): array => $report->row($kind, $record));
            $writer->stream(array_values($report->columns($kind)), $rows);
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
