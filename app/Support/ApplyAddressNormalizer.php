<?php

namespace App\Support;

use App\Exceptions\NameRequiredException;

class ApplyAddressNormalizer
{
    private const MAX_DOMAIN_CANDIDATES = 5;

    /**
     * @return array{0: string, 1: string}
     */
    public function splitName(string $fullName): array
    {
        $trimmed = trim(preg_replace('/\s+/u', ' ', $fullName) ?? '');

        if ($trimmed === '') {
            throw new NameRequiredException;
        }

        $parts = explode(' ', $trimmed, 2);

        if (count($parts) < 2 || trim($parts[1]) === '') {
            throw new NameRequiredException;
        }

        return [$parts[0], $parts[1]];
    }

    public function localPart(string $firstName, string $lastName): string
    {
        $first = $this->normalizeToken($firstName);
        $last = $this->normalizeToken($lastName);

        if ($first === '' || $last === '') {
            throw new NameRequiredException;
        }

        return $first.'.'.$last;
    }

    /**
     * Preferred `{first}-{last}.de` plus numbered alternates through `{first}-{last}5.de`.
     *
     * @return list<string>
     */
    public function domainCandidates(string $firstName, string $lastName): array
    {
        $first = $this->normalizeToken($firstName);
        $last = $this->normalizeToken($lastName);

        if ($first === '' || $last === '') {
            throw new NameRequiredException;
        }

        $base = $first.'-'.$last;
        $candidates = [$base.'.de'];

        for ($suffix = 2; $suffix <= self::MAX_DOMAIN_CANDIDATES; $suffix++) {
            $candidates[] = $base.$suffix.'.de';
        }

        return $candidates;
    }

    public function addressFor(string $localPart, string $domain): string
    {
        return $localPart.'@'.$domain;
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
