<?php

namespace App\Support;

use App\Exceptions\NameRequiredException;

class ApplyAddressNormalizer
{
    private const MAX_DOMAIN_CANDIDATES = 5;

    /**
     * One or more name tokens. A single token is treated as last name only.
     *
     * @return array{0: string|null, 1: string} first (nullable), last
     */
    public function splitName(string $fullName): array
    {
        $trimmed = trim(preg_replace('/\s+/u', ' ', $fullName) ?? '');

        if ($trimmed === '') {
            throw new NameRequiredException;
        }

        $parts = explode(' ', $trimmed, 2);

        if (count($parts) === 1 || trim($parts[1] ?? '') === '') {
            return [null, $parts[0]];
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * Default apply local-part from display name: `last` or `first.last`.
     */
    public function localPartFromName(string $fullName): string
    {
        [$firstName, $lastName] = $this->splitName($fullName);
        $last = $this->normalizeToken($lastName);

        if ($last === '') {
            throw new NameRequiredException;
        }

        if ($firstName === null || trim($firstName) === '') {
            return $last;
        }

        $first = $this->normalizeToken($firstName);

        if ($first === '') {
            return $last;
        }

        return $first.'.'.$last;
    }

    /** @deprecated Use localPartFromName — kept for older call sites. */
    public function localPart(string $firstName, string $lastName): string
    {
        return $this->localPartFromName(trim($firstName.' '.$lastName));
    }

    public function normalizeLocalPart(string $localPart): string
    {
        $value = strtolower(trim($localPart));
        $value = preg_replace('/\s+/', '', $value) ?? '';

        return $value;
    }

    public function isValidLocalPart(string $localPart): bool
    {
        $value = $this->normalizeLocalPart($localPart);

        if ($value === '' || strlen($value) > 64) {
            return false;
        }

        // RFC-ish mailbox local-part for our product: dots allowed, no @.
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/', $value)
            && ! str_contains($value, '..');
    }

    /**
     * Preferred `{last}.de`, then `{first}{last}.de` when first exists, then `{last}2.de`…
     *
     * @return list<string>
     */
    public function domainCandidates(string $firstName, string $lastName): array
    {
        $last = $this->domainLabel($lastName);

        if ($last === '') {
            throw new NameRequiredException;
        }

        $candidates = [$last.'.de'];

        $first = $this->domainLabel($firstName);
        if ($first !== '') {
            $candidates[] = $first.$last.'.de';
        }

        for ($suffix = 2; $suffix <= self::MAX_DOMAIN_CANDIDATES; $suffix++) {
            $candidates[] = $last.$suffix.'.de';
        }

        return array_values(array_unique($candidates));
    }

    /** Default suggestion for Admin UI / preferred domain (editable). */
    public function suggestDomain(?string $firstName, string $lastName): string
    {
        return $this->domainCandidates((string) $firstName, $lastName)[0];
    }

    public function addressFor(string $localPart, string $domain): string
    {
        return $localPart.'@'.$domain;
    }

    public function normalizePreferredDomain(string $domain): string
    {
        return strtolower(trim($domain));
    }

    public function isValidPreferredDomain(string $domain): bool
    {
        $domain = $this->normalizePreferredDomain($domain);

        if ($domain === '' || strlen($domain) > 253) {
            return false;
        }

        if ($domain === 'joinera.de'
            || $domain === 'apply.joinera.de'
            || str_ends_with($domain, '.joinera.de')) {
            return false;
        }

        return (bool) preg_match(
            '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/',
            $domain,
        );
    }

    private function domainLabel(string $value): string
    {
        return str_replace(['-', '.'], '', $this->normalizeToken($value));
    }

    private function normalizeToken(string $value): string
    {
        $map = [
            'ä' => 'ae',
            'ö' => 'oe',
            'ü' => 'ue',
            'Ä' => 'ae',
            'Ö' => 'oe',
            'Ü' => 'ue',
            'ß' => 'ss',
        ];

        $ascii = strtr($value, $map);
        $ascii = mb_strtolower($ascii, 'UTF-8');
        $ascii = preg_replace('/[^a-z0-9.-]+/', '', $ascii) ?? '';
        $ascii = preg_replace('/\.+/', '.', $ascii) ?? '';
        $ascii = trim($ascii, '.-');

        return $ascii;
    }
}
