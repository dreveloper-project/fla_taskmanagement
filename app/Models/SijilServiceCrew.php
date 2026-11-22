<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SijilServiceCrewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $sijil_service_id
 * @property int|null $crew_id
 * @property int|null $vessel_crew_id
 * @property int|null $expected_revision
 * @property string|null $full_name
 * @property string|null $rank
 * @property string|null $seafarer_book_number
 * @property string|null $competency_certificate_number
 * @property string $action_type
 * @property string|null $notes
 * @property array<string, mixed>|null $before_snapshot
 * @property array<string, mixed>|null $after_snapshot
 * @property CarbonImmutable|null $removed_at
 * @property-read SijilService|null $sijilService
 * @property-read Crew|null $crew
 * @property-read VesselCrew|null $assignment
 * @property-read Collection<int, SijilDocument> $documents
 */
#[Fillable(['sijil_service_id', 'crew_id', 'vessel_crew_id', 'expected_revision', 'full_name', 'rank', 'seafarer_book_number', 'competency_certificate_number', 'action_type', 'notes', 'before_snapshot', 'after_snapshot', 'removed_at'])]
class SijilServiceCrew extends Model
{
    /** @use HasFactory<SijilServiceCrewFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['before_snapshot' => 'array', 'after_snapshot' => 'array', 'removed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<SijilService, $this> */
    public function sijilService(): BelongsTo
    {
        return $this->belongsTo(SijilService::class);
    }

    /** @return BelongsTo<Crew, $this> */
    public function crew(): BelongsTo
    {
        return $this->belongsTo(Crew::class);
    }

    /** @return BelongsTo<VesselCrew, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(VesselCrew::class, 'vessel_crew_id');
    }

    /** @return HasMany<SijilDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(SijilDocument::class);
    }
}
