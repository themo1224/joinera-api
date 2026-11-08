<?php

namespace App\Contracts;

use App\Models\MailboxIdentity;
use App\Models\User;

interface MailboxProvider
{
    public function start(User $user, string $address, string $domain): MailboxIdentity;

    public function markReady(MailboxIdentity $mailbox): MailboxIdentity;

    public function markFailed(MailboxIdentity $mailbox): MailboxIdentity;
}
