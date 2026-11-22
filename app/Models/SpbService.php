<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SpbServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property int $id
 * @property string $service_number
 * @property string $service_type Legacy category, retained for historical data only.
 * @property string|null $cargo_status
 * @property string|null $cargo_description
 * @property string|null $cargo_type
 * @property string|null $cargo_quantity
 * @property string|null $cargo_unit
 * @property bool $port_cargo_activity_applicable
 * @property int $vessel_id
 * @property int|null $client_id
 * @property int $created_by
 * @property int|null $assigned_staff_id
 * @property string $priority
 * @property int $total_crew
 * @property int $total_passengers
 * @property bool $passengers_none
 * @property string $departure_port
 * @property string $destination
 * @property CarbonImmutable $estimated_departure_at
 * @property CarbonImmutable|null $estimated_port_activity_completion_at
 * @property string $status
 * @property string|null $inaportnet_reference
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $resubmitted_at
 * @property CarbonImmutable|null $revision_ready_at
 * @property string|null $revision_notes
 * @property string|null $revision_items
 * @property string|null $rejection_reason
 * @property string|null $spb_number
 * @property string|null $billing_code_labuh
 * @property string|null $billing_code_rambu
 * @property string|null $billing_code_mastercable
 * @property bool $arrival_spb_enabled
 * @property string|null $arrival_billing_code_labuh
 * @property string|null $arrival_billing_code_rambu
 * @property string|null $arrival_billing_code_mastercable
 * @property string|null $arrival_spb_number
 * @property string|null $arrival_pnbp_labuh
 * @property string|null $arrival_pnbp_mastercable
 * @property CarbonImmutable|null $spb_issued_at
 * @property CarbonImmutable|null $ready_to_sail_at
 * @property CarbonImmutable|null $actual_departure_at
 * @property CarbonImmutable|null $expected_arrival_at
 * @property CarbonImmutable|null $actual_arrival_at
 * @property int|null $arrival_confirmed_by
 * @property int|null $confirmed_by
 * @property bool $billable
 * @property string $sailing_result
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $archived_at
 * @property string|null $notes
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Vessel $vessel
 * @property-read Client|null $client
 * @property-read User $creator
 * @property-read User|null $assignedStaff
 * @property-read Collection<int, SpbDocument> $documents
 * @property-read Collection<int, SpbStatusHistory> $histories
 */
#[Fillable(['service_number', 'service_type', 'vessel_id', 'client_id', 'created_by', 'assigned_staff_id', 'priority', 'total_crew', 'total_passengers', 'passengers_none', 'cargo_status', 'cargo_description', 'cargo_type', 'cargo_quantity', 'cargo_unit', 'port_cargo_activity_applicable', 'billing_code_labuh', 'billing_code_rambu', 'billing_code_mastercable', 'arrival_spb_enabled', 'arrival_billing_code_labuh', 'arrival_billing_code_rambu', 'arrival_billing_code_mastercable', 'departure_port', 'destination', 'estimated_departure_at', 'estimated_port_activity_completion_at', 'status', 'inaportnet_reference', 'submitted_at', 'resubmitted_at', 'revision_ready_at', 'revision_notes', 'revision_items', 'rejection_reason', 'spb_number', 'spb_issued_at', 'ready_to_sail_at', 'actual_departure_at', 'confirmed_by', 'sailing_result', 'failure_reason', 'completed_at', 'archived_at', 'notes'])]
class SpbService extends Model
{
    /** @use HasFactory<SpbServiceFactory> */
    use HasFactory;

    use HasServiceFee;

    public const STATUSES = [
        'draft' => 'Draft', 'ready_to_apply' => 'Ready To Apply',
        'applied' => 'Applied / Under Review', 'revision_required' => 'Revision Required',
        'rejected' => 'Rejected', 'ready_to_sail' => 'Ready To Sail',
        'sailing' => 'Sailing', 'completed' => 'Completed', 'failed_to_sail' => 'Failed To Sail',
    ];

    public const CARGO_STATUSES = ['not_applicable' => 'Tidak berlaku', 'no_cargo' => 'No Cargo', 'carrying_cargo' => 'Carrying Cargo'];

    public const PRIORITIES = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'];

    public const PENDING = ['draft', 'ready_to_apply', 'applied', 'revision_required'];

    public const ACTIVE = ['draft', 'ready_to_apply', 'applied', 'revision_required', 'ready_to_sail', 'sailing'];

    public const TERMINAL = ['rejected', 'completed', 'failed_to_sail'];

    /** @return MorphMany<Task, $this> */
    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'billable' => 'boolean', 'passengers_none' => 'boolean', 'port_cargo_activity_applicable' => 'boolean', 'arrival_spb_enabled' => 'boolean', 'cargo_quantity' => 'decimal:3',
            'total_crew' => 'integer', 'total_passengers' => 'integer',
            'estimated_departure_at' => 'immutable_datetime',
            'estimated_port_activity_completion_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime', 'resubmitted_at' => 'immutable_datetime',
            'revision_ready_at' => 'immutable_datetime', 'spb_issued_at' => 'immutable_datetime',
            'ready_to_sail_at' => 'immutable_datetime', 'actual_departure_at' => 'immutable_datetime',
            'actual_arrival_at' => 'immutable_datetime',
            'expected_arrival_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime', 'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Vessel, $this> */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_staff_id');
    }

    /** @return HasMany<SpbDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(SpbDocument::class)->chaperone('spbService');
    }

    /** @return HasMany<SpbStatusHistory, $this> */
    public function histories(): HasMany
    {
        return $this->hasMany(SpbStatusHistory::class);
    }

    /** @return HasMany<FinanceEntry, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(FinanceEntry::class)->where('type', 'income');
    }

    public function latestDocument(string $type): ?SpbDocument
    {
        return $this->documents->where('document_type', $type)->sortByDesc('id')->first();
    }

    public function hasPassengerInformation(): bool
    {
        return $this->vessel->vessel_type === 'passenger' || $this->total_passengers > 0;
    }

    public function hasActiveLegacyVoyage(): bool
    {
        return $this->status === 'sailing'
            && $this->actual_arrival_at === null
            && $this->actual_departure_at !== null
            && $this->vessel->operational_status === 'sailing'
            && ($this->vessel->sailed_at?->equalTo($this->actual_departure_at) ?? false);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'revision_required', 'completed'], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status];
    }
}
