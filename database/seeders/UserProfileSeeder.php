<?php

namespace Database\Seeders;

use App\Enums\ApprovalStatus;
use App\Enums\ParseStatus;
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
 *
 * Uses direct creates (no factories) so Docker --no-dev images can seed without faker.
 */
class UserProfileSeeder extends Seeder
{
    public function run(): void
    {
        // Plain text — User::$casts hashes via the `hashed` cast.
        $password = 'password';

        $admin = $this->user('Admin Joinera', 'admin@joinera.test', Role::Admin, $password);
        $this->user('Staff One', 'staff@joinera.test', Role::Staff, $password);
        $this->user('Staff Two', 'staff2@joinera.test', Role::Staff, $password);

        $anna = $this->user('Anna Schmidt', 'anna@joinera.test', Role::User, $password);
        $this->profile($anna, [
            'industry' => ['Healthcare'],
            'city' => ['Berlin'],
            'province' => ['BE'],
            'language' => 'de',
            'parse_status' => ParseStatus::Parsed,
            'approval' => ApprovalStatus::Approved,
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);
        $this->cv($anna, 'anna-schmidt-cv.pdf', 'cvs/demo/anna-schmidt-cv.pdf');

        $ben = $this->user('Ben Weber', 'ben@joinera.test', Role::User, $password);
        $this->profile($ben, [
            'industry' => ['IT'],
            'city' => ['Hamburg'],
            'province' => ['HH'],
            'language' => 'de+en',
            'parse_status' => ParseStatus::Parsed,
            'approval' => ApprovalStatus::Pending,
        ]);

        $cara = $this->user('Cara Müller', 'cara@joinera.test', Role::User, $password);
        $this->profile($cara, [
            'industry' => [],
            'city' => [],
            'province' => [],
            'language' => null,
            'parse_status' => ParseStatus::Unparsed,
            'approval' => ApprovalStatus::Pending,
        ]);

        $diego = $this->user('Diego Rossi', 'diego@joinera.test', Role::User, $password);
        $this->profile($diego, [
            'industry' => ['Hospitality'],
            'city' => ['Munich'],
            'province' => ['BY'],
            'language' => 'de',
            'parse_status' => ParseStatus::ParseFailed,
            'approval' => ApprovalStatus::Pending,
        ]);

        $elena = $this->user('Elena Novak', 'elena@joinera.test', Role::User, $password);
        $this->profile($elena, [
            'industry' => ['Logistics'],
            'city' => ['Cologne'],
            'province' => ['NW'],
            'language' => 'en',
            'parse_status' => ParseStatus::Parsed,
            'approval' => ApprovalStatus::Pending,
        ]);

        $farid = $this->user('Farid Hassan', 'farid@joinera.test', Role::User, $password);
        $this->profile($farid, [
            'industry' => ['IT'],
            'city' => ['Berlin'],
            'province' => ['BE'],
            'language' => 'de+en',
            'parse_status' => ParseStatus::Parsed,
            'approval' => ApprovalStatus::Approved,
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);
        $this->cv($farid, 'farid-hassan-cv.pdf', 'cvs/demo/farid-hassan-cv.pdf');
    }

    private function user(string $name, string $email, Role $role, string $password): User
    {
        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
                'role' => $role,
                'email_verified_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function profile(User $user, array $attributes): Profile
    {
        return Profile::query()->updateOrCreate(
            ['user_id' => $user->id],
            array_merge([
                'priorities' => Profile::DEFAULT_PRIORITIES,
                'approved_by' => null,
                'approved_at' => null,
            ], $attributes),
        );
    }

    private function cv(User $user, string $originalName, string $path): CvAsset
    {
        return CvAsset::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'disk' => 'local',
                'path' => $path,
                'original_name' => $originalName,
                'mime' => 'application/pdf',
                'size' => 120_000,
            ],
        );
    }
}
