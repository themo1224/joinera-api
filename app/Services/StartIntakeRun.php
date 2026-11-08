<?php

namespace App\Services;

use App\Jobs\RunSiteIntakeJob;
use App\Models\IntakeRun;
use Illuminate\Support\Carbon;

class StartIntakeRun
{
    /**
     * @return array{run: IntakeRun, sites: list<string>}
     */
    public function handle(): array
    {
        $sites = $this->configuredSites();

        $run = IntakeRun::query()->create([
            'started_at' => Carbon::now(),
            'finished_at' => null,
        ]);

        if ($sites === []) {
            $run->finished_at = Carbon::now();
            $run->save();

            return ['run' => $run, 'sites' => []];
        }

        $queue = (string) config('joinera.queues.intake', 'intake');

        foreach ($sites as $site) {
            RunSiteIntakeJob::dispatch($run->id, $site)->onQueue($queue);
        }

        return ['run' => $run, 'sites' => $sites];
    }

    public function markFinishedIfComplete(IntakeRun $run): void
    {
        $expected = count($this->configuredSites());

        if ($expected === 0) {
            return;
        }

        if ($run->sites()->count() < $expected) {
            return;
        }

        if ($run->finished_at !== null) {
            return;
        }

        $run->finished_at = Carbon::now();
        $run->save();
    }

    /**
     * @return list<string>
     */
    private function configuredSites(): array
    {
        $sites = config('joinera.sites.v1', []);

        if (! is_array($sites)) {
            return [];
        }

        return array_values(array_filter(
            $sites,
            fn (mixed $site): bool => is_string($site) && $site !== '',
        ));
    }
}
