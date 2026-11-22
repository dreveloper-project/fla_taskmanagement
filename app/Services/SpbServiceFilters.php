<?php

namespace App\Services;

use App\Models\SpbService;
use App\Models\Vessel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;

class SpbServiceFilters
{
    /**
     * @param  Builder<SpbService>  $query
     * @param  array{search?: string|null, status?: string|null, vessel?: string|int|null, vesselType?: string|null, dateFrom?: string|null, dateTo?: string|null}  $filters
     * @return Builder<SpbService>
     */
    public function apply(Builder $query, array $filters): Builder
    {
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $search = '%'.mb_substr($search, 0, 255).'%';
            $query->where(function (Builder $query) use ($search): void {
                $query->where('service_number', 'like', $search)
                    ->orWhere('destination', 'like', $search)
                    ->orWhereHas('vessel', fn (Builder $vessels): Builder => $vessels->where('vessel_name', 'like', $search));
            });
        }
        $status = $filters['status'] ?? '';
        if (isset(SpbService::STATUSES[$status])) {
            $query->where('status', $status);
        } elseif ($status === 'pending') {
            $query->whereIn('status', SpbService::PENDING);
        }
        $vessel = (string) ($filters['vessel'] ?? '');
        if (ctype_digit($vessel)) {
            $query->where('vessel_id', $vessel);
        }
        $vesselType = $filters['vesselType'] ?? '';
        if (isset(Vessel::TYPES[$vesselType])) {
            $query->whereHas('vessel', fn (Builder $vessels): Builder => $vessels->where('vessel_type', $vesselType));
        }
        $dateFrom = $filters['dateFrom'] ?? '';
        if ($dateFrom !== '' && Validator::make(['date' => $dateFrom], ['date' => 'date_format:Y-m-d'])->passes()) {
            $query->where('estimated_departure_at', '>=', CarbonImmutable::parse($dateFrom, config('spb.timezone'))->startOfDay()->utc());
        }
        $dateTo = $filters['dateTo'] ?? '';
        if ($dateTo !== '' && Validator::make(['date' => $dateTo], ['date' => 'date_format:Y-m-d'])->passes()) {
            $query->where('estimated_departure_at', '<=', CarbonImmutable::parse($dateTo, config('spb.timezone'))->endOfDay()->utc());
        }

        return $query;
    }
}
