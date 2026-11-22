<?php

namespace App\Services;

use Illuminate\Support\Str;

class ServiceNumber
{
    public static function temporary(string $prefix): string
    {
        return $prefix.'-'.Str::ulid();
    }

    public static function forId(string $prefix, int $id, string $timezone): string
    {
        return $prefix.'-'.now($timezone)->year.'-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }
}
