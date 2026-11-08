<?php

namespace Database\Seeders;

use App\Models\AdminAlert;
use App\Models\IntakeRun;
use App\Models\IntakeRunSite;
use Illuminate\Database\Seeder;

class IntakeSeeder extends Seeder
{
    public function run(): void
    {
        $finishedRun = IntakeRun::factory()->finished()->create([
            'started_at' => now()->subHours(5),
            'finished_at' => now()->subHours(4),
        ]);

        IntakeRunSite::factory()->for($finishedRun)->ok()->create([
            'site' => 'stepstone',
            'listings_created' => 12,
        ]);

        IntakeRunSite::factory()->for($finishedRun)->ok()->create([
            'site' => 'indeed',
            'listings_created' => 7,
        ]);

        IntakeRunSite::factory()->for($finishedRun)->failed()->create([
            'site' => 'xing',
            'listings_created' => 0,
            'error' => 'Demo intake failure: xing adapter timed out.',
        ]);

        $openRun = IntakeRun::factory()->create([
            'started_at' => now()->subMinutes(20),
            'finished_at' => null,
        ]);

        IntakeRunSite::factory()->for($openRun)->ok()->create([
            'site' => 'stepstone',
            'listings_created' => 3,
        ]);

        IntakeRunSite::factory()->for($openRun)->failed()->create([
            'site' => 'xing',
            'listings_created' => 0,
            'error' => 'Demo intake failure: repeated site error.',
        ]);

        AdminAlert::factory()->forIntakeRun($finishedRun->id, 'xing')->create();
    }
}
