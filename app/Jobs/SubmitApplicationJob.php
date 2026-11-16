<?php

namespace App\Jobs;

use App\Contracts\ModeBSubmitAfterDocsReady;
use App\Enums\ApplyAttemptKind;
use App\Models\Application;
use App\Services\AssertApplicationDocsReadyForModeB;
use App\Services\ClaimApplyingApplication;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Mode B submit job — claim applying, gate on docs readiness, then Plan 3 hook.
 */
class SubmitApplicationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

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
    ): void {
        $attempt = $claim->handle($this->applicationId);
        if ($attempt === null) {
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

        $afterDocsReady->handle($application, $attempt);
    }
}
