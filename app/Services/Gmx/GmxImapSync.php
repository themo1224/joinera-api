<?php

namespace App\Services\Gmx;

use App\Contracts\MailboxInboundSync;
use App\Enums\MailboxMessageDirection;
use App\Enums\MailboxState;
use App\Exceptions\MailboxAuthFailedException;
use App\Models\MailboxIdentity;
use App\Models\MailboxMessage;
use App\Support\Mailbox\SyncResult;
use Illuminate\Support\Facades\Log;
use Throwable;

class GmxImapSync implements MailboxInboundSync
{
    public function __construct(private readonly MinimalImapClient $client)
    {
    }

    public function verify(string $address, string $password): void
    {
        $socket = $this->client->connect(
            (string) config('joinera.mailbox.imap.host', 'imap.gmx.net'),
            (int) config('joinera.mailbox.imap.port', 993),
            (string) config('joinera.mailbox.imap.encryption', 'ssl'),
        );

        try {
            $this->client->login($socket, $address, $password);
        } finally {
            $this->client->logout($socket);
        }
    }

    public function sync(MailboxIdentity $mailbox): SyncResult
    {
        $password = (string) $mailbox->password;

        if ($password === '') {
            return new SyncResult(authFailed: true, error: 'gmx_auth_failed');
        }

        $socket = null;

        try {
            $socket = $this->client->connect(
                (string) config('joinera.mailbox.imap.host', 'imap.gmx.net'),
                (int) config('joinera.mailbox.imap.port', 993),
                (string) config('joinera.mailbox.imap.encryption', 'ssl'),
            );
            $this->client->login($socket, $mailbox->address, $password);

            // First sync (no last_synced_at): fetch ALL. Later: SINCE yesterday for overlap.
            $since = $mailbox->last_synced_at?->subDay();
            $fetched = $this->client->fetchInboxSince($socket, $since);
            $upserted = 0;

            foreach ($fetched as $row) {
                $from = trim((string) $row['from_address']);
                $to = trim((string) $row['to_address']);
                $receivedAt = $row['received_at'] ?? now()->toIso8601String();

                $message = MailboxMessage::query()->updateOrCreate(
                    [
                        'mailbox_identity_id' => $mailbox->id,
                        'provider_message_id' => $this->utf8((string) $row['provider_message_id']),
                    ],
                    [
                        'direction' => MailboxMessageDirection::Inbound,
                        'from_address' => $this->utf8($from !== '' ? $from : 'unknown@gmx.local'),
                        'to_address' => $this->utf8($to !== '' ? $to : $mailbox->address),
                        'subject' => $this->utf8($row['subject'] !== '' ? $row['subject'] : '(no subject)'),
                        'body_text' => $row['body_text'] !== null ? $this->utf8((string) $row['body_text']) : null,
                        'body_html' => $row['body_html'] !== null ? $this->utf8((string) $row['body_html']) : null,
                        'received_at' => $receivedAt,
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

            Log::info('gmx.imap.sync.ok', [
                'mailbox_identity_id' => $mailbox->id,
                'fetched' => count($fetched),
                'upserted' => $upserted,
            ]);

            return new SyncResult(upserted: $upserted);
        } catch (MailboxAuthFailedException $exception) {
            $mailbox->forceFill([
                'state' => MailboxState::Failed,
                'last_sync_error' => 'gmx_auth_failed',
            ])->save();

            return new SyncResult(authFailed: true, error: $exception->getMessage());
        } catch (Throwable $exception) {
            $mailbox->forceFill([
                'last_sync_error' => 'gmx_sync_failed',
            ])->save();

            Log::warning('gmx.imap.sync.failed', [
                'mailbox_identity_id' => $mailbox->id,
                'error' => $exception->getMessage(),
            ]);

            return new SyncResult(error: 'gmx_sync_failed');
        } finally {
            if ($socket !== null) {
                $this->client->logout($socket);
            }
        }
    }

    private function utf8(string $value): string
    {
        $value = str_replace("\0", '', $value);

        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');

        if (! is_string($converted) || $converted === '') {
            return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $value) ?? '';
        }

        return $converted;
    }
}
