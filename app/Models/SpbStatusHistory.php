<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SpbStatusHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $spb_service_id
 * @property string $event
 * @property string|null $from_status
 * @property string $to_status
 * @property int $changed_by
 * @property string|null $notes
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable $created_at
 * @property-read SpbService $spbService
 * @property-read User $actor
 */
#[Fillable(['event', 'from_status', 'to_status', 'changed_by', 'notes', 'metadata'])]
class SpbStatusHistory extends Model
{
    /** @use HasFactory<SpbStatusHistoryFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<SpbService, $this> */
    public function spbService(): BelongsTo
    {
        return $this->belongsTo(SpbService::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
