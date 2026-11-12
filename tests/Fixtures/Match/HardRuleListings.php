<?php

namespace Tests\Fixtures\Match;

use App\Models\Listing;
use InvalidArgumentException;

final class HardRuleListings
{
    /**
     * @return array<string, array{category: ?string, country: ?string, education_requirement: ?string}>
     */
    private static function definitions(): array
    {
        return [
            'passAll' => [
                'category' => 'IT',
                'country' => 'DE',
                'education_requirement' => null,
            ],
            'industryMismatch' => [
                'category' => 'Nursing',
                'country' => 'DE',
                'education_requirement' => null,
            ],
            'categoryUnknown' => [
                'category' => null,
                'country' => 'DE',
                'education_requirement' => null,
            ],
            'notGermany' => [
                'category' => 'IT',
                'country' => 'AT',
                'education_requirement' => null,
            ],
            'requiresAbitur' => [
                'category' => 'IT',
                'country' => 'DE',
                'education_requirement' => 'Abitur',
            ],
            'requiresHauptschule' => [
                'category' => 'IT',
                'country' => 'DE',
                'education_requirement' => 'Hauptschulabschluss',
            ],
            'noEducationReq' => [
                'category' => 'IT',
                'country' => 'DE',
                'education_requirement' => null,
            ],
        ];
    }

    public static function make(string $key): Listing
    {
        $definitions = self::definitions();

        if (! array_key_exists($key, $definitions)) {
            throw new InvalidArgumentException("Unknown hard-rule listing fixture [{$key}].");
        }

        $attrs = $definitions[$key];

        return Listing::make([
            'source' => 'fixture',
            'external_id' => 'hard-rule-'.$key,
            'fingerprint' => hash('sha256', 'fixture|hard-rule-'.$key),
            'kind' => 'job',
            'title' => 'Hard rule fixture '.$key,
            'company' => 'Fixture GmbH',
            'city' => 'Berlin',
            'url' => 'https://jobs.joinera.test/fixture/'.$key,
            'category' => $attrs['category'],
            'country' => $attrs['country'],
            'education_requirement' => $attrs['education_requirement'],
            'language_requirement' => null,
        ]);
    }
}
