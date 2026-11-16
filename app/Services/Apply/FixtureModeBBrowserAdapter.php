<?php

namespace App\Services\Apply;

use App\Contracts\ModeBBrowserPort;
use App\Enums\DocArtifactKind;
use App\Models\Application;
use App\Models\DocArtifact;

/**
 * Fixture stand-in for an external Playwright browser worker.
 * Resolves DocArtifact paths internally; never accepts another user's session id.
 */
class FixtureModeBBrowserAdapter implements ModeBBrowserPort
{
    public function submit(int $userId, string $siteId, int $applicationId): ModeBBrowserResult
    {
        $application = Application::query()->find($applicationId);
        if ($application === null) {
            return ModeBBrowserResult::identityMismatch('application_missing');
        }

        if ((int) $application->user_id !== $userId || (string) $application->site !== $siteId) {
            return ModeBBrowserResult::identityMismatch('session_user_mismatch');
        }

        // Stub: warm/login session for this user×site only.
        // Stub: open listing for application_id.
        if (! $this->hasBothArtifacts($applicationId)) {
            return ModeBBrowserResult::identityMismatch('missing_artifacts');
        }

        // Stub: upload cv + cover DocArtifact PDFs, submit form, parse confirmation.
        return ModeBBrowserResult::success('fixture-'.$applicationId);
    }

    private function hasBothArtifacts(int $applicationId): bool
    {
        $kinds = DocArtifact::query()
            ->where('application_id', $applicationId)
            ->pluck('kind')
            ->map(fn ($kind) => $kind instanceof DocArtifactKind ? $kind->value : (string) $kind)
            ->all();

        return in_array(DocArtifactKind::Cv->value, $kinds, true)
            && in_array(DocArtifactKind::Cover->value, $kinds, true);
    }
}
