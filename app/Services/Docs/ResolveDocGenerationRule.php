<?php

namespace App\Services\Docs;

use App\Enums\DocGenerationRule;
use App\Models\Listing;
use App\Models\Profile;

class ResolveDocGenerationRule
{
    /**
     * Q20: same category → cover only; different → CV+cover.
     *
     * Interim: empty listing category (common on Azubiyo / Ausbildung.de intake)
     * falls through to CV+cover instead of category_unknown, so docs can generate
     * until intake maps a real occupation/category field (see PRD Q20 / Q-Listing-Category).
     */
    public function handle(?Profile $profile, Listing $listing): DocGenerationRule
    {
        $industry = $this->normalize($profile?->industry);
        $category = $this->normalize($listing->category);

        if ($category === null || $industry === null) {
            return DocGenerationRule::CvAndCover;
        }

        if ($industry === $category) {
            return DocGenerationRule::CoverOnly;
        }

        return DocGenerationRule::CvAndCover;
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
