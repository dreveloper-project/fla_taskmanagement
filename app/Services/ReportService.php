<?php

namespace App\Services;

use App\Models\BunkerService;
use App\Models\SafetyCertificateService;
use App\Models\ShipNationalityService;
use App\Models\SijilService;
use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use stdClass;

class ReportService
{
    private const TABLES = ['spb' => 'spb_services', 'safety-certificates' => 'safety_certificate_services', 'bunker' => 'bunker_services', 'sijil' => 'sijil_services', 'ship-nationality' => 'ship_nationality_services'];

    public const STATUS_GROUPS = [
        'draft' => 'Draft', 'ready_to_apply' => 'Ready', 'applied' => 'Under Review', 'under_review' => 'Under Review',
        'revision_required' => 'Revision', 'completed' => 'Completed', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled',
        'failed_to_sail' => 'Failed',
    ];

    public const EXTRA_COLUMNS = [
        'spb' => ['destination' => 'Destination', 'estimated_departure_at' => 'Estimated Departure', 'actual_departure_at' => 'Actual Departure', 'spb_number' => 'SPB Number', 'sailing_result' => 'Hasil SPB'],
        'safety-certificates' => ['request_type' => 'Request Type', 'certificate_number' => 'Certificate Number', 'certificate_expired_at' => 'Expiry Date'],
        'bunker' => ['supplier' => 'Pemasok', 'bunker_type' => 'Bunker Type', 'quantity' => 'Jumlah', 'unit' => 'Satuan', 'scheduled_at' => 'Scheduled At', 'actual_bunker_at' => 'Actual At'],
        'sijil' => ['process_type' => 'Process Type', 'total_crew_affected' => 'Total Crew Affected'],
        'ship-nationality' => ['document_type' => 'Document Type', 'request_type' => 'Request Type', 'issued_document_number' => 'Issued Document Number'],
    ];

    /** @var list<int>|null */
    private ?array $sijilAttentionIds = null;

    /** @param array<string, string> $filters Validated by ReportFilters. */
    public function __construct(public readonly array $filters) {}

    /** @return array{CarbonImmutable, CarbonImmutable} */
    public function range(): array
    {
        return [
            CarbonImmutable::parse($this->filters['date_from'], config('spb.timezone'))->startOfDay()->utc(),
            CarbonImmutable::parse($this->filters['date_to'], config('spb.timezone'))->endOfDay()->utc(),
        ];
    }

    private function filterService(Builder $query, string $table, string $dateColumn): void
    {
        $query->whereBetween($table.'.'.$dateColumn, $this->range());
        foreach (['status' => 'status', 'vessel_id' => 'vessel_id', 'staff_id' => 'assigned_staff_id'] as $filter => $column) {
            if ($this->filters[$filter] !== '') {
                $query->where($table.'.'.$column, $this->filters[$filter]);
            }
        }
    }

    /** @return list<int> */
    private function sijilAttention(): array
    {
        if ($this->sijilAttentionIds !== null) {
            return $this->sijilAttentionIds;
        }
        $query = SijilService::query()->whereIn('status', SijilService::ACTIVE)
            ->with(['vessel.documents', 'assignedStaff', 'documents', 'crews.documents', 'crews.crew.documents']);
        $this->filterService($query->getQuery(), 'sijil_services', 'created_at');
        $this->sijilAttentionIds = [];
        $readiness = app(SijilReadinessService::class);
        foreach ($query->lazyById(100) as $service) {
            if ($readiness->needsAttention($service)) {
                $this->sijilAttentionIds[] = $service->id;
            }
        }

        return $this->sijilAttentionIds;
    }

    public function serviceQuery(bool $attention = true, string $dateColumn = 'created_at', bool $details = false): Builder
    {
        $parts = [];
        $extras = array_unique(array_merge(...array_values(array_map('array_keys', self::EXTRA_COLUMNS))));
        foreach (TaskServiceRegistry::TYPES as $type => $class) {
            if ($this->filters['service_type'] !== '' && $this->filters['service_type'] !== $type) {
                continue;
            }
            $query = $class::query();
            $table = self::TABLES[$type];
            $this->filterService($query->getQuery(), $table, $dateColumn);
            $query->select(array_map(fn (string $column): string => $table.'.'.$column, [
                'id', 'service_number', 'vessel_id', 'assigned_staff_id', 'status', 'created_at', 'submitted_at', 'completed_at',
            ]))->selectRaw('? as report_type', [$type]);
            $activePlaceholders = implode(',', array_fill(0, count($class::ACTIVE), '?'));
            $query->selectRaw("CASE WHEN $table.status IN ($activePlaceholders) THEN 1 ELSE 0 END as is_active", $class::ACTIVE);
            $duration = DB::getDriverName() === 'sqlite'
                ? "ROUND((julianday($table.completed_at) - julianday($table.created_at)) * 86400)"
                : "TIMESTAMPDIFF(SECOND, $table.created_at, $table.completed_at)";
            $query->selectRaw("CASE WHEN $table.status = 'completed' AND $table.completed_at >= $table.created_at THEN $duration ELSE NULL END as duration_seconds");
            if ($attention) {
                $attentionQuery = match ($type) {
                    'spb' => SpbService::query()->where('status', 'revision_required'),
                    'safety-certificates' => SafetyCertificateService::query()->needsAttention(),
                    'bunker' => BunkerService::query()->needsAttention(),
                    'ship-nationality' => ShipNationalityService::query()->needsAttention(),
                    'sijil' => SijilService::query()->whereIntegerInRaw('id', $this->sijilAttention()),
                };
                $attentionQuery->select($table.'.id')->selectRaw('1 as flag');
                $query->leftJoinSub($attentionQuery, 'attention_match', 'attention_match.id', '=', $table.'.id')
                    ->selectRaw('COALESCE(attention_match.flag,0) as attention');
            } else {
                $query->selectRaw('0 as attention');
            }
            foreach ($details ? $extras : [] as $column) {
                if ($type === 'sijil' && $column === 'total_crew_affected') {
                    $query->selectSub(DB::table('sijil_service_crews')->selectRaw('COUNT(*)')->whereColumn('sijil_service_id', $table.'.id')->whereNull('removed_at'), $column);
                } elseif (isset(self::EXTRA_COLUMNS[$type][$column])) {
                    $query->addSelect($table.'.'.$column);
                } else {
                    $query->selectRaw('NULL as '.$column);
                }
            }
            $parts[] = $query->toBase();
        }
        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        return DB::query()->fromSub($union, 'services');
    }

    public function services(): Builder
    {
        return $this->serviceQuery(true, 'created_at', true)->leftJoin('vessels as v', 'v.id', '=', 'services.vessel_id')
            ->leftJoin('users as u', 'u.id', '=', 'services.assigned_staff_id')
            ->select('services.*', 'v.vessel_name', 'u.name as staff_name')
            ->orderByDesc('services.created_at')->orderBy('services.report_type')->orderByDesc('services.id');
    }

    private function aggregate(Builder $query): Builder
    {
        return $query->selectRaw("COUNT(*) as total, COALESCE(SUM(is_active),0) as active,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
            COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected,
            COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled,
            COALESCE(SUM(attention),0) as attention, AVG(duration_seconds) as average_seconds");
    }

    /** @return array<string, int|float|null> */
    public function summary(): array
    {
        $row = $this->aggregate($this->serviceQuery())->first();
        $total = (int) $row->total;
        $eligible = $total - (int) $row->cancelled;
        $documents = $this->documentSummary();

        return ['total' => $total, 'active' => (int) $row->active, 'completed' => (int) $row->completed,
            'cancelled' => (int) $row->cancelled, 'eligible' => $eligible,
            'service_attention' => (int) $row->attention,
            'expired_documents' => $documents['expired'],
            'attention' => (int) $row->attention + $documents['expired'],
            'completion_rate' => $eligible > 0 ? round((int) $row->completed / $eligible * 100, 1) : 0,
            'average_seconds' => $row->average_seconds === null ? null : (float) $row->average_seconds];
    }

    /** @return Collection<int, stdClass> */
    public function breakdown(): Collection
    {
        return $this->aggregate($this->serviceQuery())->addSelect('report_type')->groupBy('report_type')->orderBy('report_type')->get();
    }

    /** @return Collection<int, stdClass> */
    public function distribution(): Collection
    {
        $case = 'CASE status ';
        $bindings = [];
        foreach (self::STATUS_GROUPS as $status => $label) {
            $case .= 'WHEN ? THEN ? ';
            array_push($bindings, $status, $label);
        }
        $case .= "ELSE 'Operational / Handover' END";

        return DB::query()->fromSub($this->serviceQuery(false)->selectRaw("$case as status_group", $bindings), 'grouped')
            ->select('status_group')->selectRaw('COUNT(*) as total')->groupBy('status_group')->orderBy('status_group')->get();
    }

    /** @return Collection<int, stdClass> */
    public function trend(): Collection
    {
        $parts = [];
        foreach (['created_at' => 'created', 'completed_at' => 'completed'] as $column => $metric) {
            $query = $this->serviceQuery(false, $column);
            if ($metric === 'completed') {
                $query->where('status', 'completed');
            }
            $month = DB::getDriverName() === 'sqlite'
                ? "strftime('%Y-%m', $column, '+8 hours')" : "DATE_FORMAT(DATE_ADD($column, INTERVAL 8 HOUR), '%Y-%m')";
            $parts[] = $query->selectRaw("$month as month, ? as metric, COUNT(*) as total", [$metric])->groupByRaw($month);
        }

        return DB::query()->fromSub($parts[0]->unionAll($parts[1]), 'trend')
            ->select('month')->selectRaw("SUM(CASE WHEN metric = 'created' THEN total ELSE 0 END) as created, SUM(CASE WHEN metric = 'completed' THEN total ELSE 0 END) as completed")
            ->groupBy('month')->orderBy('month')->get();
    }

    private function filterVessels(Builder $query, string $alias): void
    {
        foreach (['vessel_id' => 'id'] as $filter => $column) {
            if ($this->filters[$filter] !== '') {
                $query->where($alias.'.'.$column, $this->filters[$filter]);
            }
        }
        if ($this->filters['service_type'] !== '' || $this->filters['status'] !== '' || $this->filters['staff_id'] !== '') {
            $query->whereIn($alias.'.id', $this->serviceQuery(false)->select('vessel_id'));
        }
    }

    /** @param literal-string $column
     * @return array{sql: literal-string, bindings: list<string>}
     */
    private function expiryCase(string $column): array
    {
        $sql = 'CASE ';
        $bindings = [];
        foreach (VesselDocument::expirationRanges() as $status => $range) {
            $conditions = [];
            foreach (['from' => '>=', 'to' => '<='] as $bound => $operator) {
                if ($range[$bound] !== null) {
                    $conditions[] = "DATE($column) $operator ?";
                    $bindings[] = $range[$bound];
                }
            }
            $sql .= 'WHEN '.implode(' AND ', $conditions).' THEN ? ';
            $bindings[] = $status;
        }

        return ['sql' => $sql."ELSE 'unknown' END", 'bindings' => $bindings];
    }

    public function documents(bool $currentOnly = false): Builder
    {
        $current = DB::table('vessel_documents as d')->select('d.id', 'd.vessel_id', 'd.document_type', 'd.certificate_number', 'd.issued_date', 'd.expired_date')
            ->selectRaw("'Current' as edition");
        $versions = DB::table('vessel_document_versions as version')->join('vessel_documents as parent', 'parent.id', '=', 'version.vessel_document_id')
            ->select('version.id', 'parent.vessel_id', 'parent.document_type', 'version.certificate_number', 'version.issued_date', 'version.expired_date')
            ->selectRaw("'Version' as edition");
        $scope = $currentOnly ? 'current' : $this->filters['document_scope'];
        $source = match ($scope) {
            'versions' => $versions, 'all' => $current->unionAll($versions), default => $current
        };
        $case = $this->expiryCase('docs.expired_date');
        $classified = DB::query()->fromSub($source, 'docs')->select('docs.*')->selectRaw($case['sql'].' as document_status', $case['bindings']);
        $query = DB::query()->fromSub($classified, 'documents')->join('vessels as v', 'v.id', '=', 'documents.vessel_id')
            ->select('documents.*', 'v.vessel_name');
        $this->filterVessels($query, 'v');
        foreach (['document_type', 'document_status'] as $filter) {
            if ($this->filters[$filter] !== '') {
                $query->where('documents.'.$filter, $this->filters[$filter]);
            }
        }
        foreach (['expiry_from' => '>=', 'expiry_to' => '<='] as $filter => $operator) {
            if ($this->filters[$filter] !== '') {
                $boundary = $filter === 'expiry_to'
                    ? CarbonImmutable::parse($this->filters[$filter])->addDay()->toDateString()
                    : $this->filters[$filter];
                $query->where('documents.expired_date', $filter === 'expiry_to' ? '<' : $operator, $boundary);
            }
        }

        return $query;
    }

    /** @return array<string, int> */
    public function documentSummary(): array
    {
        $counts = DB::query()->fromSub($this->documents(true), 'd')->select('document_status')->selectRaw('COUNT(*) as total')->groupBy('document_status')->pluck('total', 'document_status');

        return array_replace(['valid' => 0, 'expiring_soon' => 0, 'expired' => 0, 'unknown' => 0], $counts->map(fn ($value): int => (int) $value)->all());
    }

    public function vessels(): Builder
    {
        $serviceCounts = $this->aggregate($this->serviceQuery(false))->addSelect('vessel_id')->groupBy('vessel_id');
        $documentCounts = DB::query()->fromSub($this->documents(true), 'd')->select('vessel_id')
            ->selectRaw("COUNT(CASE WHEN document_status = 'expired' THEN 1 END) as expired, COUNT(CASE WHEN document_status = 'expiring_soon' THEN 1 END) as expiring")->groupBy('vessel_id');
        $query = DB::table('vessels as v')
            ->leftJoinSub($serviceCounts, 's', 's.vessel_id', '=', 'v.id')
            ->leftJoinSub($documentCounts, 'd', 'd.vessel_id', '=', 'v.id')
            ->select('v.id', 'v.vessel_name', 'v.owner', 'v.vessel_type', 'v.operational_status')
            ->selectRaw('COALESCE(s.total,0) as total, COALESCE(s.active,0) as active, COALESCE(s.completed,0) as completed, COALESCE(d.expired,0) as expired, COALESCE(d.expiring,0) as expiring');
        $this->filterVessels($query, 'v');

        return $query->orderByDesc('total')->orderBy('v.id');
    }

    /** @return array<string, int> */
    public function vesselSummary(): array
    {
        $query = DB::table('vessels as v');
        $this->filterVessels($query, 'v');
        $counts = $query->select('operational_status')->selectRaw('COUNT(*) as total')->groupBy('operational_status')->pluck('total', 'operational_status');

        return ['total' => (int) $counts->sum(), 'berthed' => (int) ($counts['berthed'] ?? 0), 'sailing' => (int) ($counts['sailing'] ?? 0), 'maintenance' => (int) ($counts['maintenance'] ?? 0)];
    }

    public function staff(): Builder
    {
        Gate::authorize('access-reports');
        $serviceCounts = $this->aggregate($this->serviceQuery())->selectRaw('COALESCE(assigned_staff_id,0) as staff_id')->groupByRaw('COALESCE(assigned_staff_id,0)');
        foreach (['spb' => 'completed_spb', 'safety-certificates' => 'completed_skkp', 'bunker' => 'completed_bunkering', 'sijil' => 'completed_sijil', 'ship-nationality' => 'completed_endorsement'] as $type => $column) {
            $serviceCounts->selectRaw("COUNT(CASE WHEN status = 'completed' AND report_type = ? THEN 1 END) as $column", [$type]);
        }
        $people = User::query()->where('role', 'staff')->select('id', 'name')
            ->when($this->filters['staff_id'] !== '', fn ($q) => $q->whereKey($this->filters['staff_id']))->toBase();
        if ($this->filters['staff_id'] === '') {
            $people->unionAll(DB::query()->selectRaw("0 as id, 'Unassigned' as name"));
        }
        $query = DB::query()->fromSub($people, 'u')->leftJoinSub($serviceCounts, 's', 's.staff_id', '=', 'u.id')
            ->select('u.id', 'u.name', 's.average_seconds')
            ->selectRaw('COALESCE(s.active,0) as active, COALESCE(s.completed,0) as completed, COALESCE(s.attention,0) as attention,
                COALESCE(s.completed_spb,0) as completed_spb, COALESCE(s.completed_skkp,0) as completed_skkp,
                COALESCE(s.completed_bunkering,0) as completed_bunkering, COALESCE(s.completed_sijil,0) as completed_sijil,
                COALESCE(s.completed_endorsement,0) as completed_endorsement');

        return $query->whereRaw('COALESCE(s.total,0) > 0')->orderBy('u.name')->orderBy('u.id');
    }

    public static function duration(float|int|null $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        $hours = (int) floor($seconds / 3600);

        return intdiv($hours, 24).'d '.($hours % 24).'h';
    }

    public const REPORTS = ['services' => 'Service Report', 'vessels' => 'Vessel Report', 'documents' => 'Document Report', 'staff' => 'Staff Performance'];

    public function reportQuery(string $kind): Builder
    {
        return match ($kind) {
            'services' => $this->services(), 'vessels' => $this->vessels(),
            'documents' => $this->documents()->orderBy('documents.expired_date')->orderBy('documents.edition')->orderBy('documents.id'),
            'staff' => $this->staff(),
            default => throw new \InvalidArgumentException('Jenis laporan tidak valid.'),
        };
    }

    /** @return array<string, string> */
    public function columns(string $kind): array
    {
        return match ($kind) {
            'services' => ['service_number' => 'Service Number', 'report_type' => 'Service Type', 'vessel_name' => 'Vessel', 'staff_name' => 'PIC', 'status' => 'Status', 'created_at' => 'Created At (WITA)', 'submitted_at' => 'Submitted At (WITA)', 'completed_at' => 'Completed At (WITA)', 'duration_seconds' => 'Completion Duration', 'attention' => 'Attention']
                + (self::EXTRA_COLUMNS[$this->filters['service_type']] ?? []),
            'vessels' => ['vessel_name' => 'Vessel', 'owner' => 'Owner / Operator', 'vessel_type' => 'Vessel Type', 'operational_status' => 'Operational Status', 'total' => 'Total Services', 'active' => 'Active Services', 'completed' => 'Completed Services', 'expiring' => 'Dokumen segera kedaluwarsa', 'expired' => 'Dokumen kedaluwarsa'],
            'documents' => ['vessel_name' => 'Vessel', 'document_type' => 'Document Type', 'certificate_number' => 'Document Number', 'issued_date' => 'Issued Date', 'expired_date' => 'Expired Date', 'document_status' => 'Status', 'days_remaining' => 'Days Remaining', 'edition' => 'Current / Version'],
            'staff' => ['name' => 'Staff', 'active' => 'Active Services Assigned', 'completed' => 'Completed Services', 'completed_spb' => 'Completed SPB', 'completed_skkp' => 'Completed SKKP', 'completed_bunkering' => 'Completed Bunkering', 'completed_sijil' => 'Completed Sijil', 'completed_endorsement' => 'Completed Endorsement', 'attention' => 'Need Attention', 'average_seconds' => 'Average Completion Time'],
            default => throw new \InvalidArgumentException('Jenis laporan tidak valid.'),
        };
    }

    /** @return array<string, string|int|float|null> */
    public function row(string $kind, stdClass $record): array
    {
        $values = [];
        foreach ($this->columns($kind) as $column => $label) {
            $value = $record->$column ?? null;
            if (in_array($column, ['duration_seconds', 'average_seconds'], true)) {
                $value = self::duration($value === null ? null : (float) $value);
            } elseif (in_array($column, ['issued_date', 'expired_date', 'certificate_expired_at'], true)) {
                $value = $value === null ? '—' : CarbonImmutable::parse($value)->toDateString();
            } elseif (str_ends_with($column, '_at')) {
                $value = self::date($value);
            } elseif ($column === 'days_remaining') {
                $value = self::daysRemaining($record->expired_date);
            } elseif ($column === 'report_type') {
                $value = TaskServiceRegistry::LABELS[$value];
            } elseif ($column === 'document_type') {
                $value = VesselDocument::TYPES[$value] ?? $value;
            } elseif ($column === 'status') {
                $value = TaskServiceRegistry::TYPES[$record->report_type]::STATUSES[$value];
            } elseif ($column === 'sailing_result') {
                $value = match ($value) {
                    'success' => 'Sail Completed', 'not_sailing' => 'Not Sailing',
                    'failed' => 'Fail to Sail',
                    'pending' => in_array($record->status, ['ready_to_sail', 'sailing'], true) ? 'Menunggu hasil' : '—', default => '—',
                };
            } elseif ($column === 'attention' && $kind === 'services') {
                $value = $value ? 'Yes' : 'No';
            }
            $values[$column] = $value ?? '—';
        }

        return $values;
    }

    public function rowUrl(string $kind, stdClass $row): ?string
    {
        return match ($kind) {
            'services' => route($row->report_type.'.show', $row->id),
            'vessels' => route('dashboard.vessel', ['vessel' => $row->id]),
            'documents' => route('dashboard.vessel', ['vessel' => $row->vessel_id]),
            default => null,
        };
    }

    public static function date(?string $value): string
    {
        return $value === null ? '—' : CarbonImmutable::parse($value, 'UTC')->timezone(config('spb.timezone'))->format('d M Y H:i');
    }

    public static function daysRemaining(?string $value): ?int
    {
        return $value === null ? null : (int) CarbonImmutable::today(config('app.timezone'))->diffInDays(CarbonImmutable::parse($value, config('app.timezone')), false);
    }

    /** @return array{vessels: \Illuminate\Database\Eloquent\Collection<int, Vessel>, staff: \Illuminate\Database\Eloquent\Collection<int, User>} */
    public function options(): array
    {
        return ['vessels' => Vessel::orderBy('vessel_name')->get(['id', 'vessel_name']), 'staff' => User::where('role', 'staff')->orderBy('name')->get(['id', 'name'])];
    }
}
