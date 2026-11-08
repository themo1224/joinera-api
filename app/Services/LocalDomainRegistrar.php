<?php

namespace App\Services;

use App\Contracts\DomainRegistrar;
use App\Models\MailboxIdentity;
use App\Support\ApplyAddressNormalizer;

class LocalDomainRegistrar implements DomainRegistrar
{
    public function __construct(private readonly ApplyAddressNormalizer $normalizer)
    {
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
        return ! MailboxIdentity::query()->where('domain', strtolower($domain))->exists();
    }

    public function purchaseAndConfigureDns(string $domain): void
    {
        // Local stand-in: no outbound registrar call.
    }
}
