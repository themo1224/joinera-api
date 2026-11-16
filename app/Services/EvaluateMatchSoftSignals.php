<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\Profile;
use App\Support\LanguageRequirement;

class EvaluateMatchSoftSignals
{
    public function __construct(
        private readonly LanguageRequirement $languageRequirement = new LanguageRequirement,
    ) {
    }

    /**
     * @return array{soft_signals: ?array<string, mixed>, has_soft_mismatch: bool}
     */
    public function handle(Profile $profile, Listing $listing): array
    {
        $signals = [];

        $languageSignal = $this->languageSignal($profile, $listing);
        if ($languageSignal !== null) {
            $signals['language_level'] = $languageSignal;
        }

        $locationSignal = $this->locationSignal($profile, $listing);
        if ($locationSignal !== null) {
            $signals['location'] = $locationSignal;
        }

        if ($signals === []) {
            return [
                'soft_signals' => null,
                'has_soft_mismatch' => false,
            ];
        }

        return [
            'soft_signals' => $signals,
            'has_soft_mismatch' => true,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function languageSignal(Profile $profile, Listing $listing): ?array
    {
        $raw = is_string($listing->language_requirement) ? $listing->language_requirement : null;
        $parsed = $this->languageRequirement->parse($raw);
        $covers = $this->languageRequirement->profileCoversLanguage(
            is_string($profile->language) ? $profile->language : null,
            $parsed['language'],
        );

        if ($covers !== false) {
            return null;
        }

        return [
            'mismatch' => true,
            'profile_language' => is_string($profile->language) ? $profile->language : null,
            'listing_requirement_raw' => $raw,
            'listing_language' => $parsed['language'],
            'listing_level' => $parsed['level'],
            'reason' => 'language_code_mismatch',
        ];
    }

    /**
     * Profile city/province preferences are temporarily unused — no location soft signal.
     *
     * @return array<string, mixed>|null
     */
    private function locationSignal(Profile $_profile, Listing $_listing): ?array
    {
        return null;
    }
}
