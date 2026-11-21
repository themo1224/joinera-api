<?php

namespace App\Services\Apply;

use App\Contracts\ModeBBrowserPort;
use App\Contracts\ModeBSubmitAfterDocsReady;
use App\Enums\ApplicationStatus;
use App\Enums\ApplyAttemptKind;
use App\Enums\ModeBBrowserOutcome;
use App\Events\ApplicationStatusChanged;
use App\Exceptions\ModeBRetryableException;
use App\Jobs\RecordApplyChallengeAlert;
use App\Models\Application;
use App\Models\ApplyAttempt;
use App\Models\BlockEvent;
use App\Models\SiteIdentity;
use App\Support\ScrubSecrets;
use Illuminate\Support\Facades\DB;

/**
 * Mode B submit after docs gate: call browser port, then persist outcome.
 */
class RunModeBSubmitAfterDocsReady implements ModeBSubmitAfterDocsReady
{
    public function __construct(
        private readonly ModeBBrowserPort $browser,
    ) {}

    public function handle(Application $application, ApplyAttempt $attempt): void
    {
        $application->refresh();

        if ($application->status === ApplicationStatus::Submitted) {
            return;
        }

        $result = $this->browser->submit(
            (int) $application->user_id,
            (string) $application->site,
            (int) $application->id,
        );

        match ($result->outcome) {
            ModeBBrowserOutcome::Success => $this->markSubmitted($application, $attempt, $result),
            ModeBBrowserOutcome::IdentityMismatch => $this->markIdentityMismatch($attempt, $result),
            ModeBBrowserOutcome::Challenge => $this->markChallenge($application, $attempt, $result),
            ModeBBrowserOutcome::Retryable => $this->markRetryable($attempt, $result),
        };
    }

    private function markSubmitted(
        Application $application,
        ApplyAttempt $attempt,
        ModeBBrowserResult $result,
    ): void {
        DB::transaction(function () use ($application, $attempt, $result): void {
            /** @var Application $locked */
            $locked = Application::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ApplicationStatus::Applying) {
                return;
            }

            $from = $locked->status->value;
            $locked->status = ApplicationStatus::Submitted;
            $locked->save();

            ApplicationStatusChanged::dispatch(
                $locked->id,
                $from,
                ApplicationStatus::Submitted->value,
            );

            $attempt->kind = ApplyAttemptKind::Success;
            $attempt->confirmation_reference = $result->confirmationReference;
            $attempt->stop_reason = null;
            // Plan 16 — keep form_plan on success for Staff replay/debug.
            if (is_array($result->formPlan)) {
                $attempt->form_plan = ScrubSecrets::array($result->formPlan);
            }
            $attempt->save();

            $application->refresh();
        });
    }

    private function markIdentityMismatch(ApplyAttempt $attempt, ModeBBrowserResult $result): void
    {
        $attempt->kind = ApplyAttemptKind::HardFail;
        // Preserve worker detail (e.g. external_apply:https://...) so Staff sees the company link.
        $detail = trim((string) ($result->detail ?? ''));
        $attempt->stop_reason = $detail !== ''
            ? mb_substr($detail, 0, 255)
            : ModeBBrowserOutcome::IdentityMismatch->value;
        $attempt->confirmation_reference = null;
        // Plan 10 — persist form plan for replay/debug (even when missing_info).
        if (is_array($result->formPlan)) {
            $attempt->form_plan = ScrubSecrets::array($result->formPlan);
        }
        $attempt->save();
    }

    private function markChallenge(
        Application $application,
        ApplyAttempt $attempt,
        ModeBBrowserResult $result,
    ): void {
        $observed = $this->normalizeObservedKind($result->detail);

        $blockEventId = null;

        DB::transaction(function () use ($application, $attempt, $observed, $result, &$blockEventId): void {
            $attempt->kind = ApplyAttemptKind::Challenge;
            $attempt->stop_reason = $observed;
            $attempt->confirmation_reference = null;
            // Plan 25/28 — keep scrubbed form_plan (incl. block audit) for Staff pause triage.
            if (is_array($result->formPlan)) {
                $attempt->form_plan = ScrubSecrets::array($result->formPlan);
            }
            $attempt->save();

            // Plan 25 — open pause = resolved_at null (works without board SiteIdentity).
            $block = BlockEvent::query()->create([
                'user_id' => $application->user_id,
                'site' => $application->site,
                'application_id' => $application->id,
                'observed_kind' => $observed,
                'resolved_at' => null,
            ]);
            $blockEventId = $block->id;

            // Legacy board path: also pause SiteIdentity when present.
            SiteIdentity::query()
                ->where('user_id', $application->user_id)
                ->where('site', $application->site)
                ->where('paused', false)
                ->update(['paused' => true]);
        });

        RecordApplyChallengeAlert::dispatch(
            (int) $application->user_id,
            (string) $application->site,
            (int) $application->id,
            $observed,
            $blockEventId,
        );
    }

    private function markRetryable(ApplyAttempt $attempt, ModeBBrowserResult $result): void
    {
        $attempt->kind = ApplyAttemptKind::Retryable;
        $attempt->stop_reason = $result->detail;
        $attempt->confirmation_reference = null;
        // Plan 28/29 — soft failure audit only; challenge/hard paths do not throw (no infinite loop).
        if (is_array($result->formPlan)) {
            $attempt->form_plan = ScrubSecrets::array($result->formPlan);
        }
        $attempt->save();

        throw new ModeBRetryableException($result->detail ?? 'mode_b_retryable');
    }

    private function normalizeObservedKind(?string $detail): string
    {
        $raw = strtolower(trim((string) $detail));

        return match ($raw) {
            BlockEvent::KIND_TWO_FACTOR, '2fa', 'two-factor' => BlockEvent::KIND_TWO_FACTOR,
            BlockEvent::KIND_LOGIN_WALL, 'login', 'soft_signup_failed', 'missing_apply_email', 'not_simple_signup' => BlockEvent::KIND_LOGIN_WALL,
            BlockEvent::KIND_COOKIE_WALL, 'cookie', 'cookie-wall' => BlockEvent::KIND_COOKIE_WALL,
            BlockEvent::KIND_CAPTCHA => BlockEvent::KIND_CAPTCHA,
            default => BlockEvent::KIND_CAPTCHA,
        };
    }
}
