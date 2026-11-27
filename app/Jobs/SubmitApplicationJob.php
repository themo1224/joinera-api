<?php

namespace App\Jobs;

use App\Contracts\ModeBSubmitAfterDocsReady;
use App\Enums\ApplyAttemptKind;
use App\Models\Application;
use App\Models\ApplyAttempt;
use App\Services\Apply\ApplySlotLimiter;
use App\Services\AssertApplicationDocsReadyForModeB;
use App\Services\ClaimApplyingApplication;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Mode B submit job — claim applying, gate on docs readiness, then Plan 3 hook.
 * Plan 29 — only ModeBRetryableException retries (tries/backoff). Challenge + hard_fail stop cleanly.
 */
class SubmitApplicationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Plan 29 — soft failures only; challenge/hard_fail do not throw so they never loop. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly int $applicationId,
    ) {
        $this->onQueue((string) config('joinera.queues.apply', 'apply'));
    }

    public function uniqueId(): string
    {
        return (string) $this->applicationId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('apply-submit-'.$this->applicationId))->releaseAfter(60),
        ];
    }

    public function handle(
        ClaimApplyingApplication $claim,
        AssertApplicationDocsReadyForModeB $docsReady,
        ModeBSubmitAfterDocsReady $afterDocsReady,
        ApplySlotLimiter $slotLimiter,
    ): void {
        $attempt = $claim->handle($this->applicationId);
        if ($attempt === null) {
            return;
        }

        // Claim already finalized a hard_fail (e.g. profile_not_approved / missing_company_apply_url).
        if ($attempt->kind !== null) {
            return;
        }

        $application = Application::query()->find($this->applicationId);
        if ($application === null) {
            return;
        }

        $gate = $docsReady->handle($application);
        if (! $gate['ready']) {
            $attempt->kind = ApplyAttemptKind::HardFail;
            $attempt->stop_reason = $gate['stop_reason'];
            $attempt->save();

            return;
        }

        // Plan 32 — anti-block slot before browser work (inactive layers when config null).
        $slot = $slotLimiter->requestSlot((int) $application->user_id, (string) $application->site);
        if (! $slot['allowed']) {
            $attempt->kind = ApplyAttemptKind::HardFail;
            $attempt->stop_reason = $slot['code'] ?? ApplySlotLimiter::DENY_CAP;
            $attempt->save();

            return;
        }

        if (is_int($slot['jitterSeconds']) && $slot['jitterSeconds'] > 0) {
            $slotLimiter->assertJitterInBand($slot['jitterSeconds']);
            sleep($slot['jitterSeconds']);
        }

        $afterDocsReady->handle($application, $attempt);
    }

    /**
     * Safety net: never leave ApplyAttempt kind=null after queue failure (timeout / crash / max tries).
     * Staff admin would otherwise show "Open" forever and block RetryApply with apply_in_progress.
     */
    public function failed(?Throwable $e): void
    {
        $attempt = ApplyAttempt::query()
            ->where('application_id', $this->applicationId)
            ->whereNull('kind')
            ->orderByDesc('id')
            ->first();

        if ($attempt === null) {
            return;
        }

        $attempt->kind = ApplyAttemptKind::Retryable;
        $attempt->stop_reason = $this->stopReasonFromFailure($e);
        $attempt->confirmation_reference = null;
        $attempt->save();
    }

    private function stopReasonFromFailure(?Throwable $e): string
    {
        if ($e === null) {
            return 'job_failed';
        }

        $msg = strtolower($e->getMessage());
        if (
            str_contains($msg, 'timed out')
            || str_contains($msg, 'timeout')
            || str_contains($msg, 'curl error 28')
            || str_contains($msg, 'operation timed out')
            || str_contains($msg, 'worker_timeout')
        ) {
            return 'worker_timeout';
        }

        $raw = trim($e->getMessage());
        if ($raw === '') {
            return 'job_failed';
        }

        return mb_substr($raw, 0, 255);
    }
}
