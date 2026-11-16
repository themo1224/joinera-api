<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\ApplyAttemptKind;
use App\Enums\IntakeRunSiteStatus;
use App\Models\Application;
use App\Models\ApplyAttempt;
use App\Models\DocArtifact;
use App\Models\IntakeRunSite;
use App\Models\SiteIdentity;
use Illuminate\Support\Facades\DB;

class BuildOpsReport
{
    /**
     * @return array{
     *     generatedAt: string,
     *     intake: list<array{siteId: string, lastRunAt: string, newListingCount: int, errorCount: int}>,
     *     queue: array{queued: int, applying: int, submitted: int, hardFailAttempts: int, retryableAttempts: int},
     *     blocks: array{openPauseCount: int, pausesBySite: list<array{siteId: string, openPauseCount: int}>, pausedApplicantIds: list<int>},
     *     funnel: list<array{status: string, count: int}>,
     *     docCostUnits: int|null
     * }
     */
    public function handle(): array
    {
        return [
            'generatedAt' => now()->toIso8601String(),
            'intake' => $this->intake(),
            'queue' => $this->queue(),
            'blocks' => $this->blocks(),
            'funnel' => $this->funnel(),
            'docCostUnits' => $this->docCostUnits(),
        ];
    }

    /**
     * Latest intake run row per site (by parent run id, then site row id).
     * Aggregates in SQL so large intake histories are not loaded into PHP.
     *
     * @return list<array{siteId: string, lastRunAt: string, newListingCount: int, errorCount: int}>
     */
    private function intake(): array
    {
        $latestRunPerSite = DB::table('intake_run_sites')
            ->select('site', DB::raw('MAX(intake_run_id) as max_run_id'))
            ->groupBy('site');

        $latestIds = DB::table('intake_run_sites as irs')
            ->joinSub($latestRunPerSite, 'latest', function ($join): void {
                $join->on('irs.site', '=', 'latest.site')
                    ->on('irs.intake_run_id', '=', 'latest.max_run_id');
            })
            ->groupBy('irs.site')
            ->selectRaw('MAX(irs.id) as id')
            ->pluck('id');

        if ($latestIds->isEmpty()) {
            return [];
        }

        $sites = IntakeRunSite::query()
            ->with('intakeRun')
            ->whereIn('id', $latestIds)
            ->orderBy('site')
            ->get();

        $rows = [];
        foreach ($sites as $siteRow) {
            $run = $siteRow->intakeRun;
            $lastRunAt = $run?->finished_at
                ?? $run?->started_at
                ?? $siteRow->updated_at
                ?? $siteRow->created_at
                ?? now();

            $rows[] = [
                'siteId' => (string) $siteRow->site,
                'lastRunAt' => $lastRunAt->toIso8601String(),
                'newListingCount' => (int) $siteRow->listings_created,
                'errorCount' => $siteRow->status === IntakeRunSiteStatus::Failed ? 1 : 0,
            ];
        }

        return $rows;
    }

    /**
     * @return array{queued: int, applying: int, submitted: int, hardFailAttempts: int, retryableAttempts: int}
     */
    private function queue(): array
    {
        $statusCounts = Application::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $attemptCounts = ApplyAttempt::query()
            ->selectRaw('kind, count(*) as c')
            ->whereIn('kind', [
                ApplyAttemptKind::HardFail->value,
                ApplyAttemptKind::Retryable->value,
            ])
            ->groupBy('kind')
            ->pluck('c', 'kind')
            ->map(fn ($count): int => (int) $count)
            ->all();

        return [
            'queued' => (int) ($statusCounts[ApplicationStatus::Queued->value] ?? 0),
            'applying' => (int) ($statusCounts[ApplicationStatus::Applying->value] ?? 0),
            'submitted' => (int) ($statusCounts[ApplicationStatus::Submitted->value] ?? 0),
            'hardFailAttempts' => (int) ($attemptCounts[ApplyAttemptKind::HardFail->value] ?? 0),
            'retryableAttempts' => (int) ($attemptCounts[ApplyAttemptKind::Retryable->value] ?? 0),
        ];
    }

    /**
     * @return array{openPauseCount: int, pausesBySite: list<array{siteId: string, openPauseCount: int}>, pausedApplicantIds: list<int>}
     */
    private function blocks(): array
    {
        $paused = SiteIdentity::query()
            ->where('paused', true)
            ->orderBy('site')
            ->orderBy('user_id')
            ->get(['id', 'user_id', 'site']);

        $bySite = [];
        $applicantIds = [];
        foreach ($paused as $identity) {
            $site = (string) $identity->site;
            $bySite[$site] = ($bySite[$site] ?? 0) + 1;
            $applicantIds[(int) $identity->user_id] = true;
        }

        $pausesBySite = [];
        foreach ($bySite as $siteId => $count) {
            $pausesBySite[] = [
                'siteId' => $siteId,
                'openPauseCount' => $count,
            ];
        }

        $pausedApplicantIds = array_map('intval', array_keys($applicantIds));
        sort($pausedApplicantIds);

        return [
            'openPauseCount' => $paused->count(),
            'pausesBySite' => $pausesBySite,
            'pausedApplicantIds' => $pausedApplicantIds,
        ];
    }

    /**
     * @return list<array{status: string, count: int}>
     */
    private function funnel(): array
    {
        $counts = Application::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $funnel = [];
        foreach (ApplicationStatus::cases() as $status) {
            $funnel[] = [
                'status' => $status->value,
                'count' => (int) ($counts[$status->value] ?? 0),
            ];
        }

        return $funnel;
    }

    private function docCostUnits(): ?int
    {
        $hasCost = DocArtifact::query()
            ->whereNotNull('cost_minor_units')
            ->exists();

        if (! $hasCost) {
            return null;
        }

        return (int) DocArtifact::query()
            ->whereNotNull('cost_minor_units')
            ->sum('cost_minor_units');
    }
}
