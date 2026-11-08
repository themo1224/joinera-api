<?php

namespace App\Services;

use App\Contracts\MailboxProvider;
use App\Enums\MailboxState;
use App\Exceptions\MailboxTakenException;
use App\Models\MailboxIdentity;
use App\Models\User;
use Illuminate\Database\QueryException;

class FakeMailboxProvider implements MailboxProvider
{
    private ?string $forcedAddress = null;

    private ?string $forcedDomain = null;

    private bool $autoReady = false;

    public function forceNextAddress(string $address): void
    {
        $this->forcedAddress = $address;
    }

    public function forceNextDomain(string $domain): void
    {
        $this->forcedDomain = $domain;
    }

    public function autoReady(bool $enabled = true): void
    {
        $this->autoReady = $enabled;
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function resolveAllocation(string $address, string $domain): array
    {
        if ($this->forcedAddress !== null) {
            $address = $this->forcedAddress;
            $this->forcedAddress = null;
        }

        if ($this->forcedDomain !== null) {
            $domain = $this->forcedDomain;
            $this->forcedDomain = null;
        }

        return [$address, $domain];
    }

    public function start(User $user, string $address, string $domain): MailboxIdentity
    {
        try {
            $mailbox = MailboxIdentity::query()->create([
                'user_id' => $user->id,
                'domain' => $domain,
                'address' => $address,
                'state' => MailboxState::Pending,
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw new MailboxTakenException;
            }

            throw $exception;
        }

        if ($this->autoReady) {
            return $this->markReady($mailbox);
        }

        return $mailbox;
    }

    public function markReady(MailboxIdentity $mailbox): MailboxIdentity
    {
        $mailbox->state = MailboxState::Ready;
        $mailbox->save();

        return $mailbox;
    }

    public function markFailed(MailboxIdentity $mailbox): MailboxIdentity
    {
        $mailbox->state = MailboxState::Failed;
        $mailbox->save();

        return $mailbox;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return $sqlState === '23000'
            || $driverCode === '19'
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
