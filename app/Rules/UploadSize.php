<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class UploadSize implements ValidationRule
{
    public function __construct(private int $documentMaxKb = 2048) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }
        $image = str_starts_with((string) $value->getMimeType(), 'image/');
        $limit = $image ? 4096 : $this->documentMaxKb;
        if ($value->getSize() > $limit * 1024) {
            $fail('Ukuran '.($image ? 'gambar' : 'file').' maksimal '.($limit / 1024).' MB.');
        }
    }
}
