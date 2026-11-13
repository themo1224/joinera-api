<?php

namespace App\Services\Docs;

use App\Enums\DocGenerationRule;
use App\Models\Listing;
use App\Models\Profile;

class ResolveDocGenerationRule
{
    public function handle(?Profile $profile, Listing $listing): DocGenerationRule
    {
        $industry = $this->normalize($profile?->industry);
        $category = $this->normalize($listing->category);

        if ($industry === null || $category === null) {
            return DocGenerationRule::CategoryUnknown;
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
