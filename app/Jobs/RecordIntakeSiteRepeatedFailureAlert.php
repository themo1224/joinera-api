<?php

namespace App\Jobs;

use App\Models\AdminAlert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecordIntakeSiteRepeatedFailureAlert implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $site,
        public readonly int $intakeRunId,
    ) {
    }

    public function handle(): void
    {
        AdminAlert::query()->create([
            'kind' => AdminAlert::KIND_INTAKE_SITE_REPEATED_FAILURE,
            'site' => $this->site,
            'payload' => [
                'intake_run_id' => $this->intakeRunId,
            ],
        ]);
    }
}
