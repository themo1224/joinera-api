<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CandidateMatchRecomputeRequested
{
    use Dispatchable, SerializesModels;

    public const NAME = 'candidate.match_recompute_requested';

    public function __construct(
        public readonly int $userId,
    ) {
    }
}
