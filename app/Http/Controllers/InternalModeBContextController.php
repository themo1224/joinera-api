<?php

namespace App\Http\Controllers;

use App\Enums\DocArtifactKind;
use App\Models\Application;
use App\Models\DocArtifact;
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
        $application->loadMissing(['listing', 'docArtifacts', 'user.profile', 'user.mailboxIdentity']);

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
            ],
        ]);
    }

    public function artifact(Application $application, string $kind): StreamedResponse|JsonResponse
    {
        $enum = DocArtifactKind::tryFrom($kind);
        if ($enum === null) {
            return response()->json([
                'code' => 'validation_failed',
                'message' => 'Unknown artifact kind.',
            ], 422);
        }

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
}
