<?php

namespace App\Services;

use App\Contracts\DomainRegistrar;
use App\Models\MailboxIdentity;
use App\Support\ApplyAddressNormalizer;

class FakeDomainRegistrar implements DomainRegistrar
{
    /** @var array<string, true> */
    private array $forcedTaken = [];

    private bool $exhaustAll = false;

    public function __construct(private readonly ApplyAddressNormalizer $normalizer)
    {
    }

    public function forceTaken(string $domain): void
    {
        $this->forcedTaken[strtolower($domain)] = true;
    }

    public function exhaustAll(bool $enabled = true): void
    {
        $this->exhaustAll = $enabled;
    }

    /**
     * @return list<string>
     */
    public function suggestCandidates(string $firstName, string $lastName): array
    {
        return $this->normalizer->domainCandidates($firstName, $lastName);
    }

    public function isAvailable(string $domain): bool
    {
        $domain = strtolower($domain);

        if ($this->exhaustAll || isset($this->forcedTaken[$domain])) {
            return false;
        }

        return ! MailboxIdentity::query()->where('domain', $domain)->exists();
    }

    public function purchaseAndConfigureDns(string $domain): void
    {
        // Fake: DNS and purchase succeed immediately.
    }
}
