<?php

namespace App\Services;

use App\Contracts\DomainRegistrar;
use App\Contracts\MailboxProvider;
use App\Enums\MailboxState;
use App\Exceptions\DomainRegistrarUnsetException;
use App\Exceptions\MailboxModeRejectedException;
use App\Exceptions\MailboxProviderUnsetException;
use App\Models\MailboxIdentity;
use App\Models\User;
use App\Support\ApplyAddressNormalizer;
use Illuminate\Http\Request;

class StartMailbox
{
    public function __construct(
        private readonly MailboxProvider $provider,
        private readonly DomainRegistrar $registrar,
        private readonly ApplyAddressNormalizer $normalizer,
    ) {
    }

    public function handle(User $user, Request $request): MailboxIdentity
    {
        $this->rejectForbiddenSelections($request);
        $this->assertConfigured();

        $existing = $user->mailboxIdentity()->first();

        if ($existing !== null) {
            return $existing;
        }

        [$firstName, $lastName] = $this->normalizer->splitName($user->name);
        $localPart = $this->normalizer->localPart($firstName, $lastName);
        $candidates = $this->registrar->suggestCandidates($firstName, $lastName);

        $chosenDomain = null;

        foreach ($candidates as $candidate) {
            if (! $this->registrar->isAvailable($candidate)) {
                continue;
            }

            $this->registrar->purchaseAndConfigureDns($candidate);
            $chosenDomain = $candidate;
            break;
        }

        if ($chosenDomain === null) {
            $failedDomain = $candidates[0];
            $failedAddress = $this->normalizer->addressFor($localPart, $failedDomain);

            return MailboxIdentity::query()->create([
                'user_id' => $user->id,
                'domain' => $failedDomain,
                'address' => $failedAddress,
                'state' => MailboxState::Failed,
            ]);
        }

        $address = $this->normalizer->addressFor($localPart, $chosenDomain);

        if ($this->provider instanceof FakeMailboxProvider) {
            [$address, $chosenDomain] = $this->provider->resolveAllocation($address, $chosenDomain);
        }

        return $this->provider->start($user, $address, $chosenDomain);
    }

    private function assertConfigured(): void
    {
        $mode = config('joinera.mailbox.mode');
        $provider = config('joinera.mailbox.provider');
        $registrar = config('joinera.mailbox.registrar');

        $modeOk = is_string($mode) && $mode !== '';
        $providerOk = is_string($provider) && $provider !== '';
        $registrarOk = is_string($registrar) && $registrar !== '';

        if (! $modeOk || ! $providerOk) {
            throw new MailboxProviderUnsetException;
        }

        if (! $registrarOk) {
            throw new DomainRegistrarUnsetException;
        }
    }

    private function rejectForbiddenSelections(Request $request): void
    {
        $payload = $request->all();

        if ($payload === []) {
            return;
        }

        $mode = strtolower((string) ($payload['mode'] ?? $payload['mailbox_mode'] ?? ''));
        $provider = strtolower((string) ($payload['provider'] ?? $payload['mailbox_provider'] ?? ''));
        $address = strtolower((string) ($payload['address'] ?? $payload['email'] ?? $payload['mailbox'] ?? ''));
        $domain = strtolower((string) ($payload['domain'] ?? ''));

        $gmailSelected = str_contains($mode, 'gmail')
            || str_contains($provider, 'gmail')
            || str_contains($address, 'gmail.com')
            || str_contains($mode, 'user')
            || str_contains($provider, 'user_owned')
            || array_key_exists('user_email', $payload);

        $joineraShared = $this->isJoineraBrandedDomain($domain)
            || $this->isJoineraBrandedDomain($this->domainFromAddress($address))
            || str_contains($address, '@apply.joinera.de')
            || str_contains($address, 'joinera.de');

        if ($gmailSelected || $joineraShared || $address !== '' || $domain !== '') {
            throw new MailboxModeRejectedException;
        }

        if (array_key_exists('mode', $payload)
            || array_key_exists('provider', $payload)
            || array_key_exists('address', $payload)
            || array_key_exists('email', $payload)
            || array_key_exists('mailbox', $payload)
            || array_key_exists('domain', $payload)) {
            throw new MailboxModeRejectedException;
        }
    }

    private function isJoineraBrandedDomain(string $domain): bool
    {
        if ($domain === '') {
            return false;
        }

        return $domain === 'joinera.de'
            || $domain === 'apply.joinera.de'
            || str_ends_with($domain, '.joinera.de');
    }

    private function domainFromAddress(string $address): string
    {
        if (! str_contains($address, '@')) {
            return '';
        }

        return substr($address, strrpos($address, '@') + 1) ?: '';
    }
}
