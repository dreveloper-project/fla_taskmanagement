<?php

namespace App\Services;

use App\Models\BunkerService;
use App\Models\SafetyCertificateService;
use App\Models\ShipNationalityService;
use App\Models\SijilService;
use App\Models\SpbService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class TaskServiceRegistry
{
    public const TYPES = [
        'spb' => SpbService::class, 'safety-certificates' => SafetyCertificateService::class,
        'bunker' => BunkerService::class, 'sijil' => SijilService::class, 'ship-nationality' => ShipNationalityService::class,
    ];

    public const LABELS = ['spb' => 'SPB', 'safety-certificates' => 'Sertifikat Keselamatan', 'bunker' => 'Izin Bunker', 'sijil' => 'Sijil Awak Kapal', 'ship-nationality' => 'Surat Laut / Pas Besar / Pas Kecil'];

    public function resolve(string $type, int $id): SpbService|SafetyCertificateService|BunkerService|SijilService|ShipNationalityService
    {
        $class = self::TYPES[$type] ?? null;
        if ($class === null) {
            throw ValidationException::withMessages(['service_type' => 'Jenis layanan tidak valid.']);
        }

        return $class::query()->lockForUpdate()->findOrFail($id);
    }

    /** @return array<string, string> */
    public function options(): array
    {
        $options = [];
        foreach (self::TYPES as $type => $class) {
            foreach ($class::query()->whereIn('status', $class::ACTIVE)->orderByDesc('id')->get(['id', 'service_number']) as $service) {
                $options[$type.':'.$service->id] = self::LABELS[$type].' · '.$service->service_number;
            }
        }

        return $options;
    }

    /** @return array{complete: bool, missing: list<string>, vessel_checks: array<int, mixed>, crew_checks?: array<int, list<string>>} */
    public function readiness(Model $service): array
    {
        $checker = match ($service::class) {
            SpbService::class => SpbReadinessService::class,
            SafetyCertificateService::class => SafetyCertificateReadinessService::class,
            BunkerService::class => BunkerReadinessService::class,
            SijilService::class => SijilReadinessService::class,
            ShipNationalityService::class => ShipNationalityReadinessService::class,
        };

        return app($checker)->check($service);
    }

    public function ensureActive(Model $service): void
    {
        $class = $service::class;
        if (! in_array($class, self::TYPES, true) || ! in_array($service->getAttribute('status'), $class::ACTIVE, true)) {
            throw ValidationException::withMessages(['related_service' => 'Pilih layanan yang masih aktif.']);
        }
    }
}
