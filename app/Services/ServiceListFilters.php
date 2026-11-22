<?php

namespace App\Services;

use App\Models\BunkerService;
use App\Models\SafetyCertificateService;
use App\Models\ShipNationalityService;
use App\Models\SijilService;
use App\Models\SpbService;
use App\Models\Vessel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ServiceListFilters
{
    public function __construct(private SpbServiceFilters $spbFilters, private SijilReadinessService $sijilReadiness) {}

    /**
     * @template TService of SpbService|SafetyCertificateService|BunkerService|SijilService|ShipNationalityService
     *
     * @param  Builder<TService>  $query
     * @param  array{search?: ?string, status?: ?string, vessel?: string|int|null, dateFrom?: ?string, dateTo?: ?string, requestType?: ?string, documentType?: ?string, certificateExpiry?: ?string, vesselType?: ?string}  $filters
     * @return Builder<TService>
     */
    public function apply(Builder $query, array $filters): Builder
    {
        $class = $query->getModel()::class;
        if ($class === SpbService::class) {
            return $this->spbFilters->apply($query, $filters);
        }

        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $search = '%'.mb_substr($search, 0, 255).'%';
            $query->where(function (Builder $query) use ($class, $search): void {
                $query->where('service_number', 'like', $search)
                    ->orWhereHas('vessel', fn (Builder $vessels): Builder => $vessels->where('vessel_name', 'like', $search));
                $columns = match ($class) {
                    BunkerService::class => ['supplier', 'bunker_location', 'bunker_type'],
                    SafetyCertificateService::class => ['certificate_number'],
                    ShipNationalityService::class => ['issued_document_number'],
                    default => [],
                };
                foreach ($columns as $column) {
                    $query->orWhere($column, 'like', $search);
                }
                if ($class === SijilService::class) {
                    $query->orWhereHas('crews', fn (Builder $crews): Builder => $crews->where('full_name', 'like', $search));
                }
            });
        }

        $status = $filters['status'] ?? '';
        if (isset($class::STATUSES[$status])) {
            $query->where('status', $status);
        } elseif ($status === 'active' && $class !== SafetyCertificateService::class) {
            $query->whereIn('status', $class::ACTIVE);
        } elseif ($status === 'attention') {
            if ($class === SijilService::class) {
                $query->whereIn('id', $this->sijilReadiness->needingAttention()->modelKeys());
            } else {
                $query->needsAttention();
            }
        }
        $vessel = (string) ($filters['vessel'] ?? '');
        if (ctype_digit($vessel)) {
            $query->where('vessel_id', $vessel);
        }
        if (in_array($class, [SafetyCertificateService::class, ShipNationalityService::class], true)) {
            $requestType = $filters['requestType'] ?? '';
            if (isset($class::REQUEST_TYPES[$requestType])) {
                $query->where('request_type', $requestType);
            }
        }
        if ($class === ShipNationalityService::class && isset($class::DOCUMENT_TYPES[$filters['documentType'] ?? ''])) {
            $query->where('document_type', $filters['documentType']);
        }
        $type = array_search($class, TaskServiceRegistry::TYPES, true);
        $dateColumn = match ($class) {
            BunkerService::class => 'scheduled_at',
            SijilService::class => 'submitted_at',
            default => 'created_at',
        };
        foreach (['dateFrom' => '>=', 'dateTo' => '<='] as $key => $operator) {
            $value = $filters[$key] ?? '';
            if ($value !== '' && Validator::make(['date' => $value], ['date' => 'date_format:Y-m-d'])->passes()) {
                $date = CarbonImmutable::parse($value, config($type.'.timezone'));
                $query->where($dateColumn, $operator, ($key === 'dateFrom' ? $date->startOfDay() : $date->endOfDay())->utc());
            }
        }
        $expiry = $filters['certificateExpiry'] ?? '';
        if ($class === SafetyCertificateService::class && in_array($expiry, ['expired', 'expiring_soon', 'valid'], true)) {
            $today = today(config('app.timezone'))->toDateString();
            $warning = today(config('app.timezone'))->addDays((int) config('vessels.expiration_warning_days'))->toDateString();
            $query->whereHas('vessel.documents', function (Builder $documents) use ($today, $warning, $expiry): void {
                $documents->where('document_type', 'safety_certificate');
                match ($expiry) {
                    'expired' => $documents->whereDate('expired_date', '<', $today),
                    'expiring_soon' => $documents->whereBetween('expired_date', [$today, $warning]),
                    default => $documents->whereDate('expired_date', '>', $warning),
                };
            });
        }

        return $query;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(string $type): array
    {
        $class = TaskServiceRegistry::TYPES[$type];
        $specialStatuses = match ($type) {
            'spb' => ['pending'],
            'safety-certificates' => ['attention'],
            default => ['active', 'attention'],
        };
        $rules = [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in([...array_keys($class::STATUSES), ...$specialStatuses])],
            'vessel' => ['nullable', 'integer', 'min:1'],
            'dateFrom' => ['nullable', 'date_format:Y-m-d'],
            'dateTo' => ['nullable', 'date_format:Y-m-d'],
        ];
        if (in_array($type, ['safety-certificates', 'ship-nationality'], true)) {
            $rules['requestType'] = ['nullable', 'string', Rule::in(array_keys($class::REQUEST_TYPES))];
        }
        if ($type === 'safety-certificates') {
            $rules['certificateExpiry'] = ['nullable', 'string', Rule::in(['expired', 'expiring_soon', 'valid'])];
        }
        if ($type === 'ship-nationality') {
            $rules['documentType'] = ['nullable', 'string', Rule::in(array_keys(ShipNationalityService::DOCUMENT_TYPES))];
        }
        if ($type === 'spb') {
            $rules['vesselType'] = ['nullable', 'string', Rule::in(array_keys(Vessel::TYPES))];
        }

        return $rules;
    }
}
