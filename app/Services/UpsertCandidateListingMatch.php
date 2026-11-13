<?php

namespace App\Services;

use App\Enums\MatchStatus;
use App\Models\CandidateListingMatch;
use App\Models\Listing;
use App\Models\User;

class UpsertCandidateListingMatch
{
    public function __construct(
        private readonly ResolveCandidateEligibility $eligibility,
        private readonly EvaluateMatchHardRules $hardRules,
        private readonly StoreMatchSoftSignals $storeSoftSignals,
        private readonly CreateQueuedApplicationFromMatch $createQueuedApplication,
    ) {
    }

    public function handle(User $user, Listing $listing): ?CandidateListingMatch
    {
        $gate = $this->eligibility->handle($user);

        if (! $gate['eligible']) {
            return null;
        }

        $user->loadMissing('profile');
        $profile = $user->profile;

        if ($profile === null) {
            return null;
        }

        $hard = $this->hardRules->handle($profile, $listing);

        /** @var CandidateListingMatch|null $existing */
        $existing = CandidateListingMatch::query()
            ->where('user_id', $user->id)
            ->where('listing_id', $listing->id)
            ->first();

        $forceQueued = $existing !== null && $existing->force_queued_at !== null;

        if ($forceQueued) {
            $status = MatchStatus::Queued;
            $hardSkipReason = null;
            $queuedAt = $existing->queued_at ?? now();
        } elseif ($hard['eligible']) {
            $status = MatchStatus::Queued;
            $hardSkipReason = null;
            $queuedAt = $existing?->status === MatchStatus::Queued && $existing->queued_at !== null
                ? $existing->queued_at
                : now();
        } else {
            $status = MatchStatus::Skipped;
            $hardSkipReason = $hard['hard_skip_reason'];
            $queuedAt = null;
        }

        $match = CandidateListingMatch::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'listing_id' => $listing->id,
            ],
            [
                'status' => $status,
                'hard_skip_reason' => $hardSkipReason,
                'queued_at' => $queuedAt,
                'force_queued_by' => $existing?->force_queued_by,
                'force_queued_at' => $existing?->force_queued_at,
            ],
        );

        $match = $this->storeSoftSignals->handle($match, $profile, $listing);

        if ($match->status === MatchStatus::Queued) {
            $this->createQueuedApplication->handle($match);
        }

        return $match;
    }
}
