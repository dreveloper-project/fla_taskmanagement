<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ImageOptimizer
{
    public function store(UploadedFile $file, string $directory): string|false
    {
        $mime = $file->getMimeType();
        if (! str_starts_with((string) $mime, 'image/') || $file->getSize() < 1024 * 1024) {
            return $file->store($directory, 'local');
        }
        $dimensions = @getimagesize($file->getRealPath());
        if (! $dimensions || $dimensions[0] * $dimensions[1] > 40000000) {
            throw ValidationException::withMessages(['file' => 'Dimensi gambar terlalu besar atau tidak valid.']);
        }
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($file->getRealPath()),
            'image/png' => @imagecreatefrompng($file->getRealPath()),
            'image/webp' => @imagecreatefromwebp($file->getRealPath()),
            'image/gif' => @imagecreatefromgif($file->getRealPath()),
            default => false,
        };
        if (! $image) {
            throw ValidationException::withMessages(['profile_picture' => 'Foto tidak dapat diproses. Pilih foto JPG, PNG, atau WebP yang valid.']);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, 2048 / max($width, $height));
        try {
            while ($scale > 0.05) {
                $resized = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
                if (! $resized) {
                    break;
                }
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                foreach ([85, 70, 55, 40] as $quality) {
                    ob_start();
                    $success = match ($mime) {
                        'image/jpeg' => imagejpeg($resized, null, $quality),
                        'image/png' => imagepng($resized, null, 9),
                        'image/webp' => imagewebp($resized, null, $quality),
                        'image/gif' => imagegif($resized),
                    };
                    $contents = ob_get_clean();
                    if ($success && is_string($contents) && strlen($contents) < 1024 * 1024) {
                        imagedestroy($resized);
                        $extension = match ($mime) {
                            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'
                        };
                        $path = $directory.'/'.Str::random(40).'.'.$extension;

                        return Storage::disk('local')->put($path, $contents) ? $path : false;
                    }
                }
                imagedestroy($resized);
                $scale *= 0.75;
            }
        } finally {
            imagedestroy($image);
        }

        throw ValidationException::withMessages(['profile_picture' => 'Foto tidak dapat dikompresi hingga kurang dari 1 MB.']);
    }
}
