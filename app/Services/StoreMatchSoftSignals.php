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
        // Rules heuristic (no LLM): language mismatch = 50; otherwise 100.
        // Location soft signals do not change rank_score in this slice.
        $language = is_array($result['soft_signals']['language_level'] ?? null)
            ? $result['soft_signals']['language_level']
            : null;
        $languageMismatch = is_array($language) && ($language['mismatch'] ?? false) === true;
        $match->rank_score = $languageMismatch ? 50 : 100;
        $match->ranked_at = now();
        $match->save();

        return $match->refresh();
    }
}
