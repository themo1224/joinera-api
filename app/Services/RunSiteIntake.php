<?php

namespace App\Services;

use App\Enums\IntakeRunSiteStatus;
use App\Exceptions\IntakeIdentityContextException;
use App\Exceptions\ListingDroppedException;
use App\Jobs\RecordIntakeSiteRepeatedFailureAlert;
use App\Models\IntakeRun;
use App\Models\IntakeRunSite;
use Throwable;

class RunSiteIntake
{
    public function __construct(
        private readonly SiteIntakeAdapterRegistry $registry,
        private readonly UpsertListing $upsertListing,
    ) {
    }

    public function handle(IntakeRun $run, string $site): IntakeRunSite
    {
        $adapter = $this->registry->get($site);

        if ($adapter === null) {
            $result = $this->recordSite($run, $site, IntakeRunSiteStatus::Failed, 'No intake adapter registered for site.', 0);
            $this->maybeAlertRepeatedFailure($run, $site);

            return $result;
        }

        try {
            $search = $adapter->search();
            $items = $adapter->parseList($search);
            $created = 0;

            foreach ($items as $item) {
                $detail = $adapter->parseDetail($item);

                try {
                    $result = $this->upsertListing->handle($site, $detail);
                } catch (ListingDroppedException|IntakeIdentityContextException) {
                    continue;
                }

                if ($result !== null && $result['created']) {
                    $created++;
                }
            }

            return $this->recordSite($run, $site, IntakeRunSiteStatus::Ok, null, $created);
        } catch (Throwable $exception) {
            $result = $this->recordSite(
                $run,
                $site,
                IntakeRunSiteStatus::Failed,
                $exception->getMessage(),
                0,
            );
            $this->maybeAlertRepeatedFailure($run, $site);

            return $result;
        }
    }

    private function recordSite(
        IntakeRun $run,
        string $site,
        IntakeRunSiteStatus $status,
        ?string $error,
        int $listingsCreated,
    ): IntakeRunSite {
        return IntakeRunSite::query()->updateOrCreate(
            [
                'intake_run_id' => $run->id,
                'site' => $site,
            ],
            [
                'status' => $status,
                'error' => $error,
                'listings_created' => $listingsCreated,
            ],
        );
    }

    private function maybeAlertRepeatedFailure(IntakeRun $run, string $site): void
    {
        $previousRun = IntakeRun::query()
            ->whereNotNull('finished_at')
            ->where('id', '<', $run->id)
            ->orderByDesc('id')
            ->first();

        if ($previousRun === null) {
            return;
        }

        $previousFailed = $previousRun->sites()
            ->where('site', $site)
            ->where('status', IntakeRunSiteStatus::Failed)
            ->exists();

        if (! $previousFailed) {
            return;
        }

        RecordIntakeSiteRepeatedFailureAlert::dispatch($site, $run->id)
            ->onQueue((string) config('joinera.queues.alerts', 'alerts'));
    }
}
