<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string|null $profile_picture_path
 * @property int $id
 * @property string $name
 * @property string|null $identity_number
 */
#[Fillable(['name', 'identity_number', 'service_prices'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['billing_day' => 'integer', 'next_billing_on' => 'immutable_date', 'service_prices' => 'array'];
    }

    /** @return HasMany<Vessel, $this> */
    public function vessels(): HasMany
    {
        return $this->hasMany(Vessel::class);
    }

    /** @return HasMany<SpbService, $this> */
    public function spbServices(): HasMany
    {
        return $this->hasMany(SpbService::class);
    }
}
