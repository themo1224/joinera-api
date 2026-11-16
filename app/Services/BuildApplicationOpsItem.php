<?php

namespace App\Services;

use App\Enums\DocArtifactKind;
use App\Models\Application;
use App\Models\ApplyAttempt;
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
     *     updatedAt: string|null,
     *     attempts: list<array{kind: string|null, stopReason: string|null, createdAt: string|null}>
     * }
     */
    public function handle(Application $application): array
    {
        $application->loadMissing(['user', 'listing', 'candidateListingMatch', 'docArtifacts', 'applyAttempts']);

        $user = $application->user;
        $listing = $application->listing;
        $match = $application->candidateListingMatch;
        $artifacts = $application->docArtifacts;

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
            'updatedAt' => $application->updated_at?->toISOString(),
            'attempts' => $this->attemptsPayload($application->applyAttempts),
        ];
    }

    /**
     * @param  Collection<int, ApplyAttempt>  $attempts
     * @return list<array{kind: string|null, stopReason: string|null, createdAt: string|null}>
     */
    private function attemptsPayload(Collection $attempts): array
    {
        return $attempts
            ->sortByDesc(static fn (ApplyAttempt $attempt): int => (int) $attempt->id)
            ->take(self::ATTEMPTS_CAP)
            ->values()
            ->map(static fn (ApplyAttempt $attempt): array => [
                'kind' => $attempt->kind?->value,
                'stopReason' => $attempt->stop_reason,
                'createdAt' => $attempt->created_at?->toISOString(),
            ])
            ->all();
    }
}
