<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SijilStatusHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $sijil_service_id
 * @property string $event
 * @property string|null $from_status
 * @property string $to_status
 * @property int $changed_by
 * @property string|null $notes
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable $created_at
 * @property-read User $actor
 */
#[Fillable(['event', 'from_status', 'to_status', 'changed_by', 'notes', 'metadata'])]
class SijilStatusHistory extends Model
{
    /** @use HasFactory<SijilStatusHistoryFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<SijilService, $this> */
    public function sijilService(): BelongsTo
    {
        return $this->belongsTo(SijilService::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
