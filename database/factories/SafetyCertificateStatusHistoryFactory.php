<?php

namespace Database\Factories;

use App\Models\SafetyCertificateService;
use App\Models\SafetyCertificateStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SafetyCertificateStatusHistory> */
class SafetyCertificateStatusHistoryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'safety_certificate_service_id' => SafetyCertificateService::factory(),
            'event' => 'Service Created', 'from_status' => null, 'to_status' => 'draft',
            'changed_by' => User::factory(),
        ];
    }
}
