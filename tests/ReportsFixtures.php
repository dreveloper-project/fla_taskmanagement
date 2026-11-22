<?php

use App\Services\ReportFilters;
use App\Services\ReportService;

/** @param array<string, mixed> $overrides */
function reports(array $overrides = []): ReportService
{
    return new ReportService(ReportFilters::validate(array_replace(['date_from' => '2026-10-01', 'date_to' => '2026-10-31'], $overrides)));
}
