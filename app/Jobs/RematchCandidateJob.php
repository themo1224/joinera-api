<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\RematchCandidateAgainstListings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RematchCandidateJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $userId,
    ) {
        $this->onQueue((string) config('joinera.queues.match', 'match'));
    }

    public function handle(RematchCandidateAgainstListings $rematcher): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        $rematcher->handle($user);
    }
}
