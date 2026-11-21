<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\ApprovalStatus;
use App\Enums\SiteIdentityState;
use App\Jobs\SubmitApplicationJob;
use App\Models\Application;
use App\Models\ApplyAttempt;
use App\Models\SiteIdentity;
use App\Support\ApplyUrlPreference;
use Illuminate\Support\Facades\DB;

/**
 * Staff re-dispatch of Mode B apply for an applying Application.
 * Plan 29 — company-site retries do not require board SiteIdentity; soft vs hard is enforced by the job.
 */
class RetryApplyApplication
{
    public function __construct(
        private readonly AssertApplicationDocsReadyForModeB $docsReady,
        private readonly ResolveOpenBlockEvents $resolveOpenBlockEvents,
    ) {}

    /**
     * @return array{ok: true}|array{ok: false, code: string}
     */
    public function handle(Application $application): array
    {
        $application->refresh();
        $application->loadMissing(['user.profile', 'listing']);

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

        $target = $this->applyTarget($application);

        /** @var SiteIdentity|null $identity */
        $identity = SiteIdentity::query()
            ->where('user_id', $application->user_id)
            ->where('site', $application->site)
            ->where('state', SiteIdentityState::Healthy)
            ->first();

        // Plan 29 — board Easy-Apply still needs a healthy identity; company-site does not.
        if ($target !== 'company' && $identity === null) {
            return ['ok' => false, 'code' => 'site_not_ready'];
        }

        DB::transaction(function () use ($application, $identity): void {
            $this->resolveOpenBlockEvents->forApplication((int) $application->id);

            if ($identity !== null && $identity->paused) {
                $identity->paused = false;
                $identity->save();
            }
        });

        SubmitApplicationJob::dispatch($application->id);

        return ['ok' => true];
    }

    /**
     * @return 'company'|'board'|'missing'
     */
    private function applyTarget(Application $application): string
    {
        $listing = $application->listing;
        if ($listing === null) {
            return 'missing';
        }

        $url = $listing->apply_url ?: $listing->url;
        $source = (string) ($application->site ?: $listing->source);

        return ApplyUrlPreference::applyTarget($source, $url);
    }
}
