<?php

namespace App\Support;

/**
 * Prefer an off-board (company) apply URL over a board-hosted Easy-Apply link.
 * Board hosts are keyed by intake source (`azubiyo`, `ausbildung.de`, `arbeitsagentur.de`).
 */
class ApplyUrlPreference
{
    /**
     * @var array<string, list<string>>
     */
    private const BOARD_HOST_SUFFIXES = [
        'azubiyo' => ['azubiyo.de'],
        'ausbildung.de' => ['ausbildung.de'],
        'arbeitsagentur.de' => ['arbeitsagentur.de'],
    ];

    public static function isBoardHost(string $source, string $url): bool
    {
        $host = self::hostOf($url);

        if ($host === null) {
            return false;
        }

        $suffixes = self::BOARD_HOST_SUFFIXES[$source] ?? [];

        foreach ($suffixes as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Classify where Mode B should open: company career page vs board Easy-Apply vs missing.
     *
     * @return 'company'|'board'|'missing'
     */
    public static function applyTarget(string $source, ?string $url): string
    {
        if ($url === null || trim($url) === '') {
            return 'missing';
        }

        return self::isBoardHost($source, $url) ? 'board' : 'company';
    }

    /**
     * Return the first off-board candidate; otherwise the first non-empty candidate (board fallback).
     * Never invents a URL.
     */
    public static function preferApplyUrl(string $source, ?string ...$candidates): ?string
    {
        $normalized = [];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $trimmed = trim($candidate);

            if ($trimmed === '') {
                continue;
            }

            $normalized[] = $trimmed;
        }

        foreach ($normalized as $candidate) {
            if (! self::isBoardHost($source, $candidate)) {
                return $candidate;
            }
        }

        return $normalized[0] ?? null;
    }

    /**
     * True when incoming should replace existing: null/empty existing, or board existing + company incoming.
     */
    public static function shouldUpgradeApplyUrl(string $source, ?string $existing, ?string $incoming): bool
    {
        if ($incoming === null || trim($incoming) === '') {
            return false;
        }

        if ($existing === null || trim($existing) === '') {
            return true;
        }

        return self::isBoardHost($source, $existing) && ! self::isBoardHost($source, $incoming);
    }

    private static function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return strtolower($host);
    }
}
