<?php

namespace App\Services;

use App\Models\Vessel;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class EntityProfilePhoto
{
    /** @return list<string> */
    public static function rules(): array
    {
        return ['bail', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=4096,max_height=4096'];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'profilePhoto.image' => 'Foto profil harus berupa gambar.',
            'profilePhoto.mimes' => 'Foto profil harus berupa JPG, PNG, atau WebP.',
            'profilePhoto.extensions' => 'Ekstensi foto profil harus JPG, PNG, atau WebP.',
            'profilePhoto.max' => 'Ukuran foto profil maksimal 4 MB.',
            'profilePhoto.dimensions' => 'Dimensi foto profil maksimal 4096 x 4096 piksel.',
        ];
    }

    public function save(Vessel $model, ?UploadedFile $photo, bool $remove, Closure $persist): void
    {
        $oldPath = $model->profile_picture_path;
        $newPath = null;

        try {
            if ($photo !== null) {
                $newPath = app(ImageOptimizer::class)->store($photo, $model->getTable().'/photos');
                if ($newPath === false) {
                    throw new RuntimeException('Foto profil gagal disimpan.');
                }
                $model->profile_picture_path = $newPath;
            } elseif ($remove) {
                $model->profile_picture_path = null;
            }

            $persist();
        } catch (Throwable $exception) {
            if (is_string($newPath)) {
                $this->delete($newPath);
            }
            $model->profile_picture_path = $oldPath;
            throw $exception;
        }

        if ($oldPath && $oldPath !== $model->profile_picture_path) {
            $this->delete($oldPath);
        }
    }

    private function delete(string $path): void
    {
        try {
            if (! Storage::disk('local')->delete($path)) {
                report(new RuntimeException('Foto profil lama gagal dihapus.'));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
