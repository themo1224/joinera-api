<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\MailboxState;
use App\Enums\Role;
use App\Models\User;

class BuildApplicantDirectoryItem
{
    public function __construct(
        private readonly ResolveIdentityReadiness $readiness,
        private readonly ResolveCandidateEligibility $eligibility,
    ) {
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     email: string|null,
     *     industry: string,
     *     city: string,
     *     province: string,
     *     language: string,
     *     hasCv: bool,
     *     profileComplete: bool,
     *     approval: string,
     *     approvedById: string|null,
     *     approvedByEmail: string|null,
     *     approvedAt: string|null,
     *     readiness: string,
     *     matchEligible: bool,
     *     matchEligibilityReasons: list<string>,
     *     mailboxState: string,
     *     mailboxDomain: string|null,
     *     mailboxAddress: string|null,
     *     mailboxLastSyncedAt: string|null,
     *     mailboxSyncError: string|null,
     *     createdAt: string
     * }
     */
    public function handle(User $user): array
    {
        if ($user->role !== Role::User) {
            abort(404);
        }

        $user->loadMissing(['profile.approvedBy', 'cvAsset', 'mailboxIdentity']);

        $profile = $user->profile;
        $mailbox = $user->mailboxIdentity;
        $readiness = $this->readiness->handle($user);
        $eligibility = $this->eligibility->handle($user);

        $approval = ($profile?->approval ?? ApprovalStatus::Pending)->value;
        $approvedBy = $profile?->approvedBy;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'industry' => (string) ($profile?->industry ?? ''),
            'city' => (string) ($profile?->city ?? ''),
            'province' => (string) ($profile?->province ?? ''),
            'language' => (string) ($profile?->language ?? ''),
            'hasCv' => $user->cvAsset !== null,
            'profileComplete' => $profile?->isComplete($user->cvAsset) ?? false,
            'approval' => $approval,
            'approvedById' => $approvedBy !== null ? (string) $approvedBy->id : null,
            'approvedByEmail' => $approvedBy?->email,
            'approvedAt' => $profile?->approved_at?->toISOString(),
            'readiness' => $readiness['readiness'],
            'matchEligible' => $eligibility['eligible'],
            'matchEligibilityReasons' => $eligibility['reasons'],
            'mailboxState' => ($mailbox?->state ?? MailboxState::Pending)->value,
            'mailboxDomain' => $mailbox?->domain,
            'mailboxAddress' => $mailbox?->address,
            'mailboxLastSyncedAt' => $mailbox?->last_synced_at?->toISOString(),
            'mailboxSyncError' => $mailbox?->last_sync_error,
            'createdAt' => $user->created_at?->toISOString() ?? now()->toISOString(),
        ];
    }
}
