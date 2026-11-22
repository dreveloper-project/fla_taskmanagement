<?php

namespace App\Services;

use Closure;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ArchiveDownloadResponse
{
    /**
     * Stream newline-delimited progress records followed by the exact ZIP bytes
     * described in the final file record, keeping temporary files request-scoped.
     *
     * @param Closure(Closure(int, string): void, Closure(): bool): string $create
     */
    public function stream(Closure $create, string $filename): StreamedResponse
    {
        return response()->stream(function () use ($create, $filename): void {
            $previousIgnoreAbort = ignore_user_abort(true);
            $path = null;
            $file = null;
            $binaryStarted = false;
            $lastEvent = ['type' => 'progress', 'percent' => 0, 'message' => 'Menyiapkan data dan berkas…'];
            $lastSentAt = 0.0;
            /** @param array{type: string, percent?: int, message?: string, filename?: string, size?: int|false} $event */
            $send = function (array $event) use (&$lastSentAt): void {
                echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n";
                $this->flush();
                $lastSentAt = microtime(true);
            };
            $progress = function (int $percent, string $message) use ($send, &$lastEvent): void {
                $lastEvent = ['type' => 'progress', 'percent' => $percent, 'message' => $message];
                $send($lastEvent);
            };
            $cancelled = function () use ($send, &$lastSentAt, &$lastEvent): bool {
                if (microtime(true) - $lastSentAt >= 0.25) {
                    $send($lastEvent);
                }

                return connection_aborted() !== 0;
            };

            try {
                $send($lastEvent);
                $path = $create($progress, $cancelled);
                if ($cancelled()) {
                    return;
                }
                $file = fopen($path, 'rb');
                if ($file === false) {
                    throw new RuntimeException('File ZIP tidak dapat dibaca.');
                }
                $send(['type' => 'file', 'filename' => $filename, 'size' => filesize($path)]);
                $binaryStarted = true;
                while (! feof($file) && connection_aborted() === 0) {
                    $chunk = fread($file, 1024 * 1024);
                    if ($chunk === false) {
                        throw new RuntimeException('Transfer file ZIP terputus.');
                    }
                    echo $chunk;
                    $this->flush();
                }
            } catch (Throwable $exception) {
                if (connection_aborted() === 0) {
                    if (! $exception instanceof ValidationException) {
                        report($exception);
                    }
                    if (! $binaryStarted) {
                        $message = $exception instanceof ValidationException
                            ? $exception->errors()['archive'][0]
                            : 'ZIP gagal disiapkan. Silakan coba lagi.';
                        $send(['type' => 'error', 'message' => $message]);
                    }
                }
            } finally {
                if (is_resource($file)) {
                    fclose($file);
                }
                if ($path !== null && is_file($path)) {
                    unlink($path);
                }
                ignore_user_abort((bool) $previousIgnoreAbort);
            }
        }, 200, [
            'Content-Type' => 'application/vnd.fla.archive-stream',
            'Cache-Control' => 'private, no-store, no-transform',
            'X-Accel-Buffering' => 'no',
            'X-Content-Type-Options' => 'nosniff',
        ])->setPrivate();
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
