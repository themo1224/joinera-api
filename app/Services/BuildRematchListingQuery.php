<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\Profile;
use App\Support\IndustryTaxonomy;
use Illuminate\Database\Eloquent\Builder;

class BuildRematchListingQuery
{
    /**
     * Columns required for hard/soft evaluation during rematch upsert.
     * Application create reloads listing via relation when needed.
     *
     * @var list<string>
     */
    public const SELECT_COLUMNS = [
        'id',
        'category',
        'country',
        'education_requirement',
        'language_requirement',
    ];

    /**
     * @return Builder<Listing>
     */
    public function handle(?Profile $profile = null): Builder
    {
        $query = Listing::query()
            ->select(self::SELECT_COLUMNS)
            ->orderBy('id');

        if (! (bool) config('joinera.match.rematch_listing_prefilter', false)) {
            return $query;
        }

        $this->applyGermanyPrefilter($query);

        if ($profile !== null) {
            $this->applyIndustryPrefilter($query, $profile);
        }

        return $query;
    }

    /**
     * @param  Builder<Listing>  $query
     */
    private function applyGermanyPrefilter(Builder $query): void
    {
        $query->where(function (Builder $inner): void {
            $inner->whereNull('country')
                ->orWhereRaw("TRIM(country) = ''")
                ->orWhereRaw("UPPER(TRIM(country)) = 'DE'");
        });
    }

    /**
     * Keep listings that hard-rules industry would still pass:
     * blank/null category, or category family intersecting profile industries.
     * Empty profile industry list currently always passes industry — do not category-filter.
     *
     * @param  Builder<Listing>  $query
     */
    private function applyIndustryPrefilter(Builder $query, Profile $profile): void
    {
        $familyKeys = [];
        foreach ($profile->industryList() as $label) {
            if (! is_string($label)) {
                continue;
            }
            $normalized = mb_strtolower(trim($label));
            if ($normalized === '') {
                continue;
            }
            $family = IndustryTaxonomy::familyKey($normalized);
            if ($family !== null) {
                $familyKeys[] = $family;
            }
        }

        $familyKeys = array_values(array_unique($familyKeys));

        if ($familyKeys === []) {
            return;
        }

        $labels = IndustryTaxonomy::labelsForFamilyKeys($familyKeys);

        $query->where(function (Builder $inner) use ($labels): void {
            $inner->whereNull('category')
                ->orWhereRaw("TRIM(category) = ''");

            if ($labels === []) {
                return;
            }

            $placeholders = implode(', ', array_fill(0, count($labels), '?'));
            $inner->orWhereRaw('LOWER(TRIM(category)) IN ('.$placeholders.')', $labels);
        });
    }
}
