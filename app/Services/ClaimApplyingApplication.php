<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\ApprovalStatus;
use App\Enums\SiteIdentityState;
use App\Models\Application;
use App\Models\ApplyAttempt;
use App\Models\SiteIdentity;
use Illuminate\Support\Facades\DB;

/**
 * Exclusive Mode B claim for an Application already in applying.
 *
 * Creates an open ApplyAttempt (kind null). Does not move status, upload docs,
 * or call a browser adapter.
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
                return null;
            }

            if (! $this->siteReady($application)) {
                return null;
            }

            $attempt = new ApplyAttempt;
            $attempt->application_id = $application->id;
            $attempt->kind = null;
            $attempt->confirmation_reference = null;
            $attempt->save();

            return $attempt;
        });
    }

    private function profileApproved(Application $application): bool
    {
        $application->loadMissing('user.profile');
        $profile = $application->user?->profile;

        return $profile !== null && $profile->approval === ApprovalStatus::Approved;
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
}
