<?php

namespace App\Services;

use App\Enums\DocArtifactKind;
use App\Models\Application;
use App\Models\ApplyAttempt;
use App\Models\BlockEvent;
use App\Support\ModeBFailureCatalog;
use Illuminate\Support\Collection;

class BuildApplicationOpsItem
{
    public const ATTEMPTS_CAP = 5;

    /**
     * @return array{
     *     id: int,
     *     status: string,
     *     site: string,
     *     userId: int,
     *     userName: string|null,
     *     userEmail: string|null,
     *     listingId: int,
     *     listingTitle: string|null,
     *     listingSource: string|null,
     *     listingCity: string|null,
     *     candidateListingMatchId: int|null,
     *     matchStatus: string|null,
     *     hardSkipReason: string|null,
     *     docsFailureCode: string|null,
     *     hasCv: bool,
     *     hasCover: bool,
     *     companyApplyUrl: string|null,
     *     blockPaused: bool,
     *     lastAttemptKind: string|null,
     *     manuallyCompletedAt: string|null,
     *     manuallyCompletedById: int|null,
     *     updatedAt: string|null,
     *     attempts: list<array{kind: string|null, stopReason: string|null, failureCode: string|null, failureLabel: string|null, createdAt: string|null}>
     * }
     */
    public function handle(Application $application): array
    {
        $application->loadMissing(['user', 'listing', 'candidateListingMatch', 'docArtifacts', 'applyAttempts']);

        $user = $application->user;
        $listing = $application->listing;
        $match = $application->candidateListingMatch;
        $artifacts = $application->docArtifacts;
        $companyApplyUrl = $listing?->apply_url ?: $listing?->url;
        $attempts = $this->attemptsPayload($application->applyAttempts);
        $lastAttemptKind = $attempts[0]['kind'] ?? null;

        $blockPaused = BlockEvent::query()
            ->where('application_id', $application->id)
            ->whereNull('resolved_at')
            ->exists();

        return [
            'id' => $application->id,
            'status' => $application->status->value,
            'site' => $application->site,
            'userId' => $application->user_id,
            'userName' => $user?->name,
            'userEmail' => $user?->email,
            'listingId' => $application->listing_id,
            'listingTitle' => $listing?->title,
            'listingSource' => $listing?->source,
            'listingCity' => $listing?->city,
            'candidateListingMatchId' => $application->candidate_listing_match_id,
            'matchStatus' => $match?->status->value,
            'hardSkipReason' => $match?->hard_skip_reason?->value,
            'docsFailureCode' => $application->docs_failure_code,
            'hasCv' => $artifacts->contains(
                static fn ($artifact): bool => $artifact->kind === DocArtifactKind::Cv,
            ),
            'hasCover' => $artifacts->contains(
                static fn ($artifact): bool => $artifact->kind === DocArtifactKind::Cover,
            ),
            // Plan 27 — Staff opens the same company apply page to finish by hand.
            'companyApplyUrl' => is_string($companyApplyUrl) && $companyApplyUrl !== ''
                ? $companyApplyUrl
                : null,
            'blockPaused' => $blockPaused,
            'lastAttemptKind' => $lastAttemptKind,
            'manuallyCompletedAt' => $application->manually_completed_at?->toISOString(),
            'manuallyCompletedById' => $application->manually_completed_by,
            'updatedAt' => $application->updated_at?->toISOString(),
            'attempts' => $attempts,
        ];
    }

    /**
     * @param  Collection<int, ApplyAttempt>  $attempts
     * @return list<array{kind: string|null, stopReason: string|null, failureCode: string|null, failureLabel: string|null, createdAt: string|null}>
     */
    private function attemptsPayload(Collection $attempts): array
    {
        return $attempts
            ->sortByDesc(static fn (ApplyAttempt $attempt): int => (int) $attempt->id)
            ->take(self::ATTEMPTS_CAP)
            ->values()
            ->map(static function (ApplyAttempt $attempt): array {
                $mapped = ModeBFailureCatalog::resolve($attempt->stop_reason, $attempt->kind);

                return [
                    'kind' => $attempt->kind?->value,
                    'stopReason' => $attempt->stop_reason,
                    // Plan 31 — Staff-readable failure map (EN default for ops JSON).
                    'failureCode' => $attempt->stop_reason !== null && $attempt->stop_reason !== ''
                        ? $mapped['code']
                        : ($attempt->kind?->value),
                    'failureLabel' => $attempt->stop_reason !== null && $attempt->stop_reason !== ''
                        ? $mapped['labelEn']
                        : ($attempt->kind !== null ? $mapped['labelEn'] : null),
                    'createdAt' => $attempt->created_at?->toISOString(),
                ];
            })
            ->all();
    }
}
