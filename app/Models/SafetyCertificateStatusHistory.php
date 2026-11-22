<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SafetyCertificateStatusHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $safety_certificate_service_id
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
class SafetyCertificateStatusHistory extends Model
{
    /** @use HasFactory<SafetyCertificateStatusHistoryFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<SafetyCertificateService, $this> */
    public function safetyCertificateService(): BelongsTo
    {
        return $this->belongsTo(SafetyCertificateService::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
