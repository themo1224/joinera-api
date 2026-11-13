<?php

namespace App\Listeners;

use App\Events\CandidateMatchRecomputeRequested;
use App\Jobs\RematchCandidateJob;

class DispatchRematchOnCandidateMatchRecomputeRequested
{
    public function handle(CandidateMatchRecomputeRequested $event): void
    {
        RematchCandidateJob::dispatch($event->userId);
    }
}
