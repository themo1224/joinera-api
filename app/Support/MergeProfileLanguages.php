<?php

namespace App\Support;

final class MergeProfileLanguages
{
    private const LEVEL_RANK = [
        'A1' => 1,
        'A2' => 2,
        'B1' => 3,
        'B2' => 4,
        'C1' => 5,
        'C2' => 6,
        'NATIVE' => 7,
    ];

    /**
     * Merge spoken-language rows by language name. Higher CEFR / native wins on collision.
     *
     * @param  list<array<string, mixed>>  $existing
     * @param  list<array<string, mixed>>  $incoming
     * @return list<array{language: string, level: string}>
     */
    public static function merge(array $existing, array $incoming): array
    {
        /** @var array<string, array{language: string, level: string}> $byLang */
        $byLang = [];

        foreach ([$existing, $incoming] as $list) {
            foreach ($list as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $language = trim((string) ($entry['language'] ?? ''));
                $level = trim((string) ($entry['level'] ?? ''));
                if ($language === '' || $level === '') {
                    continue;
                }
                $key = mb_strtolower($language);
                $normalized = [
                    'language' => $language,
                    'level' => $level,
                ];
                if (! isset($byLang[$key])) {
                    $byLang[$key] = $normalized;
                    continue;
                }
                if (self::rank($level) > self::rank($byLang[$key]['level'])) {
                    $byLang[$key] = $normalized;
                }
            }
        }

        return array_values($byLang);
    }

    private static function rank(string $level): int
    {
        $key = strtoupper(trim($level));
        if ($key === 'NATIVE' || str_contains(mb_strtolower($level), 'mutter')) {
            return self::LEVEL_RANK['NATIVE'];
        }

        return self::LEVEL_RANK[$key] ?? 0;
    }
}
