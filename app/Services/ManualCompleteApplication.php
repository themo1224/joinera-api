<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\ApplyAttemptKind;
use App\Enums\SiteIdentityState;
use App\Events\ApplicationStatusChanged;
use App\Models\Application;
use App\Models\ApplyAttempt;
use App\Models\BlockEvent;
use App\Models\SiteIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Staff Plan B — mark a stuck applying application as submitted by hand.
 * Does not delete mailbox. Resolves open BlockEvents (same spirit as retry).
 */
class ManualCompleteApplication
{
    public function __construct(
        private readonly ResolveOpenBlockEvents $resolveOpenBlockEvents,
    ) {}

    /**
     * @return array{
     *     ok: true,
     *     application: Application,
     *     alreadySubmitted: bool
     * }|array{ok: false, code: string}
     */
    public function handle(Application $application, User $actor): array
    {
        $application->refresh();
        $application->loadMissing(['user', 'applyAttempts']);

        if ($application->user === null) {
            return ['ok' => false, 'code' => 'not_found'];
        }

        if ($application->status === ApplicationStatus::Submitted) {
            return [
                'ok' => true,
                'application' => $application->fresh(['user', 'listing', 'candidateListingMatch', 'docArtifacts', 'applyAttempts', 'manuallyCompletedBy']),
                'alreadySubmitted' => true,
            ];
        }

        if ($application->status !== ApplicationStatus::Applying) {
            return ['ok' => false, 'code' => 'not_eligible'];
        }

        if (! $this->isEligible($application)) {
            return ['ok' => false, 'code' => 'not_eligible'];
        }

        DB::transaction(function () use ($application, $actor): void {
            /** @var Application $locked */
            $locked = Application::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === ApplicationStatus::Submitted) {
                return;
            }

            if ($locked->status !== ApplicationStatus::Applying || ! $this->isEligible($locked)) {
                return;
            }

            $from = $locked->status->value;
            $locked->status = ApplicationStatus::Submitted;
            $locked->manually_completed_at = now();
            $locked->manually_completed_by = $actor->id;
            $locked->save();

            ApplicationStatusChanged::dispatch(
                $locked->id,
                $from,
                ApplicationStatus::Submitted->value,
            );

            $this->resolveOpenBlockEvents->forApplication((int) $locked->id);

            /** @var SiteIdentity|null $identity */
            $identity = SiteIdentity::query()
                ->where('user_id', $locked->user_id)
                ->where('site', $locked->site)
                ->where('state', SiteIdentityState::Healthy)
                ->first();

            if ($identity !== null && $identity->paused) {
                $identity->paused = false;
                $identity->save();
            }
        });

        $fresh = $application->fresh([
            'user',
            'listing',
            'candidateListingMatch',
            'docArtifacts',
            'applyAttempts',
            'manuallyCompletedBy',
        ]);

        if ($fresh === null || $fresh->status !== ApplicationStatus::Submitted) {
            return ['ok' => false, 'code' => 'not_eligible'];
        }

        return [
            'ok' => true,
            'application' => $fresh,
            'alreadySubmitted' => false,
        ];
    }

    public function isEligible(Application $application): bool
    {
        if ($application->status !== ApplicationStatus::Applying) {
            return false;
        }

        $hasOpenBlock = BlockEvent::query()
            ->where('application_id', $application->id)
            ->whereNull('resolved_at')
            ->exists();

        if ($hasOpenBlock) {
            return true;
        }

        return ApplyAttempt::query()
            ->where('application_id', $application->id)
            ->where('kind', ApplyAttemptKind::HardFail)
            ->exists();
    }
}
