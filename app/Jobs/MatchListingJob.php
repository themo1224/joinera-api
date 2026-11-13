<?php

namespace App\Jobs;

use App\Models\Listing;
use App\Services\MatchListingAgainstEligibleCandidates;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MatchListingJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $listingId,
    ) {
        $this->onQueue((string) config('joinera.queues.match', 'match'));
    }

    public function handle(MatchListingAgainstEligibleCandidates $matcher): void
    {
        $listing = Listing::query()->find($this->listingId);

        if ($listing === null) {
            return;
        }

        $matcher->handle($listing);
    }
}
