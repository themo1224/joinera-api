<?php

namespace App\Support;

class EducationRequirement
{
    /**
     * Ordered DE education ranks (higher index = higher qualification).
     *
     * @var array<string, int>
     */
    private const RANKS = [
        'hauptschulabschluss' => 1,
        'mittlere reife' => 2,
        'realschulabschluss' => 2,
        'abitur' => 3,
        'fachhochschulreife' => 3,
        'bachelor' => 4,
        'master' => 5,
    ];

    /**
     * Synonyms mapped onto canonical ladder keys before ranking.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'fachabitur' => 'fachhochschulreife',
    ];

    /**
     * Listing values that mean no real education bar (same as null requirement).
     *
     * @var list<string>
     */
    private const OPEN_REQUIREMENTS = [
        'beliebig',
    ];

    public function satisfies(?string $candidateEducation, ?string $requirement): bool
    {
        $required = $this->normalize($requirement);

        if ($required === null || $this->isOpenRequirement($required)) {
            return true;
        }

        $candidate = $this->normalize($candidateEducation);

        if ($candidate === null) {
            return false;
        }

        $requiredRank = $this->rank($required);
        $candidateRank = $this->rank($candidate);

        if ($requiredRank !== null && $candidateRank !== null) {
            return $candidateRank >= $requiredRank;
        }

        return $candidate === $required;
    }

    /**
     * Rank for ordered comparison (1–5). Null when the value is unknown.
     */
    public function rank(?string $value): ?int
    {
        $normalized = $this->normalize($value);

        if ($normalized === null) {
            return null;
        }

        return self::RANKS[$normalized] ?? null;
    }

    public function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $normalized = mb_strtolower($trimmed);

        return self::ALIASES[$normalized] ?? $normalized;
    }

    private function isOpenRequirement(string $normalizedRequirement): bool
    {
        return in_array($normalizedRequirement, self::OPEN_REQUIREMENTS, true);
    }
}
