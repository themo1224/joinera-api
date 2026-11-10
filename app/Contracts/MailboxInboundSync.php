<?php

namespace App\Contracts;

use App\Exceptions\MailboxAuthFailedException;
use App\Models\MailboxIdentity;
use App\Support\Mailbox\SyncResult;

interface MailboxInboundSync
{
    /**
     * @throws MailboxAuthFailedException
     */
    public function verify(string $address, string $password): void;

    public function sync(MailboxIdentity $mailbox): SyncResult;
}
