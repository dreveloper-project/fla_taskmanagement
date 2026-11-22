<?php

namespace App\Models;

use App\Services\TaskServiceRegistry;
use Carbon\CarbonImmutable;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string|null $taskable_type
 * @property int|null $taskable_id
 * @property int|null $vessel_id
 * @property int|null $client_id
 * @property string $title
 * @property string|null $description
 * @property string|null $task_key
 * @property int|null $assigned_to
 * @property int $created_by
 * @property string $priority
 * @property string $status
 * @property int $progress
 * @property string|null $notes
 * @property bool $is_system_generated
 * @property CarbonImmutable|null $start_at
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $assignment_seen_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Model|null $taskable
 * @property-read Vessel|null $vessel
 * @property-read Client|null $client
 * @property-read User|null $assignedStaff
 * @property-read User $creator
 */
#[Fillable(['taskable_type', 'taskable_id', 'vessel_id', 'client_id', 'title', 'description', 'task_key', 'assigned_to', 'created_by', 'priority', 'status', 'progress', 'start_at', 'due_at', 'started_at', 'completed_at', 'notes', 'is_system_generated'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    public const STATUSES = ['pending' => 'Perlu dilengkapi', 'in_progress' => 'Sedang dilengkapi', 'in_review' => 'Perlu diperiksa', 'completed' => 'Sudah dilengkapi', 'cancelled' => 'Dibatalkan'];

    public const ACTIVE = ['pending', 'in_progress', 'in_review'];

    public const PRIORITIES = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'];

    public const NEXT = ['pending' => 'in_progress', 'in_progress' => 'in_review', 'in_review' => 'completed'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['progress' => 'integer', 'is_system_generated' => 'boolean', 'start_at' => 'immutable_datetime', 'due_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'assignment_seen_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (Task $task): void {
            if ($task->exists && $task->isDirty('assigned_to')) {
                $task->assignment_seen_at = null;
            }
        });
    }

    /** @param Builder<Task> $query
     * @return Builder<Task>
     */
    public function scopeUnseenAssignmentsFor(Builder $query, User $user): Builder
    {
        return $query->visibleTo($user)->active()->where('assigned_to', $user->id)->whereNull('assignment_seen_at');
    }

    /** @return MorphTo<Model, $this> */
    public function taskable(): MorphTo
    {
        return $this->morphTo();
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
    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<TaskHistory, $this> */
    public function histories(): HasMany
    {
        return $this->hasMany(TaskHistory::class)->orderBy('id');
    }

    /** @return HasMany<TaskAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class);
    }

    /** @param Builder<Task> $query
     * @return Builder<Task>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE);
    }

    /** @param Builder<Task> $query
     * @return Builder<Task>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->active()->where('due_at', '<', now());
    }

    /** @param Builder<Task> $query
     * @return Builder<Task>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->isActive() || (! $user->isStaff() && ! $user->isAdmin())) {
            return $query->whereRaw('1 = 0');
        }

        return $user->isAdmin() ? $query : $query->where('assigned_to', $user->id);
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, self::ACTIVE, true) && $this->due_at?->isPast();
    }

    public function number(): string
    {
        return 'TSK-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function serviceUrl(): ?string
    {
        $type = array_search($this->taskable_type, TaskServiceRegistry::TYPES, true);

        return $type !== false && $this->taskable !== null ? route($type.'.show', $this->taskable_id) : null;
    }
}
