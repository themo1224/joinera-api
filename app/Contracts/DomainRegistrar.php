<?php

namespace App\Contracts;

interface DomainRegistrar
{
    /**
     * @return list<string>
     */
    public function suggestCandidates(string $firstName, string $lastName): array;

    public function isAvailable(string $domain): bool;

    public function purchaseAndConfigureDns(string $domain): void;
}
