<?php

namespace App\Services\Gmx;

use App\Exceptions\MailboxAuthFailedException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Minimal IMAP client for GMX login + inbox fetch (no HTTP APIs).
 * Correctly reads IMAP literals ({N} + N bytes) so BODY fetches do not break the stream.
 */
class MinimalImapClient
{
    /**
     * @return resource
     */
    public function connect(string $host, int $port, string $encryption)
    {
        $encryption = strtolower(trim($encryption));
        $errors = [];

        // Implicit SSL (993) is often blocked by local firewalls/VPNs; fall back to STARTTLS on 143.
        if (in_array($encryption, ['ssl', 'tls'], true)) {
            try {
                return $this->connectImplicitSsl($host, $port > 0 ? $port : 993);
            } catch (RuntimeException $exception) {
                $errors[] = $exception->getMessage();
                Log::warning('gmx.imap.ssl_connect_failed', [
                    'host' => $host,
                    'port' => $port,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (in_array($encryption, ['ssl', 'tls', 'starttls'], true)) {
            $startTlsPort = $encryption === 'starttls' && $port > 0 ? $port : 143;
            $lastStartTlsError = null;

            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    return $this->connectWithStartTls($host, $startTlsPort);
                } catch (RuntimeException $exception) {
                    $lastStartTlsError = $exception->getMessage();
                    Log::warning('gmx.imap.starttls_attempt_failed', [
                        'attempt' => $attempt,
                        'error' => $lastStartTlsError,
                    ]);
                    usleep(350_000);
                }
            }

            if ($lastStartTlsError !== null) {
                $errors[] = $lastStartTlsError;
            }
        }

        throw new RuntimeException(
            'IMAP connect failed: '.($errors !== [] ? implode(' | ', $errors) : "unsupported encryption [{$encryption}]"),
        );
    }

    /**
     * @return resource
     */
    private function connectImplicitSsl(string $host, int $port)
    {
        $remote = sprintf('ssl://%s:%d', $host, $port);
        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            20,
            STREAM_CLIENT_CONNECT,
            $this->sslContext($host),
        );

        if ($socket === false) {
            throw new RuntimeException("IMAP SSL connect failed: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, 90);
        $banner = $this->readLineOrNull($socket);

        if ($banner === null) {
            fclose($socket);
            throw new RuntimeException('IMAP SSL connected but greeting was not received (port 993 may be blocked).');
        }

        return $socket;
    }

    /**
     * @return resource
     */
    private function connectWithStartTls(string $host, int $port)
    {
        $remote = sprintf('tcp://%s:%d', $host, $port);
        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            20,
            STREAM_CLIENT_CONNECT,
            $this->sslContext($host),
        );

        if ($socket === false) {
            throw new RuntimeException("IMAP STARTTLS TCP connect failed: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, 90);
        $banner = $this->readLineOrNull($socket);

        if ($banner === null) {
            fclose($socket);
            throw new RuntimeException('IMAP STARTTLS greeting was not received.');
        }

        $tag = 'A000';
        $this->write($socket, "{$tag} STARTTLS");
        $response = $this->readUntilTagged($socket, $tag);

        if (! str_contains(strtoupper($response), "{$tag} OK")) {
            fclose($socket);
            throw new RuntimeException('IMAP STARTTLS negotiation failed.');
        }

        // Windows PHP OpenSSL: unrestricted TLS_CLIENT succeeds where TLSv1_2-only often fails.
        $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

        if ($crypto !== true) {
            fclose($socket);
            throw new RuntimeException('IMAP STARTTLS TLS handshake failed.');
        }

        // enable_crypto can return true while the session is already dead — probe with NOOP.
        try {
            $tag = 'A000N';
            $this->write($socket, "{$tag} NOOP");
            $noop = $this->readUntilTagged($socket, $tag);
            if (! str_contains(strtoupper($noop), "{$tag} OK")) {
                throw new RuntimeException('IMAP STARTTLS NOOP failed.');
            }
        } catch (RuntimeException $exception) {
            fclose($socket);
            throw new RuntimeException(
                'IMAP STARTTLS connected but session died (firewall/proxy/TLS). '.$exception->getMessage(),
                0,
                $exception,
            );
        }

        return $socket;
    }

    /**
     * @return resource
     */
    private function sslContext(string $host)
    {
        return stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'SNI_enabled' => true,
            ],
        ]);
    }

    /**
     * @param  resource  $socket
     */
    private function readLineOrNull($socket): ?string
    {
        $line = @fgets($socket);

        if ($line === false) {
            return null;
        }

        return rtrim($line, "\r\n");
    }

    /**
     * @param  resource  $socket
     */
    public function login($socket, string $address, string $password): void
    {
        $tag = 'A001';
        $this->write($socket, sprintf(
            '%s LOGIN %s %s',
            $tag,
            $this->quote($address),
            $this->quote($password),
        ));

        $response = $this->readUntilTagged($socket, $tag);

        if (! str_contains(strtoupper($response), "{$tag} OK")) {
            throw new MailboxAuthFailedException('gmx_auth_failed');
        }
    }

    /**
     * @param  resource  $socket
     * @return list<array{
     *     provider_message_id: string,
     *     from_address: string,
     *     to_address: string,
     *     subject: string,
     *     body_text: string|null,
     *     body_html: string|null,
     *     received_at: string|null
     * }>
     */
    public function fetchInboxSince($socket, ?\DateTimeInterface $since): array
    {
        $tagSelect = 'A002';
        $this->write($socket, "{$tagSelect} SELECT INBOX");
        try {
            $selectResponse = $this->readUntilTagged($socket, $tagSelect);
        } catch (RuntimeException $exception) {
            throw new RuntimeException('IMAP SELECT read failed: '.$exception->getMessage(), 0, $exception);
        }

        if (! str_contains(strtoupper($selectResponse), "{$tagSelect} OK")) {
            throw new RuntimeException('IMAP SELECT INBOX failed.');
        }

        $criteria = $since instanceof \DateTimeInterface
            ? 'SINCE '.$since->format('d-M-Y')
            : 'ALL';

        $tagSearch = 'A003';
        $this->write($socket, "{$tagSearch} UID SEARCH {$criteria}");
        try {
            $searchResponse = $this->readUntilTagged($socket, $tagSearch);
        } catch (RuntimeException $exception) {
            throw new RuntimeException('IMAP SEARCH read failed: '.$exception->getMessage(), 0, $exception);
        }

        if (! preg_match('/\* SEARCH(.*)$/im', $searchResponse, $matches)) {
            return [];
        }

        $uids = array_values(array_filter(array_map(
            static fn (string $part): string => trim($part),
            preg_split('/\s+/', trim($matches[1])) ?: [],
        ), static fn (string $part): bool => $part !== '' && ctype_digit($part)));

        // Newest first — oldest-first often hit huge legacy MIME and timed out mid-read.
        $uids = array_reverse($uids);
        $messages = [];

        foreach ($uids as $index => $uid) {
            // Cap per-sync volume for first-pass reliability on free GMX accounts.
            if ($index >= 25) {
                break;
            }

            $tag = sprintf('A%03d', 100 + $index);

            try {
                // Partial first body part (<8 KiB) avoids multi-MB literals that stall/drop the socket.
                $this->write(
                    $socket,
                    "{$tag} UID FETCH {$uid} (BODY.PEEK[HEADER.FIELDS (FROM TO SUBJECT DATE MESSAGE-ID)] BODY.PEEK[1]<0.8191>)",
                );
                $fetch = $this->readUntilTagged($socket, $tag);
            } catch (RuntimeException $exception) {
                // Socket likely dead or desynced — return what we already parsed instead of failing the sync.
                Log::warning('gmx.imap.fetch.aborted', [
                    'uid' => $uid,
                    'fetched_so_far' => count($messages),
                    'error' => $exception->getMessage(),
                ]);

                break;
            }

            if (! str_contains(strtoupper($fetch), "{$tag} OK")) {
                continue;
            }

            $parsed = $this->parseFetch($fetch, $uid);

            if ($parsed !== null) {
                $messages[] = $parsed;
            }
        }

        return $messages;
    }

    /**
     * @param  resource  $socket
     */
    public function logout($socket): void
    {
        try {
            $this->write($socket, 'A999 LOGOUT');
            $this->readUntilTagged($socket, 'A999');
        } catch (\Throwable) {
            // ignore logout failures
        }

        fclose($socket);
    }

    /**
     * @param  resource  $socket
     */
    private function write($socket, string $command): void
    {
        $written = fwrite($socket, $command."\r\n");

        if ($written === false) {
            throw new RuntimeException('IMAP write failed.');
        }
    }

    /**
     * @param  resource  $socket
     */
    private function readLine($socket): string
    {
        $line = @fgets($socket);

        if ($line === false) {
            $meta = @stream_get_meta_data($socket);
            $timedOut = is_array($meta) && ($meta['timed_out'] ?? false);
            throw new RuntimeException($timedOut ? 'IMAP read timed out.' : 'IMAP read failed.');
        }

        return rtrim($line, "\r\n");
    }

    /**
     * @param  resource  $socket
     */
    private function readBytes($socket, int $length): string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $chunk = fread($socket, $length - strlen($buffer));

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('IMAP literal read failed.');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * Read until the tagged completion line, correctly consuming IMAP literals.
     *
     * @param  resource  $socket
     */
    private function readUntilTagged($socket, string $tag): string
    {
        $buffer = '';

        while (true) {
            $line = $this->readLine($socket);

            if (preg_match('/\{(\d+)\}$/', $line, $matches)) {
                $length = (int) $matches[1];
                $literal = $this->readBytes($socket, $length);
                $buffer .= $line."\n".$literal;

                // IMAP literals are followed by CRLF before the next line.
                continue;
            }

            $buffer .= $line."\n";

            if (str_starts_with($line, $tag.' ')) {
                return $buffer;
            }
        }
    }

    private function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /**
     * @return array{
     *     provider_message_id: string,
     *     from_address: string,
     *     to_address: string,
     *     subject: string,
     *     body_text: string|null,
     *     body_html: string|null,
     *     received_at: string|null
     * }|null
     */
    private function parseFetch(string $fetch, string $fallbackId): ?array
    {
        $from = $this->headerValue($fetch, 'From') ?? '';
        $to = $this->headerValue($fetch, 'To') ?? '';
        $subject = $this->decodeMimeHeader($this->headerValue($fetch, 'Subject') ?? '');
        $date = $this->headerValue($fetch, 'Date');
        $messageId = $this->headerValue($fetch, 'Message-ID') ?? $this->headerValue($fetch, 'Message-Id');

        $body = $this->extractBodyText($fetch);

        $receivedAt = null;
        if (is_string($date) && $date !== '') {
            try {
                $receivedAt = (new \DateTimeImmutable($date))->format(\DateTimeInterface::ATOM);
            } catch (\Throwable) {
                $receivedAt = null;
            }
        }

        if ($receivedAt === null) {
            $receivedAt = (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);
        }

        $providerId = is_string($messageId) && trim($messageId) !== ''
            ? trim($messageId)
            : 'imap-uid-'.$fallbackId;

        $fromAddress = $this->extractEmail($from) ?: $from;
        $toAddress = $this->extractEmail($to) ?: $to;

        if ($fromAddress === '' && $toAddress === '' && $subject === '' && ($body === null || $body === '')) {
            return null;
        }

        return [
            'provider_message_id' => $providerId,
            'from_address' => $fromAddress !== '' ? $fromAddress : 'unknown@gmx.local',
            'to_address' => $toAddress,
            'subject' => $subject,
            'body_text' => $body,
            'body_html' => null,
            'received_at' => $receivedAt,
        ];
    }

    private function extractBodyText(string $fetch): ?string
    {
        // Partial fetches reply as BODY[1]<0> {n}; full fetches as BODY[1] {n}.
        foreach (['BODY\[1\](?:<\d+>)?', 'BODY\[TEXT\](?:<\d+>)?'] as $section) {
            if (preg_match('/'.$section.'\s*\{(\d+)\}\n/s', $fetch, $matches, PREG_OFFSET_CAPTURE)) {
                $length = (int) $matches[1][0];
                $start = $matches[0][1] + strlen($matches[0][0]);
                $body = substr($fetch, $start, $length);

                return $body === false || $body === '' ? null : $this->normalizeBody($body);
            }

            if (preg_match('/'.$section.'\s+"((?:\\\\.|[^"\\\\])*)"/s', $fetch, $matches)) {
                return $this->normalizeBody(stripcslashes($matches[1]));
            }

            if (preg_match('/'.$section.'\s+NIL/i', $fetch)) {
                return null;
            }
        }

        return null;
    }

    private function normalizeBody(string $body): string
    {
        $body = str_replace("\0", '', $body);
        $body = $this->extractPlainTextFromMime($body);
        $decoded = quoted_printable_decode($body);
        $decoded = str_replace("\r\n", "\n", $decoded);

        if (! mb_check_encoding($decoded, 'UTF-8')) {
            $converted = @mb_convert_encoding($decoded, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            $decoded = is_string($converted) ? $converted : preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $decoded) ?? '';
        }

        // Strip leftover base64/PDF blobs that slipped through multipart peel.
        if (preg_match('/^%PDF-/m', $decoded) || preg_match('/^JVBERi0/m', $decoded)) {
            $decoded = '[attachment omitted]';
        }

        if (strlen($decoded) > 60_000) {
            $decoded = substr($decoded, 0, 60_000)."\n…[truncated]";
        }

        return trim($decoded);
    }

    private function extractPlainTextFromMime(string $raw): string
    {
        if (! str_contains($raw, 'Content-Type:') && ! str_contains($raw, '------=')) {
            return $raw;
        }

        if (preg_match(
            '/Content-Type:\s*text\/plain[^\n]*\n(?:[^\n]+:[^\n]*\n)*\s*\n(.*?)(?=\n------=|\n--|_Part_|\z)/is',
            $raw,
            $matches,
        )) {
            return trim($matches[1]);
        }

        // Multipart with no clearly marked text/plain — keep a short head only.
        if (str_starts_with(ltrim($raw), '------=') || str_contains($raw, 'Content-Transfer-Encoding: base64')) {
            return '[multipart body omitted]';
        }

        return $raw;
    }

    private function headerValue(string $raw, string $name): ?string
    {
        $pattern = '/^'.preg_quote($name, '/').':\s*(.+)$/mi';

        if (! preg_match($pattern, $raw, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }

    private function decodeMimeHeader(string $value): string
    {
        if ($value === '' || ! function_exists('iconv_mime_decode')) {
            return $value;
        }

        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return is_string($decoded) && $decoded !== '' ? $decoded : $value;
    }

    private function extractEmail(string $value): string
    {
        if (preg_match('/<([^>]+)>/', $value, $matches)) {
            return strtolower(trim($matches[1]));
        }

        return strtolower(trim($value));
    }
}
