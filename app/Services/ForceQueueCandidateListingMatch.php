<?php

namespace App\Services;

use App\Enums\MatchStatus;
use App\Exceptions\ApplyCapExceededException;
use App\Exceptions\MatchNotSkippedException;
use App\Models\Application;
use App\Models\CandidateListingMatch;
use App\Models\User;
use RuntimeException;

class ForceQueueCandidateListingMatch
{
    public function __construct(
        private readonly CreateQueuedApplicationFromMatch $createQueuedApplication,
        private readonly ResolveApplyCapUsage $resolveApplyCapUsage,
    ) {
    }

    /**
     * @return array{
     *     match: CandidateListingMatch,
     *     application: Application,
     *     applyCap: array{cap: int, window: string, used: int, remaining: int}
     * }
     */
    public function handle(CandidateListingMatch $match, User $actor): array
    {
        if ($match->status !== MatchStatus::Skipped) {
            throw new MatchNotSkippedException;
        }

        $match->loadMissing('user');
        $candidate = $match->user;

        if ($candidate === null) {
            throw new RuntimeException('force_queue_missing_user');
        }

        $applyCap = $this->resolveApplyCapUsage->handle($candidate);

        if ($applyCap['remaining'] < 1) {
            throw new ApplyCapExceededException($applyCap);
        }

        $match->status = MatchStatus::Queued;
        $match->hard_skip_reason = null;
        $match->queued_at = $match->queued_at ?? now();
        $match->force_queued_by = $actor->id;
        $match->force_queued_at = now();
        $match->save();

        $application = $this->createQueuedApplication->handle($match->fresh() ?? $match);

        if ($application === null) {
            throw new RuntimeException('force_queue_application_failed');
        }

        $match = $match->fresh(['user', 'listing', 'application', 'forceQueuedBy']) ?? $match;

        return [
            'match' => $match,
            'application' => $application,
            'applyCap' => $this->resolveApplyCapUsage->handle($candidate),
        ];
    }
}
