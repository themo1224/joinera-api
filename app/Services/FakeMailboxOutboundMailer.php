<?php

namespace App\Services;

use App\Contracts\MailboxOutboundMailer;
use App\Models\MailboxIdentity;
use App\Models\MailboxMessage;
use App\Support\Mailbox\OutboundResult;

class FakeMailboxOutboundMailer implements MailboxOutboundMailer
{
    private bool $failSend = false;

    /** @var list<array{mailbox_id: int, to: string, body: string, in_reply_to: int}> */
    private array $sent = [];

    public function failSend(bool $enabled = true): void
    {
        $this->failSend = $enabled;
    }

    /**
     * @return list<array{mailbox_id: int, to: string, body: string, in_reply_to: int}>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    public function sendReply(
        MailboxIdentity $mailbox,
        MailboxMessage $inReplyTo,
        string $bodyText,
    ): OutboundResult {
        if ($this->failSend || (string) $mailbox->password === '') {
            return new OutboundResult(sent: false, error: 'gmx_smtp_failed');
        }

        $providerMessageId = sprintf('<fake-outbound-%s@joinera.test>', uniqid('', true));

        $this->sent[] = [
            'mailbox_id' => $mailbox->id,
            'to' => $inReplyTo->from_address,
            'body' => $bodyText,
            'in_reply_to' => $inReplyTo->id,
        ];

        return new OutboundResult(sent: true, providerMessageId: $providerMessageId);
    }
}
