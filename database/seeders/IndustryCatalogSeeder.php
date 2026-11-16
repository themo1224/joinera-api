<?php

namespace Database\Seeders;

use App\Models\Industry;
use App\Models\SubIndustry;
use Illuminate\Database\Seeder;

/**
 * Seeds Ausbildungsweg (industry) + Schwerpunkt (sub-industry) from the
 * historical admin hardcoded catalog. Matching synonyms stay in IndustryTaxonomy.
 */
class IndustryCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            [
                'slug' => 'pflegefachkraft',
                'label_en' => 'Nursing specialist',
                'label_de' => 'Pflegefachkraft',
                'children' => [
                    ['slug' => 'krankenhaus', 'label_en' => 'Hospital', 'label_de' => 'Krankenhaus'],
                    ['slug' => 'altenpflege', 'label_en' => 'Elderly care', 'label_de' => 'Altenpflege'],
                    ['slug' => 'ambulante_pflege', 'label_en' => 'Outpatient care', 'label_de' => 'Ambulante Pflege'],
                ],
            ],
            [
                'slug' => 'pflegeassistent',
                'label_en' => 'Nursing assistant',
                'label_de' => 'Pflegeassistenz',
                'children' => [
                    ['slug' => 'assistenz_krankenhaus', 'label_en' => 'Hospital', 'label_de' => 'Krankenhaus'],
                    ['slug' => 'assistenz_altenpflege', 'label_en' => 'Elderly care', 'label_de' => 'Altenpflege'],
                    ['slug' => 'assistenz_ambulant', 'label_en' => 'Outpatient care', 'label_de' => 'Ambulante Pflege'],
                ],
            ],
            [
                'slug' => 'pflegehelfer',
                'label_en' => 'Care aide',
                'label_de' => 'Pflegehilfe',
                'children' => [
                    ['slug' => 'hilfe_krankenhaus', 'label_en' => 'Hospital', 'label_de' => 'Krankenhaus'],
                    ['slug' => 'hilfe_altenpflege', 'label_en' => 'Elderly care', 'label_de' => 'Altenpflege'],
                    ['slug' => 'hilfe_ambulant', 'label_en' => 'Outpatient care', 'label_de' => 'Ambulante Pflege'],
                ],
            ],
            [
                'slug' => 'mfa',
                'label_en' => 'MFA',
                'label_de' => 'MFA',
                'children' => [
                    ['slug' => 'hausarztpraxis', 'label_en' => 'GP practice', 'label_de' => 'Hausarztpraxis'],
                    ['slug' => 'facharztpraxis', 'label_en' => 'Specialist practice', 'label_de' => 'Facharztpraxis'],
                    ['slug' => 'klinik_mvz', 'label_en' => 'Clinic / MVZ', 'label_de' => 'Klinik / MVZ'],
                ],
            ],
            [
                'slug' => 'zfa',
                'label_en' => 'ZFA',
                'label_de' => 'ZFA',
                'children' => [
                    ['slug' => 'zahnarztpraxis', 'label_en' => 'Dental practice', 'label_de' => 'Zahnarztpraxis'],
                    ['slug' => 'kieferorthopaedie', 'label_en' => 'Orthodontics', 'label_de' => 'Kieferorthopädie'],
                    ['slug' => 'oralchirurgie', 'label_en' => 'Oral surgery', 'label_de' => 'Oralchirurgie'],
                ],
            ],
            [
                'slug' => 'gastronomie',
                'label_en' => 'Hospitality',
                'label_de' => 'Gastronomie',
                'children' => [
                    ['slug' => 'restaurant', 'label_en' => 'Restaurant', 'label_de' => 'Restaurant'],
                    ['slug' => 'systemgastronomie', 'label_en' => 'System catering', 'label_de' => 'Systemgastronomie'],
                    ['slug' => 'hotel_service', 'label_en' => 'Hotel / Service', 'label_de' => 'Hotel / Service'],
                ],
            ],
            [
                'slug' => 'koch',
                'label_en' => 'Cook',
                'label_de' => 'Koch / Köchin',
                'children' => [
                    ['slug' => 'restaurantkueche', 'label_en' => 'Restaurant kitchen', 'label_de' => 'Restaurantküche'],
                    ['slug' => 'hotel_kantinenkueche', 'label_en' => 'Hotel / Canteen', 'label_de' => 'Hotel / Kantine'],
                    ['slug' => 'systemkueche', 'label_en' => 'System catering', 'label_de' => 'Systemgastronomie'],
                ],
            ],
            [
                'slug' => 'elektriker',
                'label_en' => 'Electronics / Electrical',
                'label_de' => 'Elektronik / Elektro',
                'children' => [
                    [
                        'slug' => 'gebaeudetechnik',
                        'label_en' => 'Building / energy technology',
                        'label_de' => 'Energie- und Gebäudetechnik',
                    ],
                    [
                        'slug' => 'automatisierungstechnik',
                        'label_en' => 'Automation / systems',
                        'label_de' => 'Automatisierungs- und Systemtechnik',
                    ],
                    [
                        'slug' => 'informationstechnik',
                        'label_en' => 'IT / telecommunications',
                        'label_de' => 'Informations- und Telekommunikationstechnik',
                    ],
                ],
            ],
        ];

        foreach ($catalog as $parentIndex => $parent) {
            $industry = Industry::query()->updateOrCreate(
                ['slug' => $parent['slug']],
                [
                    'label_en' => $parent['label_en'],
                    'label_de' => $parent['label_de'],
                    'sort_order' => $parentIndex,
                    'archived_at' => null,
                ],
            );

            foreach ($parent['children'] as $childIndex => $child) {
                SubIndustry::query()->updateOrCreate(
                    ['slug' => $child['slug']],
                    [
                        'industry_id' => $industry->id,
                        'label_en' => $child['label_en'],
                        'label_de' => $child['label_de'],
                        'sort_order' => $childIndex,
                        'archived_at' => null,
                    ],
                );
            }
        }
    }
}
