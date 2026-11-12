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

    public function satisfies(?string $candidateEducation, ?string $requirement): bool
    {
        $required = $this->normalize($requirement);

        if ($required === null) {
            return true;
        }

        $candidate = $this->normalize($candidateEducation);

        if ($candidate === null) {
            return false;
        }

        $requiredRank = self::RANKS[$required] ?? null;
        $candidateRank = self::RANKS[$candidate] ?? null;

        if ($requiredRank !== null && $candidateRank !== null) {
            return $candidateRank >= $requiredRank;
        }

        return $candidate === $required;
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return mb_strtolower($trimmed);
    }
}
