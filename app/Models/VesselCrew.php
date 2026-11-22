<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\VesselCrewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $vessel_id
 * @property int $crew_id
 * @property int|null $active_crew_id
 * @property string $full_name
 * @property string $rank
 * @property string|null $seafarer_book_number
 * @property string|null $competency_certificate_number
 * @property CarbonImmutable $sign_on_at
 * @property CarbonImmutable|null $sign_off_at
 * @property CarbonImmutable $last_changed_at
 * @property int $revision
 * @property int $sign_on_service_id
 * @property int|null $sign_off_service_id
 * @property-read Vessel $vessel
 * @property-read Crew $crew
 * @property-read SijilService|null $signOnService
 * @property-read SijilService|null $signOffService
 */
#[Fillable(['vessel_id', 'crew_id', 'active_crew_id', 'full_name', 'rank', 'seafarer_book_number', 'competency_certificate_number', 'sign_on_at', 'sign_off_at', 'last_changed_at', 'revision', 'sign_on_service_id', 'sign_off_service_id'])]
class VesselCrew extends Model
{
    /** @use HasFactory<VesselCrewFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sign_on_at' => 'immutable_date', 'sign_off_at' => 'immutable_date', 'last_changed_at' => 'immutable_date'];
    }

    /** @return BelongsTo<Vessel, $this> */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /** @return BelongsTo<Crew, $this> */
    public function crew(): BelongsTo
    {
        return $this->belongsTo(Crew::class);
    }

    /** @return BelongsTo<SijilService, $this> */
    public function signOnService(): BelongsTo
    {
        return $this->belongsTo(SijilService::class, 'sign_on_service_id');
    }

    /** @return BelongsTo<SijilService, $this> */
    public function signOffService(): BelongsTo
    {
        return $this->belongsTo(SijilService::class, 'sign_off_service_id');
    }
}
