<?php

namespace App\Services;

use App\Contracts\MailboxInboundSync;
use App\Enums\MailboxMessageDirection;
use App\Enums\MailboxState;
use App\Exceptions\MailboxAuthFailedException;
use App\Models\MailboxIdentity;
use App\Models\MailboxMessage;
use App\Support\Mailbox\SyncResult;

class FakeMailboxInboundSync implements MailboxInboundSync
{
    private bool $failAuth = false;

    /** @var array<string, list<array<string, mixed>>> */
    private array $queuedByAddress = [];

    public function failAuth(bool $enabled = true): void
    {
        $this->failAuth = $enabled;
    }

    /**
     * @param  array{
     *     provider_message_id?: string,
     *     from_address?: string,
     *     to_address?: string,
     *     subject?: string,
     *     body_text?: string|null,
     *     body_html?: string|null,
     *     received_at?: string|null
     * }  $message
     */
    public function queueInbound(string $address, array $message): void
    {
        $key = strtolower(trim($address));
        $this->queuedByAddress[$key] ??= [];
        $this->queuedByAddress[$key][] = $message;
    }

    public function verify(string $address, string $password): void
    {
        if ($this->failAuth || $password === '') {
            throw new MailboxAuthFailedException('gmx_auth_failed');
        }
    }

    public function sync(MailboxIdentity $mailbox): SyncResult
    {
        $password = (string) $mailbox->password;

        if ($this->failAuth || $password === '') {
            $mailbox->forceFill([
                'state' => MailboxState::Failed,
                'last_sync_error' => 'gmx_auth_failed',
            ])->save();

            return new SyncResult(authFailed: true, error: 'gmx_auth_failed');
        }

        $key = strtolower(trim($mailbox->address));
        $queued = $this->queuedByAddress[$key] ?? [];
        unset($this->queuedByAddress[$key]);

        $upserted = 0;

        foreach ($queued as $row) {
            $providerId = (string) ($row['provider_message_id'] ?? ('fake-'.uniqid('', true)));

            $message = MailboxMessage::query()->updateOrCreate(
                [
                    'mailbox_identity_id' => $mailbox->id,
                    'provider_message_id' => $providerId,
                ],
                [
                    'direction' => MailboxMessageDirection::Inbound,
                    'from_address' => (string) ($row['from_address'] ?? 'employer@example.test'),
                    'to_address' => (string) ($row['to_address'] ?? $mailbox->address),
                    'subject' => (string) ($row['subject'] ?? ''),
                    'body_text' => $row['body_text'] ?? null,
                    'body_html' => $row['body_html'] ?? null,
                    'received_at' => $row['received_at'] ?? now(),
                    'sent_at' => null,
                ],
            );

            if ($message->wasRecentlyCreated || $message->wasChanged()) {
                $upserted++;
            }
        }

        $mailbox->forceFill([
            'state' => MailboxState::Ready,
            'last_synced_at' => now(),
            'last_sync_error' => null,
        ])->save();

        return new SyncResult(upserted: $upserted);
    }
}
