<?php

namespace Database\Seeders;

use App\Models\Listing;
use Illuminate\Database\Seeder;

class ListingSeeder extends Seeder
{
    public function run(): void
    {
        Listing::factory()->job()->forSource('stepstone', 'ext-demo-001')->create([
            'title' => 'Registered Nurse',
            'company' => 'Charité Demo Klinik',
            'city' => 'Berlin',
            'category' => 'Nursing',
        ]);

        Listing::factory()->job()->forSource('stepstone', 'ext-demo-002')->create([
            'title' => 'Backend Engineer',
            'company' => 'Joinera Labs',
            'city' => 'Berlin',
            'category' => 'IT',
        ]);

        Listing::factory()->ausbildung()->forSource('indeed', 'ext-demo-003')->create([
            'company' => 'Hotel Demo GmbH',
            'city' => 'Hamburg',
            'category' => 'Hospitality',
        ]);

        Listing::factory()->job()->forSource('indeed', 'ext-demo-004')->create([
            'title' => 'Warehouse Associate',
            'company' => 'LogiDemo AG',
            'city' => 'Cologne',
            'category' => 'Logistics',
        ]);

        Listing::factory()->ausbildung()->forSource('xing', 'ext-demo-005')->create([
            'company' => 'Tech Ausbildung Partner',
            'city' => 'Munich',
            'category' => 'IT',
        ]);

        Listing::factory()->job()->forSource('xing', 'ext-demo-006')->create([
            'title' => 'Customer Success Manager',
            'company' => 'SaaS Demo GmbH',
            'city' => 'Berlin',
            'category' => 'IT',
        ]);
    }
}
