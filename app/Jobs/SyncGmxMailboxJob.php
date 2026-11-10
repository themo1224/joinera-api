<?php

namespace App\Jobs;

use App\Contracts\MailboxInboundSync;
use App\Enums\MailboxState;
use App\Models\MailboxIdentity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncGmxMailboxJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $mailboxIdentityId)
    {
    }

    public function handle(MailboxInboundSync $inboundSync): void
    {
        $mailbox = MailboxIdentity::query()->find($this->mailboxIdentityId);

        if ($mailbox === null) {
            return;
        }

        if ($mailbox->state !== MailboxState::Ready && $mailbox->state !== MailboxState::Failed) {
            return;
        }

        if ((string) $mailbox->password === '') {
            $mailbox->forceFill([
                'state' => MailboxState::Failed,
                'last_sync_error' => 'gmx_auth_failed',
            ])->save();

            return;
        }

        $inboundSync->sync($mailbox);
    }
}
