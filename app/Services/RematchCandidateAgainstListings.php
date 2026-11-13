<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\User;

class RematchCandidateAgainstListings
{
    public function __construct(
        private readonly ResolveCandidateEligibility $eligibility,
        private readonly UpsertCandidateListingMatch $upsertMatch,
    ) {
    }

    public function handle(User $user): void
    {
        $gate = $this->eligibility->handle($user);

        if (! $gate['eligible']) {
            return;
        }

        Listing::query()
            ->orderBy('id')
            ->cursor()
            ->each(function (Listing $listing) use ($user): void {
                $this->upsertMatch->handle($user, $listing);
            });
    }
}
