<?php

namespace Database\Seeders;

use App\Models\MailboxIdentity;
use App\Models\SiteIdentity;
use App\Models\User;
use Illuminate\Database\Seeder;

class IdentitySeeder extends Seeder
{
    public function run(): void
    {
        $anna = User::query()->where('email', 'anna@joinera.test')->firstOrFail();
        $ben = User::query()->where('email', 'ben@joinera.test')->firstOrFail();
        $diego = User::query()->where('email', 'diego@joinera.test')->firstOrFail();
        $elena = User::query()->where('email', 'elena@joinera.test')->firstOrFail();
        $farid = User::query()->where('email', 'farid@joinera.test')->firstOrFail();

        MailboxIdentity::factory()->for($anna)->ready()->create([
            'domain' => 'anna-schmidt.joinera.test',
            'address' => 'hello@anna-schmidt.joinera.test',
        ]);

        MailboxIdentity::factory()->for($ben)->pending()->create([
            'domain' => 'ben-weber.joinera.test',
            'address' => 'hello@ben-weber.joinera.test',
        ]);

        MailboxIdentity::factory()->for($diego)->failed()->create([
            'domain' => 'diego-rossi.joinera.test',
            'address' => 'hello@diego-rossi.joinera.test',
        ]);

        SiteIdentity::factory()->for($anna)->healthy()->create([
            'site' => 'stepstone',
            'site_login' => 'anna@joinera.test',
            'secret' => 'demo-site-secret-anna-stepstone',
            'paused' => false,
        ]);

        SiteIdentity::factory()->for($anna)->healthy()->paused()->create([
            'site' => 'indeed',
            'site_login' => 'anna.stepstone-paused@joinera.test',
            'secret' => 'demo-site-secret-anna-indeed',
        ]);

        SiteIdentity::factory()->for($elena)->failed()->create([
            'site' => 'xing',
            'site_login' => 'elena@joinera.test',
            'secret' => 'demo-site-secret-elena-xing',
            'paused' => false,
        ]);

        SiteIdentity::factory()->for($farid)->healthy()->create([
            'site' => 'stepstone',
            'site_login' => 'farid@joinera.test',
            'secret' => 'demo-site-secret-farid-stepstone',
            'paused' => false,
        ]);
    }
}
