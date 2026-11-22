<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\ApplyAttemptKind;
use App\Enums\ApprovalStatus;
use App\Enums\MailboxState;
use App\Enums\SiteIdentityState;
use App\Models\Application;
use App\Models\ApplyAttempt;
use App\Models\MailboxIdentity;
use App\Models\SiteIdentity;
use App\Support\ApplyUrlPreference;
use App\Support\GmxAddressValidator;
use Illuminate\Support\Facades\DB;

/**
 * Exclusive Mode B claim for an Application already in applying.
 *
 * Creates an open ApplyAttempt (kind null) when claimable.
 * When status is applying but gates fail, writes a hard_fail
 * ApplyAttempt with a stop_reason so Staff never see a silent hang.
 *
 * Company-site apply (PRD 1.8 / plan 3): requires approved + complete profile
 * and a Ready allowed-GMX apply mailbox (never personal User.email).
 * Board SiteIdentity is NOT a claim gate for company URLs.
 * Board-only URLs hard-fail unless joinera.mode_b.allow_board_apply (legacy probes).
 */
class ClaimApplyingApplication
{
    public function handle(int $applicationId): ?ApplyAttempt
    {
        return DB::transaction(function () use ($applicationId): ?ApplyAttempt {
            /** @var Application|null $application */
            $application = Application::query()->whereKey($applicationId)->lockForUpdate()->first();
            if ($application === null) {
                return null;
            }

            if ($application->status === ApplicationStatus::Submitted) {
                return null;
            }

            if ($application->status !== ApplicationStatus::Applying) {
                return null;
            }

            $open = ApplyAttempt::query()
                ->where('application_id', $application->id)
                ->whereNull('kind')
                ->first();
            if ($open !== null) {
                return $open;
            }

            if (! $this->profileApproved($application)) {
                return $this->finalizeHardFail($application, 'profile_not_approved');
            }

            if (! $this->profileComplete($application)) {
                return $this->finalizeHardFail($application, 'profile_incomplete');
            }

            if (! $this->mailboxReady($application)) {
                return $this->finalizeHardFail($application, 'mailbox_not_ready');
            }

            $target = $this->applyTarget($application);

            if ($target === 'company') {
                $attempt = new ApplyAttempt;
                $attempt->application_id = $application->id;
                $attempt->kind = null;
                $attempt->confirmation_reference = null;
                $attempt->save();

                return $attempt;
            }

            // Legacy board Easy-Apply (opt-in only).
            if ($target === 'board' && (bool) config('joinera.mode_b.allow_board_apply', false)) {
                if (! $this->siteReady($application)) {
                    return $this->finalizeHardFail($application, 'no_healthy_site');
                }

                $attempt = new ApplyAttempt;
                $attempt->application_id = $application->id;
                $attempt->kind = null;
                $attempt->confirmation_reference = null;
                $attempt->save();

                return $attempt;
            }

            return $this->finalizeHardFail($application, 'missing_company_apply_url');
        });
    }

    private function profileApproved(Application $application): bool
    {
        $application->loadMissing('user.profile');
        $profile = $application->user?->profile;

        return $profile !== null && $profile->approval === ApprovalStatus::Approved;
    }

    private function profileComplete(Application $application): bool
    {
        $application->loadMissing(['user.profile', 'user.cvAsset']);
        $user = $application->user;
        $profile = $user?->profile;

        if ($profile === null) {
            return false;
        }

        return $profile->isComplete($user->cvAsset);
    }

    private function mailboxReady(Application $application): bool
    {
        /** @var MailboxIdentity|null $mailbox */
        $mailbox = MailboxIdentity::query()
            ->where('user_id', $application->user_id)
            ->where('state', MailboxState::Ready)
            ->first();

        if ($mailbox === null) {
            return false;
        }

        return app(GmxAddressValidator::class)->isAllowed((string) $mailbox->address);
    }

    private function siteReady(Application $application): bool
    {
        return SiteIdentity::query()
            ->where('user_id', $application->user_id)
            ->where('site', $application->site)
            ->where('state', SiteIdentityState::Healthy)
            ->where('paused', false)
            ->exists();
    }

    /**
     * @return 'company'|'board'|'missing'
     */
    private function applyTarget(Application $application): string
    {
        $application->loadMissing('listing');
        $listing = $application->listing;
        if ($listing === null) {
            return 'missing';
        }

        $url = $listing->apply_url ?: $listing->url;
        $source = (string) ($application->site ?: $listing->source);

        return ApplyUrlPreference::applyTarget($source, $url);
    }

    private function finalizeHardFail(Application $application, string $stopReason): ApplyAttempt
    {
        $attempt = new ApplyAttempt;
        $attempt->application_id = $application->id;
        $attempt->kind = ApplyAttemptKind::HardFail;
        $attempt->stop_reason = $stopReason;
        $attempt->confirmation_reference = null;
        $attempt->save();

        return $attempt;
    }
}
