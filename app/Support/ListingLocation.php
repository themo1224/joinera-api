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
        return $this->citiesMatchAny(
            $profileCity === null || $profileCity === '' ? [] : [$profileCity],
            $listingCity,
        );
    }

    /**
     * Empty profile city list means all cities (no mismatch).
     *
     * @param  list<string>  $profileCities
     * @return bool|null true when cities match, false when mismatch, null when inconclusive
     */
    public function citiesMatchAny(array $profileCities, ?string $listingCity): ?bool
    {
        $listing = $this->normalizeCity($listingCity);
        if ($listing === null) {
            return null;
        }

        $normalized = [];
        foreach ($profileCities as $city) {
            $value = $this->normalizeCity($city);
            if ($value !== null) {
                $normalized[] = $value;
            }
        }

        if ($normalized === []) {
            return true;
        }

        return in_array($listing, $normalized, true);
    }
}
