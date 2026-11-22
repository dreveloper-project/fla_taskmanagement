<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SafetyCertificateServiceFactory;
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
 * @property string $request_type
 * @property string $priority
 * @property string $status
 * @property int|null $current_certificate_document_id
 * @property array<string, mixed>|null $previous_certificate_snapshot
 * @property string|null $submission_reference
 * @property string|null $authority
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $resubmitted_at
 * @property string|null $revision_notes
 * @property string|null $rejection_reason
 * @property bool $inspection_required
 * @property CarbonImmutable|null $inspection_scheduled_at
 * @property string|null $inspection_location
 * @property string|null $inspector_name
 * @property string|null $inspection_notes
 * @property CarbonImmutable|null $inspection_completed_at
 * @property string|null $inspection_result
 * @property string|null $findings
 * @property string|null $certificate_number
 * @property CarbonImmutable|null $certificate_issued_at
 * @property CarbonImmutable|null $certificate_expired_at
 * @property string|null $issuing_authority
 * @property int|null $issued_certificate_document_id
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
 * @property-read User $assignedStaff
 * @property-read VesselDocument|null $currentCertificate
 * @property-read SafetyCertificateDocument|null $issuedCertificate
 * @property-read Collection<int, SafetyCertificateDocument> $documents
 * @property-read Collection<int, SafetyCertificateStatusHistory> $histories
 */
#[Fillable(['service_number', 'vessel_id', 'client_id', 'created_by', 'assigned_staff_id', 'request_type', 'priority', 'status', 'current_certificate_document_id', 'previous_certificate_snapshot', 'submission_reference', 'authority', 'submitted_at', 'resubmitted_at', 'revision_notes', 'rejection_reason', 'inspection_required', 'inspection_scheduled_at', 'inspection_location', 'inspector_name', 'inspection_notes', 'inspection_completed_at', 'inspection_result', 'findings', 'certificate_number', 'certificate_issued_at', 'certificate_expired_at', 'issuing_authority', 'issued_certificate_document_id', 'handed_over_at', 'handed_over_to', 'recipient_role', 'handed_over_by', 'completed_at', 'archived_at', 'due_at', 'notes'])]
class SafetyCertificateService extends Model
{
    /** @use HasFactory<SafetyCertificateServiceFactory> */
    use HasFactory;
    use HasServiceFee;

    public const REQUEST_TYPES = ['new_issue' => 'Penerbitan Baru', 'renewal' => 'Perpanjangan'];

    public const PRIORITIES = SpbService::PRIORITIES;

    public const STATUSES = [
        'draft' => 'Draft', 'ready_to_apply' => 'Ready To Apply', 'under_review' => 'Under Review',
        'revision_required' => 'Revision Required', 'inspection_scheduled' => 'Inspection Scheduled',
        'inspection_completed' => 'Inspection Completed', 'waiting_for_issuance' => 'Waiting For Issuance',
        'rejected' => 'Rejected', 'certificate_issued' => 'Certificate Issued',
        'ready_for_handover' => 'Ready For Handover', 'completed' => 'Completed',
    ];

    public const ACTIVE = ['draft', 'ready_to_apply', 'under_review', 'revision_required', 'inspection_scheduled', 'inspection_completed', 'waiting_for_issuance', 'certificate_issued', 'ready_for_handover'];

    public const TERMINAL = ['rejected', 'completed'];

    /** @return MorphMany<Task, $this> */
    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'inspection_required' => 'boolean', 'previous_certificate_snapshot' => 'array',
            'submitted_at' => 'immutable_datetime', 'resubmitted_at' => 'immutable_datetime',
            'inspection_scheduled_at' => 'immutable_datetime', 'inspection_completed_at' => 'immutable_datetime',
            'certificate_issued_at' => 'immutable_date', 'certificate_expired_at' => 'immutable_date',
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
    public function currentCertificate(): BelongsTo
    {
        return $this->belongsTo(VesselDocument::class, 'current_certificate_document_id');
    }

    /** @return BelongsTo<SafetyCertificateDocument, $this> */
    public function issuedCertificate(): BelongsTo
    {
        return $this->belongsTo(SafetyCertificateDocument::class, 'issued_certificate_document_id');
    }

    /** @return HasMany<SafetyCertificateDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(SafetyCertificateDocument::class);
    }

    /** @return HasMany<SafetyCertificateStatusHistory, $this> */
    public function histories(): HasMany
    {
        return $this->hasMany(SafetyCertificateStatusHistory::class);
    }

    public function latestDocument(string $type): ?SafetyCertificateDocument
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

    /** @param Builder<SafetyCertificateService> $query
     * @return Builder<SafetyCertificateService>
     */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE)->where(function (Builder $query): void {
            $query->where('status', 'revision_required')
                ->orWhere(fn (Builder $query) => $query->where('status', 'inspection_scheduled')->where('inspection_scheduled_at', '<', now()))
                ->orWhere('due_at', '<', now())
                ->orWhereHas('vessel.documents', fn (Builder $documents) => $documents->where('document_type', 'safety_certificate')->whereDate('expired_date', '<', today(config('app.timezone'))->toDateString()));
        });
    }
}
