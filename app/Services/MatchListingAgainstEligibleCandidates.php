<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\MailboxState;
use App\Enums\Role;
use App\Models\Listing;
use App\Models\User;

class MatchListingAgainstEligibleCandidates
{
    public function __construct(
        private readonly ResolveCandidateEligibility $eligibility,
        private readonly UpsertCandidateListingMatch $upsertMatch,
    ) {
    }

    public function handle(Listing $listing): void
    {
        foreach ($this->candidateQuery()->cursor() as $user) {
            /** @var User $user */
            $gate = $this->eligibility->handle($user);

            if (! $gate['eligible']) {
                continue;
            }

            $this->upsertMatch->handle($user, $listing);
        }
    }

    /**
     * Coarse prefilter; ResolveCandidateEligibility is the final gate.
     *
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    private function candidateQuery()
    {
        return User::query()
            ->where('role', Role::User)
            ->whereHas('profile', function ($query): void {
                $query->where('approval', ApprovalStatus::Approved)
                    ->whereNotNull('industry')
                    ->where('industry', '!=', '')
                    ->whereNotNull('city')
                    ->where('city', '!=', '')
                    ->whereNotNull('province')
                    ->where('province', '!=', '')
                    ->whereNotNull('language')
                    ->where('language', '!=', '');
            })
            ->whereHas('cvAsset')
            ->whereHas('mailboxIdentity', function ($query): void {
                $query->where('state', MailboxState::Ready);
            })
            ->with(['profile', 'cvAsset', 'mailboxIdentity']);
    }
}
