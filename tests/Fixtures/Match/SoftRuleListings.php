<?php

namespace Tests\Fixtures\Match;

use App\Models\Listing;
use InvalidArgumentException;

final class SoftRuleListings
{
    /**
     * @return array<string, array{city: ?string, language_requirement: ?string}>
     */
    private static function definitions(): array
    {
        return [
            'languageB1German' => [
                'city' => 'Berlin',
                'language_requirement' => 'B1 German',
            ],
            'languageDeC1' => [
                'city' => 'Berlin',
                'language_requirement' => 'de C1',
            ],
            'languageEnglish' => [
                'city' => 'Berlin',
                'language_requirement' => 'English',
            ],
            'noLanguageReq' => [
                'city' => 'Berlin',
                'language_requirement' => null,
            ],
            'cityBerlinPlz' => [
                'city' => '12435 Berlin',
                'language_requirement' => null,
            ],
            'cityMunich' => [
                'city' => 'Munich',
                'language_requirement' => null,
            ],
            'noCity' => [
                'city' => null,
                'language_requirement' => null,
            ],
        ];
    }

    public static function make(string $key): Listing
    {
        $definitions = self::definitions();

        if (! array_key_exists($key, $definitions)) {
            throw new InvalidArgumentException("Unknown soft-rule listing fixture [{$key}].");
        }

        $attrs = $definitions[$key];

        return Listing::make([
            'source' => 'fixture',
            'external_id' => 'soft-rule-'.$key,
            'fingerprint' => hash('sha256', 'fixture|soft-rule-'.$key),
            'kind' => 'job',
            'title' => 'Soft rule fixture '.$key,
            'company' => 'Fixture GmbH',
            'city' => $attrs['city'],
            'url' => 'https://jobs.joinera.test/fixture/soft/'.$key,
            'category' => 'IT',
            'country' => 'DE',
            'education_requirement' => null,
            'language_requirement' => $attrs['language_requirement'],
        ]);
    }
}
