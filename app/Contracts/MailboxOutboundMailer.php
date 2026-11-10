<?php

namespace App\Contracts;

use App\Models\MailboxIdentity;
use App\Models\MailboxMessage;
use App\Support\Mailbox\OutboundResult;

interface MailboxOutboundMailer
{
    public function sendReply(
        MailboxIdentity $mailbox,
        MailboxMessage $inReplyTo,
        string $bodyText,
    ): OutboundResult;
}
