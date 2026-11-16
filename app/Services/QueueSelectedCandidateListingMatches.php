<?php

namespace App\Services;

use App\Enums\MatchStatus;
use App\Enums\Role;
use App\Exceptions\ApplyCapExceededException;
use App\Exceptions\MatchNotProposedException;
use App\Models\Application;
use App\Models\CandidateListingMatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class QueueSelectedCandidateListingMatches
{
    public function __construct(
        private readonly CreateQueuedApplicationFromMatch $createQueuedApplication,
        private readonly ResolveApplyCapUsage $resolveApplyCapUsage,
    ) {
    }

    /**
     * @param  list<int>  $matchIds
     * @return array{
     *     matches: list<CandidateListingMatch>,
     *     applications: list<Application>,
     *     applyCap: array{cap: int, window: string, used: int, remaining: int}
     * }
     */
    public function handle(User $candidate, array $matchIds, User $_actor): array
    {
        if ($candidate->role !== Role::User) {
            throw new InvalidArgumentException('queue_selected_not_candidate');
        }

        $ids = array_values(array_unique(array_map('intval', $matchIds)));

        if ($ids === []) {
            throw new InvalidArgumentException('queue_selected_empty');
        }

        $applyCap = $this->resolveApplyCapUsage->handle($candidate);

        if (count($ids) > $applyCap['remaining']) {
            throw new ApplyCapExceededException($applyCap);
        }

        return DB::transaction(function () use ($candidate, $ids, $applyCap): array {
            /** @var \Illuminate\Database\Eloquent\Collection<int, CandidateListingMatch> $matches */
            $matches = CandidateListingMatch::query()
                ->where('user_id', $candidate->id)
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get();

            if ($matches->count() !== count($ids)) {
                throw new InvalidArgumentException('queue_selected_not_found');
            }

            foreach ($matches as $match) {
                if ($match->status !== MatchStatus::Proposed) {
                    throw new MatchNotProposedException;
                }
            }

            $queuedMatches = [];
            $applications = [];

            foreach ($matches as $match) {
                $match->status = MatchStatus::Queued;
                $match->hard_skip_reason = null;
                $match->queued_at = $match->queued_at ?? now();
                $match->save();

                $application = $this->createQueuedApplication->handle($match->fresh() ?? $match);

                if ($application === null) {
                    throw new RuntimeException('queue_selected_application_failed');
                }

                $queuedMatches[] = $match->fresh(['user', 'listing', 'application', 'forceQueuedBy']) ?? $match;
                $applications[] = $application;
            }

            return [
                'matches' => $queuedMatches,
                'applications' => $applications,
                'applyCap' => $this->resolveApplyCapUsage->handle($candidate),
            ];
        });
    }
}
