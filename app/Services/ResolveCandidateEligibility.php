<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\MailboxState;
use App\Models\User;

class ResolveCandidateEligibility
{
    /**
     * @return array{eligible: bool, reasons: list<string>}
     */
    public function handle(User $user): array
    {
        $user->loadMissing(['profile', 'cvAsset', 'mailboxIdentity']);

        $reasons = [];

        $mailbox = $user->mailboxIdentity;
        if ($mailbox === null || $mailbox->state !== MailboxState::Ready) {
            $reasons[] = 'mailbox_not_ready';
        }

        $profile = $user->profile;
        if ($profile === null || ! $profile->isComplete($user->cvAsset)) {
            $reasons[] = 'profile_incomplete';
        }

        if ($profile === null || $profile->approval !== ApprovalStatus::Approved) {
            $reasons[] = 'profile_not_approved';
        }

        return [
            'eligible' => $reasons === [],
            'reasons' => $reasons,
        ];
    }
}
