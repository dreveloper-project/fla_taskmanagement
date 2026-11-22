<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ShipNationalityStatusHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ship_nationality_service_id
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
class ShipNationalityStatusHistory extends Model
{
    /** @use HasFactory<ShipNationalityStatusHistoryFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ShipNationalityService, $this> */
    public function shipNationalityService(): BelongsTo
    {
        return $this->belongsTo(ShipNationalityService::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
