<?php

namespace App\Services\Gmx;

use App\Contracts\MailboxOutboundMailer;
use App\Models\MailboxIdentity;
use App\Models\MailboxMessage;
use App\Support\Mailbox\OutboundResult;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;
use Throwable;

class GmxSmtpMailer implements MailboxOutboundMailer
{
    public function sendReply(
        MailboxIdentity $mailbox,
        MailboxMessage $inReplyTo,
        string $bodyText,
    ): OutboundResult {
        $password = (string) $mailbox->password;

        if ($password === '') {
            return new OutboundResult(sent: false, error: 'gmx_auth_failed');
        }

        $to = $inReplyTo->from_address;
        $subject = $this->replySubject($inReplyTo->subject);
        $providerMessageId = sprintf('<%s@joinera-outbound>', bin2hex(random_bytes(16)));

        try {
            $transport = new EsmtpTransport(
                (string) config('joinera.mailbox.smtp.host', 'mail.gmx.net'),
                (int) config('joinera.mailbox.smtp.port', 587),
            );
            $transport->setUsername($mailbox->address);
            $transport->setPassword($password);

            $email = (new Email)
                ->from($mailbox->address)
                ->to($to)
                ->subject($subject)
                ->text($bodyText)
                ->replyTo($mailbox->address);

            $email->getHeaders()->addIdHeader('Message-ID', trim($providerMessageId, '<>'));

            if ($inReplyTo->provider_message_id !== '') {
                $email->getHeaders()->addTextHeader('In-Reply-To', $inReplyTo->provider_message_id);
                $email->getHeaders()->addTextHeader('References', $inReplyTo->provider_message_id);
            }

            $transport->send($email);

            return new OutboundResult(sent: true, providerMessageId: $providerMessageId);
        } catch (Throwable $exception) {
            return new OutboundResult(sent: false, error: 'gmx_smtp_failed');
        }
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
