<?php

namespace App\Support;

class LanguageRequirement
{
    /**
     * @var array<string, string>
     */
    private const LANGUAGE_ALIASES = [
        'german' => 'de',
        'deutsch' => 'de',
        'english' => 'en',
        'englisch' => 'en',
        'de' => 'de',
        'en' => 'en',
    ];

    /**
     * @return array{language: ?string, level: ?string}
     */
    public function parse(?string $requirement): array
    {
        $normalized = $this->normalize($requirement);

        if ($normalized === null) {
            return ['language' => null, 'level' => null];
        }

        $level = null;
        if (preg_match('/\b([abc][12])\b/i', $normalized, $matches) === 1) {
            $level = strtoupper($matches[1]);
        }

        $language = null;
        foreach (self::LANGUAGE_ALIASES as $alias => $code) {
            if (preg_match('/\b'.preg_quote($alias, '/').'\b/u', $normalized) === 1) {
                $language = $code;
                break;
            }
        }

        return [
            'language' => $language,
            'level' => $level,
        ];
    }

    /**
     * @return bool|null true when covered, false when mismatch, null when inconclusive
     */
    public function profileCoversLanguage(?string $profileLanguage, ?string $listingLanguage): ?bool
    {
        $listingCode = $this->normalizeCode($listingLanguage);

        if ($listingCode === null) {
            return null;
        }

        $profileCodes = $this->profileLanguageCodes($profileLanguage);

        if ($profileCodes === []) {
            return false;
        }

        return in_array($listingCode, $profileCodes, true);
    }

    /**
     * @return list<string>
     */
    public function profileLanguageCodes(?string $profileLanguage): array
    {
        $normalized = $this->normalize($profileLanguage);

        if ($normalized === null) {
            return [];
        }

        $parts = preg_split('/[\s+,\/|]+/u', $normalized) ?: [];
        $codes = [];

        foreach ($parts as $part) {
            $code = $this->normalizeCode($part);
            if ($code !== null) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    private function normalizeCode(?string $value): ?string
    {
        $normalized = $this->normalize($value);

        if ($normalized === null) {
            return null;
        }

        return self::LANGUAGE_ALIASES[$normalized] ?? (
            preg_match('/^[a-z]{2}$/', $normalized) === 1 ? $normalized : null
        );
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return mb_strtolower($trimmed);
    }
}
