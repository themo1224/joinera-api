<?php

namespace App\Jobs;

use App\Models\AdminAlert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecordApplyChallengeAlert implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $userId,
        public readonly string $site,
        public readonly int $applicationId,
        public readonly string $observedKind,
        public readonly ?int $blockEventId = null,
    ) {
        $this->onQueue((string) config('joinera.queues.alerts', 'alerts'));
    }

    public function handle(): void
    {
        AdminAlert::query()->create([
            'kind' => AdminAlert::KIND_APPLY_CHALLENGE,
            'site' => $this->site,
            'payload' => [
                'user_id' => $this->userId,
                'application_id' => $this->applicationId,
                'observed_kind' => $this->observedKind,
                'block_event_id' => $this->blockEventId,
            ],
        ]);
    }
}
