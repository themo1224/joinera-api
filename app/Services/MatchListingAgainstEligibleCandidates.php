<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\MailboxState;
use App\Enums\Role;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class MatchListingAgainstEligibleCandidates
{
    public function __construct(
        private readonly ResolveCandidateEligibility $eligibility,
        private readonly UpsertCandidateListingMatch $upsertMatch,
    ) {}

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
     * @return Builder<User>
     */
    private function candidateQuery()
    {
        return User::query()
            ->where('role', Role::User)
            ->whereHas('profile', function ($query): void {
                $query->where('approval', ApprovalStatus::Approved)
                    ->whereNotNull('industry')
                    ->where('industry', '!=', '[]')
                    ->whereNotNull('language')
                    ->where('language', '!=', '')
                    ->whereNotNull('education')
                    ->where('education', '!=', '')
                    ->whereNotNull('desired_path')
                    ->where('desired_path', '!=', '');
            })
            ->whereHas('mailboxIdentity', function ($query): void {
                $query->where('state', MailboxState::Ready);
            })
            ->with(['profile', 'cvAsset', 'mailboxIdentity']);
    }
}
