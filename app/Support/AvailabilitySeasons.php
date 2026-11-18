<?php

namespace App\Support;

/**
 * Canonical season chips for candidate target availability.
 * spring→03, summer→06, autumn→09, winter→12 (day 01).
 */
final class AvailabilitySeasons
{
    public const SEASONS = ['spring', 'summer', 'autumn', 'winter'];

    /** @var array<string, string> */
    private const MONTH = [
        'spring' => '03',
        'summer' => '06',
        'autumn' => '09',
        'winter' => '12',
    ];

    /**
     * @return list<array{season: string, year: int}>
     */
    public static function normalize(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $seen = [];
        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $season = strtolower(trim((string) ($row['season'] ?? '')));
            if (! in_array($season, self::SEASONS, true)) {
                continue;
            }
            $yearRaw = $row['year'] ?? null;
            if (! is_int($yearRaw) && ! (is_string($yearRaw) && ctype_digit(trim($yearRaw)))) {
                continue;
            }
            $year = (int) $yearRaw;
            if ($year < 2000 || $year > 2100) {
                continue;
            }
            $key = $season.'|'.$year;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['season' => $season, 'year' => $year];
        }

        usort($out, static function (array $a, array $b): int {
            if ($a['year'] !== $b['year']) {
                return $a['year'] <=> $b['year'];
            }

            return array_search($a['season'], self::SEASONS, true) <=> array_search($b['season'], self::SEASONS, true);
        });

        return $out;
    }

    /**
     * @param  list<array{season: string, year: int}>  $seasons
     */
    public static function earliestDate(array $seasons): ?string
    {
        $earliest = null;
        foreach ($seasons as $row) {
            $date = self::toDate($row['season'], $row['year']);
            if ($date === null) {
                continue;
            }
            if ($earliest === null || $date < $earliest) {
                $earliest = $date;
            }
        }

        return $earliest;
    }

    public static function toDate(string $season, int $year): ?string
    {
        $month = self::MONTH[$season] ?? null;
        if ($month === null || $year < 2000 || $year > 2100) {
            return null;
        }

        return sprintf('%04d-%s-01', $year, $month);
    }

    /**
     * @param  list<array{season: string, year: int}>|null  $seasons
     */
    public static function formatLabels(?array $seasons): string
    {
        $normalized = self::normalize($seasons ?? []);
        if ($normalized === []) {
            return '';
        }

        return implode(', ', array_map(
            static fn (array $row): string => ucfirst($row['season']).' '.$row['year'],
            $normalized
        ));
    }
}
