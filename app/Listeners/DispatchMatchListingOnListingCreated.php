<?php

namespace App\Listeners;

use App\Events\ListingCreated;
use App\Jobs\MatchListingJob;

class DispatchMatchListingOnListingCreated
{
    public function handle(ListingCreated $event): void
    {
        MatchListingJob::dispatch($event->listingId);
    }
}
