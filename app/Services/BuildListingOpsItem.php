<?php

namespace App\Services;

use App\Models\Listing;

class BuildListingOpsItem
{
    /**
     * @return array{
     *     id: int,
     *     source: string,
     *     externalId: string|null,
     *     kind: string,
     *     category: string|null,
     *     title: string,
     *     company: string|null,
     *     city: string|null,
     *     country: string|null,
     *     url: string|null,
     *     applyUrl: string|null,
     *     languageRequirement: string|null,
     *     educationRequirement: string|null,
     *     postedAt: string|null,
     *     firstSeenAt: string|null,
     *     lastSeenAt: string|null,
     *     description?: string|null
     * }
     */
    public function handle(Listing $listing, bool $includeDescription = false): array
    {
        $payload = [
            'id' => $listing->id,
            'source' => $listing->source,
            'externalId' => $listing->external_id,
            'kind' => $listing->kind->value,
            'category' => $listing->category,
            'title' => $listing->title,
            'company' => $listing->company,
            'city' => $listing->city,
            'country' => $listing->country,
            'url' => $listing->url,
            'applyUrl' => $listing->apply_url,
            'languageRequirement' => $listing->language_requirement,
            'educationRequirement' => $listing->education_requirement,
            'postedAt' => $listing->posted_at?->toISOString(),
            'firstSeenAt' => $listing->first_seen_at?->toISOString(),
            'lastSeenAt' => $listing->last_seen_at?->toISOString(),
        ];

        if ($includeDescription) {
            $payload['description'] = $listing->description;
        }

        return $payload;
    }
}
