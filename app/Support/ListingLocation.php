<?php

namespace App\Support;

class ListingLocation
{
    public function normalizeCity(?string $city): ?string
    {
        if ($city === null) {
            return null;
        }

        $trimmed = trim($city);

        if ($trimmed === '') {
            return null;
        }

        $withoutPlz = preg_replace('/^\d{5}\s+/u', '', $trimmed) ?? $trimmed;
        $normalized = trim($withoutPlz);

        if ($normalized === '') {
            return null;
        }

        return mb_strtolower($normalized);
    }

    /**
     * @return bool|null true when cities match, false when mismatch, null when inconclusive
     */
    public function citiesMatch(?string $profileCity, ?string $listingCity): ?bool
    {
        $profile = $this->normalizeCity($profileCity);
        $listing = $this->normalizeCity($listingCity);

        if ($profile === null || $listing === null) {
            return null;
        }

        return $profile === $listing;
    }
}
