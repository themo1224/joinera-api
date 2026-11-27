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
     * Soft location fit: profile city list vs listing city (PLZ prefix stripped).
     * Empty profile cities → no signal (preferences unused).
     *
     * @return array<string, mixed>|null
     */
    private function locationSignal(Profile $profile, Listing $listing): ?array
    {
        $listingCityRaw = is_string($listing->city) ? trim($listing->city) : '';
        if ($listingCityRaw === '') {
            return null;
        }

        $profileCities = [];
        foreach ($profile->cityList() as $city) {
            if (! is_string($city)) {
                continue;
            }
            $normalized = mb_strtolower(trim($city));
            if ($normalized !== '') {
                $profileCities[] = $normalized;
            }
        }

        if ($profileCities === []) {
            return null;
        }

        $listingNormalized = $this->normalizeListingCity($listingCityRaw);
        foreach ($profileCities as $city) {
            if (
                $listingNormalized === $city
                || str_contains($listingNormalized, $city)
                || str_contains($city, $listingNormalized)
            ) {
                return null;
            }
        }

        return [
            'mismatch' => true,
            'reason' => 'city_mismatch',
            'profile_cities' => array_values(array_unique($profileCities)),
            'listing_city' => $listingCityRaw,
        ];
    }

    private function normalizeListingCity(string $raw): string
    {
        $trimmed = trim($raw);
        $stripped = preg_replace('/^\d{4,5}\s+/u', '', $trimmed) ?? $trimmed;

        return mb_strtolower(trim($stripped));
    }
}
