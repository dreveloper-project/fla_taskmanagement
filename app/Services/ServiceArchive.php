<?php

namespace App\Services;

use App\Models\BunkerService;
use App\Models\SafetyCertificateService;
use App\Models\ShipNationalityService;
use App\Models\SijilService;
use App\Models\SpbService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipArchive;

class ServiceArchive
{
    /**
     * @param  ?Closure(int, string): void  $progress
     * @param  ?Closure(): bool  $cancelled
     */
    public function create(SpbService|SafetyCertificateService|BunkerService|SijilService|ShipNationalityService $service, ?Closure $progress = null, ?Closure $cancelled = null): string
    {
        return $this->writeArchive(function (ZipArchive $archive) use ($service, $progress, $cancelled): void {
            $folder = Str::slug($service->service_number) ?: 'layanan-'.$service->id;
            $this->addServiceFiles($archive, $service, $folder, $cancelled);
            $progress?->__invoke(20, 'Berkas terkumpul. Mengompres ZIP…');
        }, progress: $progress, cancelled: $cancelled);
    }

    /**
     * @param  Builder<SpbService>  $services
     * @param  ?Closure(int, string): void  $progress
     * @param  ?Closure(): bool  $cancelled
     */
    public function createCompletedSpbs(Builder $services, ?Closure $progress = null, ?Closure $cancelled = null): string
    {
        return $this->createCompletedServices($services, $progress, $cancelled);
    }

    /**
     * @param  Builder<SpbService|SafetyCertificateService|BunkerService|SijilService|ShipNationalityService>  $services
     * @param  ?Closure(int, string): void  $progress
     * @param  ?Closure(): bool  $cancelled
     */
    public function createCompletedServices(Builder $services, ?Closure $progress = null, ?Closure $cancelled = null): string
    {
        $type = array_search($services->getModel()::class, TaskServiceRegistry::TYPES, true);
        if ($type === false) {
            throw ValidationException::withMessages(['archive' => 'Jenis layanan tidak didukung.']);
        }
        $label = $type === 'spb' ? 'SPB' : 'layanan';
        $prefix = strtoupper($type);
        $dataFilename = $type === 'spb' ? 'Data-SPB.json' : 'Data-Layanan.json';

        return $this->writeArchive(function (ZipArchive $archive) use ($services, $progress, $cancelled, $type, $label, $prefix, $dataFilename): void {
            $total = $progress !== null ? (clone $services)->where('status', 'completed')->count() : 0;
            $processed = 0;
            foreach ($services->where('status', 'completed')->with(['vessel', 'assignedStaff'])->reorder()->lazyById(50) as $service) {
                $this->ensureNotCancelled($cancelled);
                $folder = $prefix.'-'.$service->id.'-'.Str::limit(Str::slug($service->service_number), 100, '');
                $data = json_encode([
                    'service_type' => $type,
                    'service' => array_diff_key($service->attributesToArray(), array_fill_keys([
                        'client_id', 'priority', 'agent_fee', 'billable', 'pnbp_labuh', 'pnbp_tambat',
                        'pnbp_mastercable', 'arrival_pnbp_labuh', 'arrival_pnbp_mastercable',
                    ], true)),
                    'vessel' => $service->vessel->only(['id', 'vessel_name', 'vessel_type', 'flag', 'call_sign', 'registry_number']),
                    'pic' => $service->assignedStaff?->only(['id', 'name']),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if (! $archive->addFromString($folder.'/'.$dataFilename, $data)) {
                    throw new RuntimeException('Data layanan tidak dapat ditambahkan ke arsip.');
                }
                try {
                    $this->addServiceFiles($archive, $service, $folder, $cancelled);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(['archive' => $service->service_number.': '.$exception->errors()['archive'][0]]);
                }
                $processed++;
                $progress?->__invoke(min(20, (int) floor(20 * $processed / max(1, $total))), 'Mengumpulkan data dan berkas '.$label.': '.$processed.' / '.$total);
            }
        }, 'Tidak ada '.$label.' Completed yang sesuai dengan filter.', $progress, $cancelled);
    }

    /**
     * @param  Closure(ZipArchive): void  $write
     * @param  ?Closure(int, string): void  $progress
     * @param  ?Closure(): bool  $cancelled
     */
    private function writeArchive(Closure $write, string $emptyMessage = 'Belum ada berkas yang dapat diunduh untuk layanan ini.', ?Closure $progress = null, ?Closure $cancelled = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fla-archive-');
        if ($path === false) {
            throw new RuntimeException('File sementara arsip tidak dapat dibuat.');
        }

        $archive = new ZipArchive;
        $archiveOpen = false;
        $completed = false;

        try {
            if ($archive->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Arsip ZIP tidak dapat dibuat.');
            }
            $archiveOpen = true;
            if ($progress !== null) {
                $archive->registerProgressCallback(0.01, function (float $fraction) use ($progress): void {
                    $progress(min(99, 20 + (int) floor($fraction * 79)), 'Mengompres ZIP…');
                });
            }
            if ($cancelled !== null) {
                $archive->registerCancelCallback(fn (): int => $cancelled() ? 1 : 0);
            }
            $this->ensureNotCancelled($cancelled);
            $write($archive);

            if ($archive->numFiles === 0) {
                throw ValidationException::withMessages(['archive' => $emptyMessage]);
            }
            $this->ensureNotCancelled($cancelled);
            $closed = @$archive->close();
            $archiveOpen = false;
            if (! $closed) {
                $this->ensureNotCancelled($cancelled);
                throw new RuntimeException('Arsip ZIP tidak dapat diselesaikan.');
            }
            clearstatcache(true, $path);
            $this->ensureNotCancelled($cancelled);
            $progress?->__invoke(100, 'ZIP siap diunduh.');
            $completed = true;

            return $path;
        } finally {
            if ($archiveOpen) {
                $archive->registerCancelCallback(fn (): int => 1);
                @$archive->close();
            }
            unset($archive);
            if (! $completed && is_file($path)) {
                unlink($path);
            }
        }
    }

    /** @param ?Closure(): bool $cancelled */
    private function addServiceFiles(ZipArchive $archive, SpbService|SafetyCertificateService|BunkerService|SijilService|ShipNationalityService $service, string $folder, ?Closure $cancelled = null): void
    {
        $service->loadMissing('vessel');
        foreach ($service->documents()->lazyById(100) as $document) {
            $this->ensureNotCancelled($cancelled);
            $filename = DocumentFilename::forService($document, $service->vessel,
                $service instanceof ShipNationalityService ? $service->document_type : null);
            $this->addFile($archive, $document->file_path, $folder.'/Berkas/'.$filename);
        }

        foreach ($service->tasks()->with('attachments')->lazyById(100) as $task) {
            $this->ensureNotCancelled($cancelled);
            if (! Gate::allows('view', $task)) {
                continue;
            }
            foreach ($task->attachments as $attachment) {
                $this->ensureNotCancelled($cancelled);
                $original = str_replace('\\', '/', $attachment->original_filename);
                $name = Str::limit(Str::slug(pathinfo($original, PATHINFO_FILENAME)), 100, '') ?: 'lampiran';
                $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
                if (! preg_match('/\A[a-z0-9]{1,10}\z/', $extension)) {
                    $extension = 'bin';
                }
                $filename = $attachment->id.'-'.$name.'.'.$extension;
                $this->addFile($archive, $attachment->file_path, $folder.'/Task-'.$task->id.'/'.$filename);
            }
        }
    }

    /** @param ?Closure(): bool $cancelled */
    private function ensureNotCancelled(?Closure $cancelled): void
    {
        if ($cancelled !== null && $cancelled()) {
            throw new RuntimeException('Pembuatan ZIP dibatalkan.');
        }
    }

    private function addFile(ZipArchive $archive, string $storedPath, string $entry): void
    {
        $disk = Storage::disk('local');
        $root = realpath($disk->path(''));
        $relativePath = str_replace('\\', '/', $storedPath);
        $file = in_array('..', explode('/', $relativePath), true) ? false : realpath($disk->path($relativePath));

        if ($root === false || $file === false || ! is_file($file) || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR)) {
            throw ValidationException::withMessages(['archive' => 'Ada berkas layanan yang tidak ditemukan. Unduhan dibatalkan agar arsip tidak berisi berkas yang tidak lengkap.']);
        }
        if (! $archive->addFile($file, $entry)) {
            throw new RuntimeException('Berkas layanan tidak dapat ditambahkan ke arsip.');
        }
    }
}
