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
