<?php

namespace App\Services;

use App\Models\Application;
use App\Models\CandidateListingMatch;

class BuildMatchOpsItem
{
    /**
     * @return array{
     *     id: int,
     *     userId: int,
     *     listingId: int,
     *     status: string,
     *     hardSkipReason: string|null,
     *     softSignals: array<string, mixed>|null,
     *     queuedAt: string|null,
     *     forceQueuedBy: int|null,
     *     forceQueuedAt: string|null,
     *     applicationId: int|null,
     *     listingTitle: string|null,
     *     listingSource: string|null,
     *     listingCity: string|null,
     *     listingCountry: string|null,
     *     listingCategory: string|null,
     *     userName: string|null,
     *     userEmail: string|null
     * }
     */
    public function handle(CandidateListingMatch $match): array
    {
        $match->loadMissing(['user', 'listing', 'application']);

        $listing = $match->listing;
        $user = $match->user;
        $application = $match->application;

        return [
            'id' => $match->id,
            'userId' => $match->user_id,
            'listingId' => $match->listing_id,
            'status' => $match->status->value,
            'hardSkipReason' => $match->hard_skip_reason?->value,
            'softSignals' => $match->soft_signals,
            'queuedAt' => $match->queued_at?->toISOString(),
            'forceQueuedBy' => $match->force_queued_by,
            'forceQueuedAt' => $match->force_queued_at?->toISOString(),
            'applicationId' => $application?->id,
            'listingTitle' => $listing?->title,
            'listingSource' => $listing?->source,
            'listingCity' => $listing?->city,
            'listingCountry' => $listing?->country,
            'listingCategory' => $listing?->category,
            'userName' => $user?->name,
            'userEmail' => $user?->email,
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     status: string,
     *     site: string,
     *     userId: int,
     *     listingId: int,
     *     candidateListingMatchId: int|null
     * }
     */
    public function applicationPayload(Application $application): array
    {
        return [
            'id' => $application->id,
            'status' => $application->status->value,
            'site' => $application->site,
            'userId' => $application->user_id,
            'listingId' => $application->listing_id,
            'candidateListingMatchId' => $application->candidate_listing_match_id,
        ];
    }
}
