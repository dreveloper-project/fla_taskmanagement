<?php

namespace App\Services;

use App\Models\VesselDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ReportFilters
{
    /** @return array<string, string> */
    public static function defaults(): array
    {
        $now = CarbonImmutable::now(config('spb.timezone'));

        return ['date_from' => $now->startOfMonth()->toDateString(), 'date_to' => $now->endOfMonth()->toDateString(),
            'service_type' => '', 'status' => '', 'vessel_id' => '', 'staff_id' => '',
            'document_type' => '', 'document_status' => '', 'expiry_from' => '', 'expiry_to' => '', 'document_scope' => 'current'];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        $statuses = [];
        foreach (TaskServiceRegistry::TYPES as $class) {
            $statuses += $class::STATUSES;
        }

        return $statuses;
    }

    /** @param array<string, mixed> $input
     * @return array<string, string>
     */
    public static function validate(array $input): array
    {
        $data = array_replace(self::defaults(), array_intersect_key($input, self::defaults()));
        $validated = Validator::make($data, [
            'date_from' => ['required', 'date_format:Y-m-d'], 'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'service_type' => ['nullable', Rule::in(array_keys(TaskServiceRegistry::TYPES))],
            'status' => ['nullable', Rule::in(array_keys(self::statuses()))],
            'vessel_id' => ['nullable', 'integer', 'exists:vessels,id'],
            'staff_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', 'staff')],
            'document_type' => ['nullable', Rule::in(array_keys(VesselDocument::TYPES))],
            'document_status' => ['nullable', Rule::in(['valid', 'expiring_soon', 'expired', 'unknown'])],
            'expiry_from' => ['nullable', 'date_format:Y-m-d'],
            'expiry_to' => ['nullable', 'date_format:Y-m-d', Rule::when(! empty($data['expiry_from']), ['after_or_equal:expiry_from'])],
            'document_scope' => ['required', Rule::in(['current', 'versions', 'all'])],
        ])->validate();

        return array_map(fn ($value): string => $value === null ? '' : (string) $value, $validated);
    }
}
