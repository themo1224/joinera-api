<?php

namespace App\Services;

use App\Enums\MatchHardSkipReason;
use App\Models\Listing;
use App\Models\Profile;
use App\Support\EducationRequirement;

class EvaluateMatchHardRules
{
    public function __construct(
        private readonly EducationRequirement $educationRequirement = new EducationRequirement,
    ) {
    }

    /**
     * @return array{eligible: bool, hard_skip_reason: ?MatchHardSkipReason}
     */
    public function handle(Profile $profile, Listing $listing): array
    {
        if ($this->failsIndustry($profile, $listing)) {
            return $this->fail(MatchHardSkipReason::Industry);
        }

        if ($this->failsGermany($listing)) {
            return $this->fail(MatchHardSkipReason::NotGermany);
        }

        if ($this->failsEducation($profile, $listing)) {
            return $this->fail(MatchHardSkipReason::Education);
        }

        return [
            'eligible' => true,
            'hard_skip_reason' => null,
        ];
    }

    private function failsIndustry(Profile $profile, Listing $listing): bool
    {
        $industry = $this->normalizeLabel($profile->industry);
        $category = $this->normalizeLabel($listing->category);

        if ($industry === null || $category === null) {
            return false;
        }

        return $industry !== $category;
    }

    private function failsGermany(Listing $listing): bool
    {
        $country = $this->normalizeLabel($listing->country);

        if ($country === null) {
            return false;
        }

        return strtoupper($country) !== 'DE';
    }

    private function failsEducation(Profile $profile, Listing $listing): bool
    {
        return ! $this->educationRequirement->satisfies(
            is_string($profile->education) ? $profile->education : null,
            is_string($listing->education_requirement) ? $listing->education_requirement : null,
        );
    }

    private function normalizeLabel(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return mb_strtolower($trimmed);
    }

    /**
     * @return array{eligible: bool, hard_skip_reason: MatchHardSkipReason}
     */
    private function fail(MatchHardSkipReason $reason): array
    {
        return [
            'eligible' => false,
            'hard_skip_reason' => $reason,
        ];
    }
}
