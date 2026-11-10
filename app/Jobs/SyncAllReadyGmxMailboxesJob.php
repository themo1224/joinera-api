<?php

namespace App\Jobs;

use App\Enums\MailboxState;
use App\Models\MailboxIdentity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncAllReadyGmxMailboxesJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        MailboxIdentity::query()
            ->where('state', MailboxState::Ready)
            ->whereNotNull('password')
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $id): void {
                SyncGmxMailboxJob::dispatch($id);
            });
    }
}
