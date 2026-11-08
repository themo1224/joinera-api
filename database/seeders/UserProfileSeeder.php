<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\CvAsset;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo logins (password for all: "password"):
 *
 * admin@joinera.test  — admin
 * staff@joinera.test  — staff
 * staff2@joinera.test — staff
 * anna@joinera.test   — user, parsed + approved + CV
 * ben@joinera.test    — user, parsed + pending
 * cara@joinera.test   — user, unparsed + incomplete
 * diego@joinera.test  — user, parse_failed
 * elena@joinera.test  — user, parsed + pending
 * farid@joinera.test  — user, parsed + approved + CV
 */
class UserProfileSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Admin Joinera',
            'email' => 'admin@joinera.test',
            'role' => Role::Admin,
        ]);

        User::factory()->staff()->create([
            'name' => 'Staff One',
            'email' => 'staff@joinera.test',
            'role' => Role::Staff,
        ]);

        User::factory()->staff()->create([
            'name' => 'Staff Two',
            'email' => 'staff2@joinera.test',
            'role' => Role::Staff,
        ]);

        $anna = User::factory()->create([
            'name' => 'Anna Schmidt',
            'email' => 'anna@joinera.test',
        ]);
        Profile::factory()->for($anna)->parsed()->approved($admin)->create([
            'industry' => 'Healthcare',
            'city' => 'Berlin',
            'province' => 'BE',
            'language' => 'de',
        ]);
        CvAsset::factory()->for($anna)->create([
            'original_name' => 'anna-schmidt-cv.pdf',
            'path' => 'cvs/demo/anna-schmidt-cv.pdf',
        ]);

        $ben = User::factory()->create([
            'name' => 'Ben Weber',
            'email' => 'ben@joinera.test',
        ]);
        Profile::factory()->for($ben)->parsed()->pending()->create([
            'industry' => 'IT',
            'city' => 'Hamburg',
            'province' => 'HH',
            'language' => 'de+en',
        ]);

        $cara = User::factory()->create([
            'name' => 'Cara Müller',
            'email' => 'cara@joinera.test',
        ]);
        Profile::factory()->for($cara)->unparsed()->incomplete()->pending()->create();

        $diego = User::factory()->create([
            'name' => 'Diego Rossi',
            'email' => 'diego@joinera.test',
        ]);
        Profile::factory()->for($diego)->parseFailed()->pending()->create([
            'industry' => 'Hospitality',
            'city' => 'Munich',
            'province' => 'BY',
            'language' => 'de',
        ]);

        $elena = User::factory()->create([
            'name' => 'Elena Novak',
            'email' => 'elena@joinera.test',
        ]);
        Profile::factory()->for($elena)->parsed()->pending()->create([
            'industry' => 'Logistics',
            'city' => 'Cologne',
            'province' => 'NW',
            'language' => 'en',
        ]);

        $farid = User::factory()->create([
            'name' => 'Farid Hassan',
            'email' => 'farid@joinera.test',
        ]);
        Profile::factory()->for($farid)->parsed()->approved($admin)->create([
            'industry' => 'IT',
            'city' => 'Berlin',
            'province' => 'BE',
            'language' => 'de+en',
        ]);
        CvAsset::factory()->for($farid)->create([
            'original_name' => 'farid-hassan-cv.pdf',
            'path' => 'cvs/demo/farid-hassan-cv.pdf',
        ]);
    }
}
