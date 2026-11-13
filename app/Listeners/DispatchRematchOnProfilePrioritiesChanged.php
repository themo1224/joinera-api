<?php

namespace App\Listeners;

use App\Events\ProfilePrioritiesChanged;
use App\Jobs\RematchCandidateJob;

class DispatchRematchOnProfilePrioritiesChanged
{
    public function handle(ProfilePrioritiesChanged $event): void
    {
        RematchCandidateJob::dispatch($event->userId);
    }
}
