<?php

namespace App\Http\Controllers;

use App\Enums\DocArtifactKind;
use App\Enums\DocumentAssetKind;
use App\Models\Application;
use App\Models\DocArtifact;
use App\Models\ProfileDocumentAsset;
use App\Models\SiteIdentity;
use App\Support\ApplyUrlPreference;
use App\Support\ModeBCandidatePack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Internal read API for the Mode B Playwright worker (shared secret only).
 */
class InternalModeBContextController extends Controller
{
    public function show(Request $request, Application $application): JsonResponse
    {
        $application->loadMissing([
            'listing',
            'docArtifacts',
            'user.profile',
            'user.mailboxIdentity',
            'user.profileDocumentAssets',
        ]);

        $identity = SiteIdentity::query()
            ->where('user_id', $application->user_id)
            ->where('site', $application->site)
            ->where('paused', false)
            ->orderByDesc('id')
            ->first();

        $base = rtrim((string) $request->getSchemeAndHttpHost(), '/');
        $prefix = $base.'/api/v1/internal/mode-b/applications/'.$application->id;

        $cv = $this->artifactMeta($application, DocArtifactKind::Cv, $prefix);
        $cover = $this->artifactMeta($application, DocArtifactKind::Cover, $prefix);

        $candidate = $application->user !== null
            ? ModeBCandidatePack::fromUser($application->user)
            : ModeBCandidatePack::empty();

        $candidate['cover_letter'] = $this->coverLetterText($application);
        // Listing employer — maps Softgarden "Für welches Unternehmen …" / similar selects.
        $company = trim((string) ($application->listing?->company ?? ''));
        if ($company !== '') {
            $candidate['company'] = $company;
        }
        // Listing title — maps IU OBW / Softgarden "Studiengang" selects from the vacancy name.
        $listingTitle = trim((string) ($application->listing?->title ?? ''));
        if ($listingTitle !== '') {
            $candidate['listing_title'] = $listingTitle;
        }

        $applyUrl = $application->listing?->apply_url ?? $application->listing?->url;
        $source = (string) ($application->site ?: $application->listing?->source ?: '');
        $applyTarget = ApplyUrlPreference::applyTarget($source, $applyUrl);

        return response()->json([
            'user_id' => (int) $application->user_id,
            'site_id' => (string) $application->site,
            'application_id' => (int) $application->id,
            'listing_url' => $application->listing?->url,
            'apply_url' => $applyUrl,
            'apply_target' => $applyTarget,
            'site_login' => $identity?->site_login,
            'site_secret' => $identity?->secret,
            'candidate' => $candidate,
            'artifacts' => [
                'cv' => $cv,
                'cover' => $cover,
                'passport' => $this->profileDocMeta($application, DocumentAssetKind::Passport, $prefix),
                'language_cert' => $this->profileDocMeta($application, DocumentAssetKind::LanguageCert, $prefix),
                'education_cert' => $this->profileDocMeta($application, DocumentAssetKind::EducationCert, $prefix),
            ],
        ]);
    }

    public function artifact(Application $application, string $kind): StreamedResponse|JsonResponse
    {
        $docEnum = DocArtifactKind::tryFrom($kind);
        if ($docEnum !== null) {
            return $this->streamDocArtifact($application, $docEnum);
        }

        $profileKind = DocumentAssetKind::tryFrom($kind);
        if ($profileKind !== null) {
            return $this->streamProfileDocument($application, $profileKind);
        }

        return response()->json([
            'code' => 'validation_failed',
            'message' => 'Unknown artifact kind.',
        ], 422);
    }

    private function streamDocArtifact(Application $application, DocArtifactKind $enum): StreamedResponse|JsonResponse
    {
        /** @var DocArtifact|null $artifact */
        $artifact = DocArtifact::query()
            ->where('application_id', $application->id)
            ->where('kind', $enum)
            ->first();

        if ($artifact === null || ! Storage::disk($artifact->disk)->exists($artifact->path)) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Artifact missing.',
            ], 404);
        }

        $filename = $enum->value.'-'.$application->id.'.pdf';

        return Storage::disk($artifact->disk)->response(
            $artifact->path,
            $filename,
            ['Content-Type' => $artifact->mime ?: 'application/pdf'],
        );
    }

    private function streamProfileDocument(Application $application, DocumentAssetKind $kind): StreamedResponse|JsonResponse
    {
        $application->loadMissing(['user.profileDocumentAssets']);

        /** @var ProfileDocumentAsset|null $asset */
        $asset = $application->user?->profileDocumentAssets
            ->first(static function (ProfileDocumentAsset $row) use ($kind): bool {
                $rowKind = $row->kind instanceof DocumentAssetKind
                    ? $row->kind
                    : DocumentAssetKind::tryFrom((string) $row->kind);

                return $rowKind === $kind;
            });

        if ($asset === null || ! Storage::disk($asset->disk)->exists($asset->path)) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Profile document missing.',
            ], 404);
        }

        $filename = $asset->original_name ?: ($kind->value.'-'.$application->user_id);

        return Storage::disk($asset->disk)->response(
            $asset->path,
            $filename,
            ['Content-Type' => $asset->mime ?: 'application/octet-stream'],
        );
    }

    /**
     * @return array{kind: string, url: string, filename: string}|null
     */
    private function artifactMeta(Application $application, DocArtifactKind $kind, string $prefix): ?array
    {
        $exists = DocArtifact::query()
            ->where('application_id', $application->id)
            ->where('kind', $kind)
            ->exists();

        if (! $exists) {
            return null;
        }

        return [
            'kind' => $kind->value,
            'url' => $prefix.'/artifacts/'.$kind->value,
            'filename' => $kind->value.'-'.$application->id.'.pdf',
        ];
    }

    /**
     * @return array{kind: string, url: string, filename: string}|null
     */
    private function profileDocMeta(Application $application, DocumentAssetKind $kind, string $prefix): ?array
    {
        $user = $application->user;
        if ($user === null) {
            return null;
        }

        /** @var ProfileDocumentAsset|null $asset */
        $asset = $user->relationLoaded('profileDocumentAssets')
            ? $user->profileDocumentAssets->first(static function (ProfileDocumentAsset $row) use ($kind): bool {
                $rowKind = $row->kind instanceof DocumentAssetKind
                    ? $row->kind
                    : DocumentAssetKind::tryFrom((string) $row->kind);

                return $rowKind === $kind;
            })
            : ProfileDocumentAsset::query()
                ->where('user_id', $user->id)
                ->where('kind', $kind)
                ->first();

        if ($asset === null) {
            return null;
        }

        $filename = $asset->original_name ?: ($kind->value.'.pdf');

        return [
            'kind' => $kind->value,
            'url' => $prefix.'/artifacts/'.$kind->value,
            'filename' => $filename,
        ];
    }

    private function coverLetterText(Application $application): ?string
    {
        /** @var DocArtifact|null $cover */
        $cover = DocArtifact::query()
            ->where('application_id', $application->id)
            ->where('kind', DocArtifactKind::Cover)
            ->first();

        if ($cover === null) {
            return null;
        }

        $sidecar = $cover->path.'.txt';
        if (! Storage::disk($cover->disk)->exists($sidecar)) {
            return null;
        }

        $text = Storage::disk($cover->disk)->get($sidecar);
        if (! is_string($text)) {
            return null;
        }

        $trimmed = trim($text);

        return $trimmed !== '' ? $trimmed : null;
    }
}
