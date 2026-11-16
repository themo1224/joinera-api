<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores a list of non-empty strings as JSON. Accepts legacy scalar strings on read/write.
 *
 * @implements CastsAttributes<list<string>, list<string>|string|null>
 */
class StringListCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        return self::normalize($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: string, 1: string}
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        return [$key => json_encode(self::normalize($value), JSON_UNESCAPED_UNICODE)];
    }

    /**
     * @return list<string>
     */
    public static function normalize(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }

            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return self::normalizeList($decoded);
            }

            return [$trimmed];
        }

        if (is_array($value)) {
            return self::normalizeList($value);
        }

        return [];
    }

    /**
     * @param  array<mixed>  $value
     * @return list<string>
     */
    private static function normalizeList(array $value): array
    {
        $out = [];
        foreach ($value as $item) {
            if (! is_string($item) && ! is_numeric($item)) {
                continue;
            }
            $trimmed = trim((string) $item);
            if ($trimmed === '') {
                continue;
            }
            if (! in_array($trimmed, $out, true)) {
                $out[] = $trimmed;
            }
        }

        return $out;
    }
}
