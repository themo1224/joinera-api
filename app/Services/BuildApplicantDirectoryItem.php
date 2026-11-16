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
    ) {}

    /**
     * @return array<string, mixed>
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
            'industry' => $profile?->industryList() ?? [],
            'language' => (string) ($profile?->language ?? ''),
            'education' => (string) ($profile?->education ?? ''),
            'desiredPath' => (string) ($profile?->desired_path ?? ''),
            'preferredOccupation' => (string) ($profile?->preferred_occupation ?? ''),
            'availableFrom' => $profile?->available_from?->format('Y-m-d'),
            'willingToRelocate' => $profile?->willing_to_relocate,
            'educationEntries' => $profile?->education_entries ?? [],
            'experiences' => $profile?->experiences ?? [],
            'skills' => $profile?->skills ?? [],
            'languages' => $profile?->languages ?? [],
            'drivingLicenses' => $profile?->driving_licenses ?? [],
            'workResidenceNote' => (string) ($profile?->work_residence_note ?? ''),
            'about' => (string) ($profile?->about ?? ''),
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
            'mailboxLastSyncError' => $mailbox?->last_sync_error,
            'createdAt' => $user->created_at?->toISOString() ?? now()->toISOString(),
        ];
    }
}
