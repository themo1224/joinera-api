<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\ApprovalStatus;
use App\Enums\SiteIdentityState;
use App\Jobs\SubmitApplicationJob;
use App\Models\Application;
use App\Models\ApplyAttempt;
use App\Models\SiteIdentity;
use Illuminate\Support\Facades\DB;

/**
 * Staff re-dispatch of Mode B apply for an applying Application.
 */
class RetryApplyApplication
{
    public function __construct(
        private readonly AssertApplicationDocsReadyForModeB $docsReady,
    ) {}

    /**
     * @return array{ok: true}|array{ok: false, code: string}
     */
    public function handle(Application $application): array
    {
        $application->refresh();
        $application->loadMissing(['user.profile']);

        if ($application->status === ApplicationStatus::Submitted) {
            return ['ok' => false, 'code' => 'application_already_submitted'];
        }

        if ($application->status !== ApplicationStatus::Applying) {
            return ['ok' => false, 'code' => 'retry_apply_wrong_status'];
        }

        $openAttempt = ApplyAttempt::query()
            ->where('application_id', $application->id)
            ->whereNull('kind')
            ->exists();
        if ($openAttempt) {
            return ['ok' => false, 'code' => 'apply_in_progress'];
        }

        $docs = $this->docsReady->handle($application);
        if (! $docs['ready']) {
            return ['ok' => false, 'code' => 'docs_not_ready'];
        }

        $profile = $application->user?->profile;
        if ($profile === null || $profile->approval !== ApprovalStatus::Approved) {
            return ['ok' => false, 'code' => 'profile_not_approved'];
        }

        /** @var SiteIdentity|null $identity */
        $identity = SiteIdentity::query()
            ->where('user_id', $application->user_id)
            ->where('site', $application->site)
            ->where('state', SiteIdentityState::Healthy)
            ->first();

        if ($identity === null) {
            return ['ok' => false, 'code' => 'site_not_ready'];
        }

        DB::transaction(function () use ($identity): void {
            if ($identity->paused) {
                $identity->paused = false;
                $identity->save();
            }
        });

        SubmitApplicationJob::dispatch($application->id);

        return ['ok' => true];
    }
}
