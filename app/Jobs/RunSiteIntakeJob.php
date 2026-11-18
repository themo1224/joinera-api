<?php

namespace App\Jobs;

use App\Models\IntakeRun;
use App\Services\RunSiteIntake;
use App\Services\StartIntakeRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunSiteIntakeJob implements ShouldQueue
{
    use Queueable;

    /**
     * Intake can fetch many listing detail pages; keep above DB retry_after floor.
     */
    public int $timeout = 540;

    public function __construct(
        public readonly int $intakeRunId,
        public readonly string $site,
    ) {
    }

    public function handle(RunSiteIntake $runSiteIntake, StartIntakeRun $startIntakeRun): void
    {
        $run = IntakeRun::query()->find($this->intakeRunId);

        if ($run === null) {
            return;
        }

        $runSiteIntake->handle($run, $this->site);
        $startIntakeRun->markFinishedIfComplete($run->fresh());
    }
}
