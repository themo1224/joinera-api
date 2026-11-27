<?php

namespace App\Services;

use App\Enums\IntakeRunSiteStatus;
use App\Exceptions\IntakeIdentityContextException;
use App\Exceptions\ListingDroppedException;
use App\Jobs\RecordIntakeSiteRepeatedFailureAlert;
use App\Models\IntakeRun;
use App\Models\IntakeRunSite;
use App\Models\Listing;
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
                try {
                    $detail = $adapter->parseDetail($item);
                    $result = $this->upsertListing->handle($site, $detail);
                } catch (ListingDroppedException $exception) {
                    // Re-see of expired/dead apply targets: clear apply_url so Mode B
                    // cannot keep using a known-unapplyable company link.
                    $this->clearListingApplyUrlOnDrop($site, $item);
                    continue;
                } catch (IntakeIdentityContextException) {
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

    /**
     * @param  array<string, mixed>  $listItem
     */
    private function clearListingApplyUrlOnDrop(string $site, array $listItem): void
    {
        $externalId = $listItem['external_id'] ?? null;

        if (! is_string($externalId) || $externalId === '') {
            return;
        }

        $listing = Listing::query()
            ->where('source', $site)
            ->where('external_id', $externalId)
            ->first();

        if ($listing === null || $listing->apply_url === null || $listing->apply_url === '') {
            return;
        }

        $listing->apply_url = null;
        $listing->save();
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
