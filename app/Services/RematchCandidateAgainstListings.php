<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\User;

class RematchCandidateAgainstListings
{
    public const CHUNK_SIZE = 500;

    public function __construct(
        private readonly ResolveCandidateEligibility $eligibility,
        private readonly UpsertCandidateListingMatch $upsertMatch,
        private readonly BuildRematchListingQuery $listingQuery,
    ) {
    }

    public function handle(User $user): void
    {
        $gate = $this->eligibility->handle($user);

        if (! $gate['eligible']) {
            return;
        }

        $user->loadMissing('profile');

        $this->listingQuery
            ->handle($user->profile)
            ->chunkById(self::CHUNK_SIZE, function ($listings) use ($user): void {
                foreach ($listings as $listing) {
                    /** @var Listing $listing */
                    $this->upsertMatch->handle($user, $listing);
                }
            });
    }
}
