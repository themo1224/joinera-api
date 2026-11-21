<?php

namespace App\Support;

/**
 * Plan 28 — defense-in-depth redaction before persisting worker form_plan / payloads.
 */
final class ScrubSecrets
{
    private const SECRET_KEY_RE = '/^(password|passwd|pass|secret|token|api[_-]?key|authorization|cookie|session)$/i';

    /**
     * @param  array<string, mixed>|null  $value
     * @return array<string, mixed>|null
     */
    public static function array(?array $value): ?array
    {
        if ($value === null) {
            return null;
        }

        /** @var array<string, mixed> $scrubbed */
        $scrubbed = self::mixed($value);

        return $scrubbed;
    }

    private static function mixed(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $out = [];

        foreach ($value as $key => $child) {
            if (! $isList && is_string($key) && preg_match(self::SECRET_KEY_RE, $key) === 1) {
                $out[$key] = '[redacted]';

                continue;
            }

            $out[$key] = self::mixed($child);
        }

        return $out;
    }
}
