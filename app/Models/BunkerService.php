<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BunkerServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property int $id
 * @property string $service_number
 * @property int $vessel_id
 * @property int|null $client_id
 * @property int $created_by
 * @property int $assigned_staff_id
 * @property string|null $supplier
 * @property string|null $bunker_location
 * @property string|null $bunker_type
 * @property string|null $quantity
 * @property string|null $unit
 * @property string|null $unit_other
 * @property CarbonImmutable|null $scheduled_at
 * @property string $priority
 * @property string $status
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $resubmitted_at
 * @property string|null $submission_reference
 * @property string|null $authority
 * @property string|null $revision_notes
 * @property string|null $rejection_reason
 * @property string|null $approval_number
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $actual_bunker_at
 * @property string|null $actual_quantity
 * @property string|null $actual_unit
 * @property string|null $actual_unit_other
 * @property string|null $activity_notes
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $archived_at
 * @property string|null $cancellation_reason
 * @property string|null $notes
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Vessel $vessel
 * @property-read Client|null $client
 * @property-read User $creator
 * @property-read User $assignedStaff
 * @property-read Collection<int, BunkerDocument> $documents
 * @property-read Collection<int, BunkerStatusHistory> $histories
 */
#[Fillable(['service_number', 'vessel_id', 'client_id', 'created_by', 'assigned_staff_id', 'supplier', 'bunker_location', 'bunker_type', 'quantity', 'unit', 'unit_other', 'scheduled_at', 'priority', 'status', 'submitted_at', 'resubmitted_at', 'submission_reference', 'authority', 'revision_notes', 'rejection_reason', 'approval_number', 'approved_at', 'actual_bunker_at', 'actual_quantity', 'actual_unit', 'actual_unit_other', 'activity_notes', 'completed_at', 'archived_at', 'cancellation_reason', 'notes'])]
class BunkerService extends Model
{
    /** @use HasFactory<BunkerServiceFactory> */
    use HasFactory;
    use HasServiceFee;

    public const PRIORITIES = SpbService::PRIORITIES;

    public const UNITS = ['liter' => 'Liter', 'kl' => 'KL', 'mt' => 'MT', 'other' => 'Lainnya'];

    public const STATUSES = [
        'draft' => 'Draft', 'ready_to_apply' => 'Ready To Apply', 'under_review' => 'Under Review',
        'revision_required' => 'Revision Required', 'rejected' => 'Rejected', 'approved' => 'Approved',
        'bunker_scheduled' => 'Bunker Scheduled', 'bunker_completed' => 'Bunker Completed',
        'completed' => 'Completed', 'cancelled' => 'Cancelled',
    ];

    public const ACTIVE = ['draft', 'ready_to_apply', 'under_review', 'revision_required', 'approved', 'bunker_scheduled', 'bunker_completed'];

    public const TERMINAL = ['rejected', 'completed', 'cancelled'];

    /** @return MorphMany<Task, $this> */
    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3', 'actual_quantity' => 'decimal:3',
            'scheduled_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime',
            'resubmitted_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime',
            'actual_bunker_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime',
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

    /** @return HasMany<BunkerDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(BunkerDocument::class);
    }

    /** @return HasMany<BunkerStatusHistory, $this> */
    public function histories(): HasMany
    {
        return $this->hasMany(BunkerStatusHistory::class);
    }

    public function latestDocument(string $type): ?BunkerDocument
    {
        return $this->documents->where('document_type', $type)->sortByDesc('id')->first();
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'revision_required'], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status];
    }

    public function unitLabel(bool $actual = false): string
    {
        $unit = $actual ? $this->actual_unit : $this->unit;

        return $unit === 'other' ? (($actual ? $this->actual_unit_other : $this->unit_other) ?? 'Lainnya') : (self::UNITS[$unit ?? ''] ?? '-');
    }

    /** @param Builder<BunkerService> $query
     * @return Builder<BunkerService>
     */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereIn('status', ['revision_required', 'rejected'])
                ->orWhere(fn (Builder $query) => $query->whereIn('status', self::ACTIVE)->where('scheduled_at', '<', now()));
        });
    }
}
