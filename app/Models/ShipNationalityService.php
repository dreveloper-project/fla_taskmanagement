<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ShipNationalityServiceFactory;
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
 * @property int|null $assigned_staff_id
 * @property string $document_type
 * @property string|null $cancellation_reason
 * @property string $request_type
 * @property string $priority
 * @property string $status
 * @property int|null $previous_document_id
 * @property array<string, mixed>|null $previous_document_snapshot
 * @property string|null $submission_reference
 * @property string|null $authority
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $resubmitted_at
 * @property string|null $revision_notes
 * @property string|null $rejection_reason
 * @property string|null $issued_document_number
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $expired_at
 * @property string|null $issuing_authority
 * @property int|null $issued_document_id
 * @property CarbonImmutable|null $handed_over_at
 * @property string|null $handed_over_to
 * @property string|null $recipient_role
 * @property int|null $handed_over_by
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $due_at
 * @property string|null $notes
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Vessel $vessel
 * @property-read Client|null $client
 * @property-read User $creator
 * @property-read User|null $assignedStaff
 * @property-read VesselDocument|null $previousDocument
 * @property-read ShipNationalityDocument|null $issuedDocument
 * @property-read Collection<int, ShipNationalityDocument> $documents
 * @property-read Collection<int, ShipNationalityStatusHistory> $histories
 */
#[Fillable(['service_number', 'vessel_id', 'client_id', 'created_by', 'assigned_staff_id', 'document_type', 'request_type', 'priority', 'status', 'previous_document_id', 'previous_document_snapshot', 'submission_reference', 'authority', 'submitted_at', 'resubmitted_at', 'revision_notes', 'rejection_reason', 'cancellation_reason', 'issued_document_number', 'issued_at', 'expired_at', 'issuing_authority', 'issued_document_id', 'handed_over_at', 'handed_over_to', 'recipient_role', 'handed_over_by', 'completed_at', 'archived_at', 'due_at', 'notes'])]
class ShipNationalityService extends Model
{
    /** @use HasFactory<ShipNationalityServiceFactory> */
    use HasFactory;
    use HasServiceFee;

    public const REQUEST_TYPES = ['new_issue' => 'Penerbitan Baru', 'renewal' => 'Perpanjangan'];

    public const PRIORITIES = SpbService::PRIORITIES;

    public const DOCUMENT_TYPES = ['surat_laut' => 'Surat Laut', 'pas_besar' => 'Pas Besar', 'pas_kecil' => 'Pas Kecil'];

    public const STATUSES = [
        'draft' => 'Draft', 'ready_to_apply' => 'Ready To Apply', 'under_review' => 'Under Review',
        'revision_required' => 'Revision Required', 'rejected' => 'Rejected', 'document_issued' => 'Document Issued',
        'ready_for_handover' => 'Ready For Handover', 'completed' => 'Completed', 'cancelled' => 'Cancelled',
    ];

    public const ACTIVE = ['draft', 'ready_to_apply', 'under_review', 'revision_required', 'document_issued', 'ready_for_handover'];

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
            'previous_document_snapshot' => 'array',
            'submitted_at' => 'immutable_datetime', 'resubmitted_at' => 'immutable_datetime',
            'issued_at' => 'immutable_date', 'expired_at' => 'immutable_date',
            'handed_over_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime', 'due_at' => 'immutable_datetime',
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

    /** @return BelongsTo<VesselDocument, $this> */
    public function previousDocument(): BelongsTo
    {
        return $this->belongsTo(VesselDocument::class, 'previous_document_id');
    }

    /** @return BelongsTo<ShipNationalityDocument, $this> */
    public function issuedDocument(): BelongsTo
    {
        return $this->belongsTo(ShipNationalityDocument::class, 'issued_document_id');
    }

    /** @return HasMany<ShipNationalityDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(ShipNationalityDocument::class);
    }

    /** @return HasMany<ShipNationalityStatusHistory, $this> */
    public function histories(): HasMany
    {
        return $this->hasMany(ShipNationalityStatusHistory::class);
    }

    public function latestDocument(string $type): ?ShipNationalityDocument
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

    /** @param Builder<ShipNationalityService> $query
     * @return Builder<ShipNationalityService>
     */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE)->where(function (Builder $query): void {
            $query->where('status', 'revision_required')->orWhere('due_at', '<', now())
                ->orWhereHas('vessel.documents', fn (Builder $documents) => $documents
                    ->whereColumn('vessel_documents.document_type', 'ship_nationality_services.document_type')
                    ->whereDate('expired_date', '<', today(config('app.timezone'))->toDateString()));
        });
    }
}
