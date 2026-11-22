<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SijilServiceFactory;
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
 * @property int $vessel_id
 * @property int|null $client_id
 * @property int $created_by
 * @property int $assigned_staff_id
 * @property string $process_type
 * @property string $priority
 * @property string $status
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $resubmitted_at
 * @property string|null $submission_reference
 * @property string|null $authority
 * @property string|null $revision_notes
 * @property string|null $rejection_reason
 * @property string|null $result_reference
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $effective_at
 * @property CarbonImmutable|null $completed_at
 * @property int|null $completed_by
 * @property CarbonImmutable|null $archived_at
 * @property string|null $cancellation_reason
 * @property string|null $notes
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Vessel $vessel
 * @property-read Client|null $client
 * @property-read User $creator
 * @property-read User $assignedStaff
 * @property-read User|null $completedBy
 * @property-read Collection<int, SijilDocument> $documents
 * @property-read Collection<int, SijilStatusHistory> $histories
 * @property-read Collection<int, SijilServiceCrew> $crews
 * @property-read Collection<int, SijilServiceCrew> $allCrews
 */
#[Fillable(['service_number', 'vessel_id', 'client_id', 'created_by', 'assigned_staff_id', 'process_type', 'priority', 'status', 'due_at', 'submitted_at', 'resubmitted_at', 'submission_reference', 'authority', 'revision_notes', 'rejection_reason', 'result_reference', 'issued_at', 'effective_at', 'completed_at', 'completed_by', 'archived_at', 'cancellation_reason', 'notes'])]
class SijilService extends Model
{
    /** @use HasFactory<SijilServiceFactory> */
    use HasFactory;
    use HasServiceFee;

    /** @return MorphMany<Task, $this> */
    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['due_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime', 'resubmitted_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'archived_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime', 'issued_at' => 'immutable_date', 'effective_at' => 'immutable_date'];
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

    /** @return BelongsTo<User, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @return HasMany<SijilDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(SijilDocument::class);
    }

    /** @return HasMany<SijilStatusHistory, $this> */
    public function histories(): HasMany
    {
        return $this->hasMany(SijilStatusHistory::class);
    }

    /** @return HasMany<SijilServiceCrew, $this> */
    public function crews(): HasMany
    {
        return $this->hasMany(SijilServiceCrew::class)->whereNull('removed_at');
    }

    /** @return HasMany<SijilServiceCrew, $this> */
    public function allCrews(): HasMany
    {
        return $this->hasMany(SijilServiceCrew::class);
    }

    public const PRIORITIES = SpbService::PRIORITIES;

    public const PROCESS_TYPES = ['sign_on' => 'Crew Sign On', 'sign_off' => 'Crew Sign Off', 'crew_change' => 'Crew Change', 'registration_update' => 'Crew Registration / Update'];

    public const ACTIONS = ['sign_on' => 'Sign On', 'sign_off' => 'Sign Off', 'update' => 'Update'];

    public const ALLOWED_ACTIONS = ['sign_on' => ['sign_on'], 'sign_off' => ['sign_off'], 'crew_change' => ['sign_on', 'sign_off'], 'registration_update' => ['sign_on', 'update']];

    public const STATUSES = ['draft' => 'Draft', 'ready_to_apply' => 'Ready To Apply', 'under_review' => 'Under Review', 'revision_required' => 'Revision Required', 'rejected' => 'Rejected', 'ready_to_update_crew' => 'Ready To Update Crew', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

    public const ACTIVE = ['draft', 'ready_to_apply', 'under_review', 'revision_required', 'ready_to_update_crew'];

    public const TERMINAL = ['rejected', 'completed', 'cancelled'];

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'revision_required'], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status];
    }

    public function latestDocument(string $type): ?SijilDocument
    {
        return $this->documents->whereNull('sijil_service_crew_id')->where('document_type', $type)->sortByDesc('id')->first();
    }
}
