<?php

namespace App\Services;

use App\Contracts\MailboxInboundSync;
use App\Enums\MailboxState;
use App\Exceptions\MailboxAddressRejectedException;
use App\Exceptions\MailboxAuthFailedException;
use App\Exceptions\MailboxProviderUnsetException;
use App\Exceptions\MailboxTakenException;
use App\Jobs\SyncGmxMailboxJob;
use App\Models\MailboxIdentity;
use App\Models\User;
use App\Support\GmxAddressValidator;
use Illuminate\Database\QueryException;

class AttachGmxMailbox
{
    public function __construct(
        private readonly MailboxInboundSync $inboundSync,
        private readonly GmxAddressValidator $validator,
    ) {
    }

    /**
     * @throws MailboxProviderUnsetException
     * @throws MailboxAddressRejectedException
     * @throws MailboxTakenException
     * @throws MailboxAuthFailedException
     */
    public function handle(User $user, string $address, string $password): MailboxIdentity
    {
        $this->assertConfigured();

        $normalized = $this->validator->normalize($address);

        if (! $this->validator->isAllowed($normalized)) {
            throw new MailboxAddressRejectedException('invalid_gmx_address');
        }

        if (trim($password) === '') {
            throw new MailboxAddressRejectedException('password_required');
        }

        $taken = MailboxIdentity::query()
            ->where('address', $normalized)
            ->where('user_id', '!=', $user->id)
            ->exists();

        if ($taken) {
            throw new MailboxTakenException;
        }

        $domain = $this->validator->host($normalized);

        try {
            $mailbox = MailboxIdentity::query()->firstOrNew(['user_id' => $user->id]);
            $mailbox->fill([
                'domain' => $domain,
                'address' => $normalized,
                'password' => $password,
                'provider' => 'gmx',
                'state' => MailboxState::Pending,
                'last_sync_error' => null,
            ]);
            $mailbox->save();
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw new MailboxTakenException;
            }

            throw $exception;
        }

        try {
            $this->inboundSync->verify($normalized, $password);
        } catch (MailboxAuthFailedException $exception) {
            $mailbox->forceFill([
                'state' => MailboxState::Failed,
                'last_sync_error' => 'gmx_auth_failed',
            ])->save();

            throw $exception;
        }

        $mailbox->forceFill([
            'state' => MailboxState::Ready,
            'last_sync_error' => null,
        ])->save();

        $ready = $mailbox->fresh() ?? $mailbox;
        SyncGmxMailboxJob::dispatch($ready->id);

        return $ready;
    }

    private function assertConfigured(): void
    {
        $mode = config('joinera.mailbox.mode');

        if (! is_string($mode) || $mode === '') {
            throw new MailboxProviderUnsetException;
        }
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
