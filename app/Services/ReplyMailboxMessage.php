<?php

namespace App\Services;

use App\Contracts\MailboxOutboundMailer;
use App\Enums\MailboxMessageDirection;
use App\Enums\MailboxState;
use App\Exceptions\MailboxAuthFailedException;
use App\Models\MailboxIdentity;
use App\Models\MailboxMessage;
use RuntimeException;

class ReplyMailboxMessage
{
    public function __construct(private readonly MailboxOutboundMailer $outboundMailer)
    {
    }

    public function handle(MailboxIdentity $mailbox, MailboxMessage $message, string $bodyText): MailboxMessage
    {
        if ($mailbox->state !== MailboxState::Ready) {
            throw new RuntimeException('mailbox_not_ready');
        }

        if ($message->mailbox_identity_id !== $mailbox->id) {
            throw new RuntimeException('message_not_found');
        }

        $result = $this->outboundMailer->sendReply($mailbox, $message, $bodyText);

        if (! $result->sent) {
            throw new MailboxAuthFailedException($result->error ?? 'gmx_smtp_failed');
        }

        return MailboxMessage::query()->create([
            'mailbox_identity_id' => $mailbox->id,
            'provider_message_id' => $result->providerMessageId ?? ('outbound-'.uniqid('', true)),
            'direction' => MailboxMessageDirection::Outbound,
            'from_address' => $mailbox->address,
            'to_address' => $message->from_address,
            'subject' => $this->replySubject($message->subject),
            'body_text' => $bodyText,
            'body_html' => null,
            'received_at' => null,
            'sent_at' => now(),
            'in_reply_to_message_id' => $message->id,
        ]);
    }

    private function replySubject(string $subject): string
    {
        $trimmed = trim($subject);

        if ($trimmed === '') {
            return 'Re:';
        }

        if (preg_match('/^re:\s*/i', $trimmed) === 1) {
            return $trimmed;
        }

        return 'Re: '.$trimmed;
    }
}
