<?php

namespace App\Services;

use App\Contracts\MailboxProvider;
use App\Enums\MailboxState;
use App\Exceptions\MailboxTakenException;
use App\Models\MailboxIdentity;
use App\Models\User;
use Illuminate\Database\QueryException;

class LocalMailboxProvider implements MailboxProvider
{
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

        return $this->markReady($mailbox);
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
