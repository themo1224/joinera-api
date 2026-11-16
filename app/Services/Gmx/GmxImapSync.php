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
use RuntimeException;
use Throwable;

class GmxImapSync implements MailboxInboundSync
{
    public function __construct(private readonly MinimalImapClient $client) {}

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
                (int) config('joinera.mailbox.imap.port', 143),
                (string) config('joinera.mailbox.imap.encryption', 'starttls'),
            );

            try {
                $this->client->login($socket, $mailbox->address, $password);
            } catch (MailboxAuthFailedException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw new RuntimeException('IMAP login read failed: '.$exception->getMessage(), 0, $exception);
            }

            // First sync (no last_synced_at): fetch ALL. Later: SINCE yesterday for overlap.
            $since = $mailbox->last_synced_at?->subDay();
            try {
                $fetched = $this->client->fetchInboxSince($socket, $since);
            } catch (Throwable $exception) {
                throw new RuntimeException('IMAP fetch failed: '.$exception->getMessage(), 0, $exception);
            }
            $upserted = 0;
            $skipped = 0;

            foreach ($fetched as $row) {
                try {
                    $from = trim((string) $row['from_address']);
                    $to = trim((string) $row['to_address']);
                    $receivedAt = $row['received_at'] ?? now()->toIso8601String();
                    $bodyText = $row['body_text'] !== null ? $this->utf8((string) $row['body_text']) : null;
                    $bodyHtml = $row['body_html'] !== null ? $this->utf8((string) $row['body_html']) : null;

                    if ($bodyText !== null && strlen($bodyText) > 60_000) {
                        $bodyText = substr($bodyText, 0, 60_000)."\n…[truncated]";
                    }

                    if ($bodyHtml !== null && strlen($bodyHtml) > 60_000) {
                        $bodyHtml = substr($bodyHtml, 0, 60_000)."\n…[truncated]";
                    }

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
                            'body_text' => $bodyText,
                            'body_html' => $bodyHtml,
                            'received_at' => $receivedAt,
                            'sent_at' => null,
                        ],
                    );

                    if ($message->wasRecentlyCreated || $message->wasChanged()) {
                        $upserted++;
                    }
                } catch (Throwable $exception) {
                    $skipped++;
                    Log::warning('gmx.imap.sync.message_skipped', [
                        'mailbox_identity_id' => $mailbox->id,
                        'provider_message_id' => $row['provider_message_id'] ?? null,
                        'error' => $exception->getMessage(),
                    ]);
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
                'skipped' => $skipped,
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

            return new SyncResult(error: 'gmx_sync_failed', detail: $exception->getMessage());
        } finally {
            if ($socket !== null) {
                $this->client->logout($socket);
            }
        }
    }

    private function utf8(string $value): string
    {
        $value = str_replace("\0", '', $value);

        // Force a MySQL-safe UTF-8 string even when mb_check_encoding is optimistic.
        $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');

        if (! is_string($converted) || $converted === '') {
            $converted = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $value) ?? '';
        }

        // Drop invalid leftover bytes that still break utf8 (not utf8mb4) columns.
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $converted);

        return is_string($clean) ? $clean : $converted;
    }
}
