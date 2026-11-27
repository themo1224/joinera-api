<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\ApplyAttemptKind;
use App\Enums\IntakeRunSiteStatus;
use App\Models\Application;
use App\Models\ApplyAttempt;
use App\Models\BlockEvent;
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
     *     blocks: array{
     *         openPauseCount: int,
     *         openBlockEventCount: int,
     *         staffHelpCount: int,
     *         pausesBySite: list<array{siteId: string, openPauseCount: int}>,
     *         pausedApplicantIds: list<int>,
     *         challengesByKind: list<array{kind: string, count: int}>
     *     },
     *     applyOutcomes: array{
     *         successAttempts: int,
     *         challengeAttempts: int,
     *         hardFailAttempts: int,
     *         retryableAttempts: int,
     *         successRate: float|null,
     *         challengeRate: float|null,
     *         staffHelpRate: float|null
     *     },
     *     funnel: list<array{status: string, count: int}>,
     *     docCostUnits: int|null
     * }
     */
    public function handle(): array
    {
        $applyOutcomes = $this->applyOutcomes();

        return [
            'generatedAt' => now()->toIso8601String(),
            'intake' => $this->intake(),
            'queue' => $this->queue(),
            'blocks' => $this->blocks(),
            'applyOutcomes' => $applyOutcomes,
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
     * @return array{queued: int, preparingDocs: int, applying: int, submitted: int, hardFailAttempts: int, retryableAttempts: int}
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
            'preparingDocs' => (int) ($statusCounts[ApplicationStatus::PreparingDocs->value] ?? 0),
            'applying' => (int) ($statusCounts[ApplicationStatus::Applying->value] ?? 0),
            'submitted' => (int) ($statusCounts[ApplicationStatus::Submitted->value] ?? 0),
            'hardFailAttempts' => (int) ($attemptCounts[ApplyAttemptKind::HardFail->value] ?? 0),
            'retryableAttempts' => (int) ($attemptCounts[ApplyAttemptKind::Retryable->value] ?? 0),
        ];
    }

    /**
     * @return array{
     *     openPauseCount: int,
     *     openBlockEventCount: int,
     *     staffHelpCount: int,
     *     pausesBySite: list<array{siteId: string, openPauseCount: int}>,
     *     pausedApplicantIds: list<int>,
     *     challengesByKind: list<array{kind: string, count: int}>
     * }
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

        // Plan 33 — company-site open BlockEvents (may exist without SiteIdentity pause).
        $openBlockEventCount = BlockEvent::query()->whereNull('resolved_at')->count();
        $staffHelpCount = $openBlockEventCount;

        $kindCounts = BlockEvent::query()
            ->selectRaw('observed_kind, count(*) as c')
            ->whereNull('resolved_at')
            ->groupBy('observed_kind')
            ->pluck('c', 'observed_kind')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $challengesByKind = [];
        foreach ($kindCounts as $kind => $count) {
            $challengesByKind[] = [
                'kind' => (string) $kind,
                'count' => $count,
            ];
        }
        usort(
            $challengesByKind,
            static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['kind'], $b['kind']),
        );

        return [
            'openPauseCount' => $paused->count(),
            'openBlockEventCount' => $openBlockEventCount,
            'staffHelpCount' => $staffHelpCount,
            'pausesBySite' => $pausesBySite,
            'pausedApplicantIds' => $pausedApplicantIds,
            'challengesByKind' => $challengesByKind,
        ];
    }

    /**
     * Plan 33 — success / challenge / Staff-help rates for Mode B attempts.
     *
     * @return array{
     *     successAttempts: int,
     *     challengeAttempts: int,
     *     hardFailAttempts: int,
     *     retryableAttempts: int,
     *     successRate: float|null,
     *     challengeRate: float|null,
     *     staffHelpRate: float|null
     * }
     */
    private function applyOutcomes(): array
    {
        $attemptCounts = ApplyAttempt::query()
            ->selectRaw('kind, count(*) as c')
            ->whereIn('kind', [
                ApplyAttemptKind::Success->value,
                ApplyAttemptKind::Challenge->value,
                ApplyAttemptKind::HardFail->value,
                ApplyAttemptKind::Retryable->value,
            ])
            ->groupBy('kind')
            ->pluck('c', 'kind')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $success = (int) ($attemptCounts[ApplyAttemptKind::Success->value] ?? 0);
        $challenge = (int) ($attemptCounts[ApplyAttemptKind::Challenge->value] ?? 0);
        $hardFail = (int) ($attemptCounts[ApplyAttemptKind::HardFail->value] ?? 0);
        $retryable = (int) ($attemptCounts[ApplyAttemptKind::Retryable->value] ?? 0);
        $total = $success + $challenge + $hardFail + $retryable;

        $openBlocks = BlockEvent::query()->whereNull('resolved_at')->count();

        return [
            'successAttempts' => $success,
            'challengeAttempts' => $challenge,
            'hardFailAttempts' => $hardFail,
            'retryableAttempts' => $retryable,
            'successRate' => $total > 0 ? round($success / $total, 4) : null,
            'challengeRate' => $total > 0 ? round($challenge / $total, 4) : null,
            // Staff-help rate = open blocks / challenges (1.0 when every challenge still open).
            'staffHelpRate' => $challenge > 0 ? round(min(1, $openBlocks / $challenge), 4) : ($openBlocks > 0 ? 1.0 : null),
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
