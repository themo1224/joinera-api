<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ListingCreated
{
    use Dispatchable, SerializesModels;

    public const NAME = 'listing.created';

    public function __construct(
        public readonly int $listingId,
    ) {
    }
}
