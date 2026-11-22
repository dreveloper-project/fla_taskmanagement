<?php

namespace App\Models;

use Database\Factories\CrewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $full_name
 * @property string|null $seafarer_book_number
 * @property string|null $competency_certificate_number
 * @property-read Collection<int, VesselCrew> $assignments
 * @property-read Collection<int, SijilDocument> $documents
 */
#[Fillable(['full_name', 'seafarer_book_number', 'competency_certificate_number'])]
class Crew extends Model
{
    /** @use HasFactory<CrewFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [];
    }

    /** @return HasMany<VesselCrew, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(VesselCrew::class);
    }

    /** @return HasMany<SijilDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(SijilDocument::class);
    }
}
