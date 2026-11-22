<?php

namespace App\Services;

use App\Models\BunkerService;
use App\Models\SafetyCertificateService;
use App\Models\ShipNationalityService;
use App\Models\SijilService;
use App\Models\SijilServiceCrew;
use App\Models\SpbService;
use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Models\VesselDocumentVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ServiceDeletion
{
    public function delete(SpbService|SafetyCertificateService|BunkerService|SijilService|ShipNationalityService $service): void
    {
        Gate::authorize('delete', $service->fresh() ?? $service);
        $disk = Storage::disk('local');
        $staged = [];

        try {
            DB::transaction(function () use ($service, $disk, &$staged): void {
                Vessel::query()->lockForUpdate()->findOrFail($service->vessel_id);
                $locked = $service::query()->lockForUpdate()->findOrFail($service->id);
                Gate::authorize('delete', $locked);

                $type = array_search($locked::class, TaskServiceRegistry::TYPES, true);
                $prefixes = [$type.'/'.$locked->id.'/'];
                $paths = $locked->documents()->pluck('file_path');
                $tasks = $locked->tasks()->with('attachments')->orderBy('id')->lockForUpdate()->get();
                foreach ($tasks as $task) {
                    $prefixes[] = 'tasks/'.$task->id.'/';
                    $paths = $paths->merge($task->attachments->pluck('file_path'));
                }
                $protected = VesselDocument::whereIn('file_path', $paths)->pluck('file_path')
                    ->merge(VesselDocumentVersion::whereIn('file_path', $paths)->pluck('file_path'));
                foreach ($paths->unique()->diff($protected) as $path) {
                    if (! Str::startsWith($path, $prefixes) || str_contains($path, '..') || str_contains($path, '\\') || ! $disk->exists($path)) {
                        continue;
                    }
                    $temporary = dirname($path).'/.hapus-'.Str::uuid();
                    if (! $disk->move($path, $temporary)) {
                        throw new RuntimeException('Dokumen service gagal disiapkan untuk dihapus.');
                    }
                    $staged[$path] = $temporary;
                }

                $source = match ($locked::class) {
                    SafetyCertificateService::class => 'source_safety_certificate_service_id',
                    ShipNationalityService::class => 'source_ship_nationality_service_id',
                    default => null,
                };
                if ($source !== null) {
                    VesselDocument::where($source, $locked->id)->update([$source => null]);
                    VesselDocumentVersion::where($source, $locked->id)->update([$source => null]);
                    $issued = $locked instanceof SafetyCertificateService ? 'issued_certificate_document_id' : 'issued_document_id';
                    $locked->forceFill([$issued => null])->save();
                }
                foreach ($tasks as $task) {
                    if (! $task->delete()) {
                        throw new RuntimeException('Task terkait gagal dihapus.');
                    }
                }
                $locked->documents()->delete();
                $locked->histories()->delete();
                if ($locked instanceof SijilService) {
                    SijilServiceCrew::where('sijil_service_id', $locked->id)->delete();
                }
                if (! $locked->delete()) {
                    throw new RuntimeException('Service gagal dihapus.');
                }
            });
        } catch (Throwable $exception) {
            foreach ($staged as $original => $temporary) {
                try {
                    if (! $disk->move($temporary, $original)) {
                        report(new RuntimeException('Dokumen service gagal dipulihkan: '.$original));
                    }
                } catch (Throwable $restoreException) {
                    report($restoreException);
                }
            }
            throw $exception;
        }

        foreach ($staged as $temporary) {
            try {
                if (! $disk->delete($temporary)) {
                    report(new RuntimeException('File sementara service gagal dibersihkan: '.$temporary));
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
