<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\MatchStatus;
use App\Events\ApplicationStatusChanged;
use App\Jobs\PrepareApplicationDocsJob;
use App\Models\Application;
use App\Models\CandidateListingMatch;
use Illuminate\Database\QueryException;

class CreateQueuedApplicationFromMatch
{
    public function handle(CandidateListingMatch $match): ?Application
    {
        if ($match->status !== MatchStatus::Queued) {
            return null;
        }

        $match->loadMissing('listing');
        $listing = $match->listing;

        if ($listing === null) {
            return null;
        }

        $application = $this->findOrCreate($match, (string) $listing->source);

        if ($application->candidate_listing_match_id === null) {
            $application->candidate_listing_match_id = $match->id;
            $application->save();
        }

        if ($application->status === ApplicationStatus::Matched) {
            $from = $application->status->value;
            $application->status = ApplicationStatus::Queued;
            $application->save();

            ApplicationStatusChanged::dispatch(
                $application->id,
                $from,
                ApplicationStatus::Queued->value,
            );

            PrepareApplicationDocsJob::dispatch($application->id);
        }

        return $application->refresh();
    }

    private function findOrCreate(CandidateListingMatch $match, string $site): Application
    {
        $existing = Application::query()
            ->where('user_id', $match->user_id)
            ->where('listing_id', $match->listing_id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return Application::query()->create([
                'user_id' => $match->user_id,
                'listing_id' => $match->listing_id,
                'candidate_listing_match_id' => $match->id,
                'site' => $site,
                'status' => ApplicationStatus::Matched,
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $raced = Application::query()
                ->where('user_id', $match->user_id)
                ->where('listing_id', $match->listing_id)
                ->first();

            if ($raced === null) {
                throw $exception;
            }

            return $raced;
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return $sqlState === '23000'
            || $driverCode === '19'
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
