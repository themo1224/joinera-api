<?php

namespace App\Support;

class GmxAddressValidator
{
    public function normalize(string $address): string
    {
        return strtolower(trim($address));
    }

    public function host(string $address): string
    {
        $normalized = $this->normalize($address);

        if (! str_contains($normalized, '@')) {
            return '';
        }

        return substr($normalized, strrpos($normalized, '@') + 1) ?: '';
    }

    public function isAllowed(string $address): bool
    {
        $normalized = $this->normalize($address);

        if (! filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $host = $this->host($normalized);
        $allowed = config('joinera.mailbox.allowed_hosts', ['gmx.de', 'gmx.net']);

        if (! is_array($allowed)) {
            return false;
        }

        $allowedHosts = array_values(array_filter(
            $allowed,
            fn (mixed $value): bool => is_string($value) && $value !== '',
        ));

        return in_array($host, $allowedHosts, true);
    }
}
