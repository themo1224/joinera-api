<?php

namespace App\Services;

use App\Models\CandidateListingMatch;
use App\Models\Listing;
use App\Models\Profile;

class StoreMatchSoftSignals
{
    public function __construct(
        private readonly EvaluateMatchSoftSignals $evaluator = new EvaluateMatchSoftSignals,
    ) {
    }

    public function handle(CandidateListingMatch $match, Profile $profile, Listing $listing): CandidateListingMatch
    {
        $result = $this->evaluator->handle($profile, $listing);

        $match->soft_signals = $result['soft_signals'];
        $match->save();

        return $match->refresh();
    }
}
