<?php

namespace App\Support;

/**
 * Ausbildung industry synonym + specialization families for hard-match.
 * Keep in sync with docs/matching/Suchbegriffe_Ausbildungsgesuche.md
 * and DB industry/sub_industry slugs (IndustryCatalogSeeder + staff CRUD).
 */
final class IndustryTaxonomy
{
    /**
     * match_key => lowercase labels that belong to the family:
     * - match_key itself
     * - Beruf/search synonyms (Suchbegriffe)
     * - specialization slugs stored on profile.industry
     *
     * @var array<string, list<string>>
     */
    private const FAMILIES = [
        'pflegefachkraft' => [
            'pflegefachkraft',
            'pflegefachmann',
            'pflegefachfrau',
            'gesundheits- und krankenpfleger',
            'gesundheits- und krankenpflegerin',
            'krankenpfleger',
            'krankenschwester',
            'altenpfleger',
            'altenpflegerin',
            'krankenhaus',
            'altenpflege',
            'ambulante_pflege',
        ],
        'pflegeassistent' => [
            'pflegeassistent',
            'pflegeassistentin',
            'pflegefachassistent',
            'pflegefachassistentin',
            'krankenpflegehelfer',
            'krankenpflegehelferin',
            'altenpflegehelfer',
            'altenpflegehelferin',
            'assistenz_krankenhaus',
            'assistenz_altenpflege',
            'assistenz_ambulant',
        ],
        'pflegehelfer' => [
            'pflegehelfer',
            'pflegehelferin',
            'pflegehilfskraft',
            'pflegehilfe',
            'hilfe_krankenhaus',
            'hilfe_altenpflege',
            'hilfe_ambulant',
        ],
        'mfa' => [
            'mfa',
            'medizinischer fachangestellter',
            'medizinische fachangestellte',
            'arzthelfer',
            'arzthelferin',
            'hausarztpraxis',
            'facharztpraxis',
            'klinik_mvz',
        ],
        'zfa' => [
            'zfa',
            'zahnmedizinischer fachangestellter',
            'zahnmedizinische fachangestellte',
            'zahnarzthelfer',
            'zahnarzthelferin',
            'zahnarztassistenz',
            'zahnarztpraxis',
            'kieferorthopaedie',
            'oralchirurgie',
        ],
        'gastronomie' => [
            'gastronomie',
            'gastronomiefachkraft',
            'fachkraft für gastronomie',
            'restaurantfachmann',
            'restaurantfachfrau',
            'fachmann für systemgastronomie',
            'fachfrau für systemgastronomie',
            'restaurant',
            'systemgastronomie',
            'hotel_service',
        ],
        'koch' => [
            'koch',
            'köchin',
            'restaurantkueche',
            'hotel_kantinenkueche',
            'systemkueche',
        ],
        'elektriker' => [
            'elektriker',
            'elektrikerin',
            'elektroniker',
            'elektronikerin',
            'elektroinstallateur',
            'elektroinstallateurin',
            'elektrofachkraft',
            'gebaeudetechnik',
            'automatisierungstechnik',
            'informationstechnik',
        ],
    ];

    /**
     * Map a label to its family match_key, or return the normalized label when unknown.
     */
    public static function familyKey(?string $normalizedLower): ?string
    {
        if ($normalizedLower === null || $normalizedLower === '') {
            return null;
        }

        foreach (self::FAMILIES as $matchKey => $synonyms) {
            if (in_array($normalizedLower, $synonyms, true)) {
                return $matchKey;
            }
        }

        return $normalizedLower;
    }

    /**
     * Lowercase labels that belong to any of the given family keys (synonyms + key).
     * Unknown family keys are included as themselves so SQL prefilters mirror familyKey().
     *
     * @param  list<string>  $familyKeys
     * @return list<string>
     */
    public static function labelsForFamilyKeys(array $familyKeys): array
    {
        $labels = [];

        foreach ($familyKeys as $familyKey) {
            if (! is_string($familyKey) || $familyKey === '') {
                continue;
            }

            if (isset(self::FAMILIES[$familyKey])) {
                foreach (self::FAMILIES[$familyKey] as $synonym) {
                    $labels[] = $synonym;
                }

                continue;
            }

            $labels[] = $familyKey;
        }

        return array_values(array_unique($labels));
    }
}
