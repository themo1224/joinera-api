<?php

namespace App\Services;

use App\Enums\DocArtifactKind;
use App\Models\Application;
use App\Models\DocArtifact;

/**
 * Mode B docs gate: applying row must have null docs_failure_code + cv and cover artifacts.
 */
class AssertApplicationDocsReadyForModeB
{
    /**
     * @return array{ready: bool, stop_reason: string|null}
     */
    public function handle(Application $application): array
    {
        if ($application->docs_failure_code !== null) {
            return [
                'ready' => false,
                'stop_reason' => (string) $application->docs_failure_code,
            ];
        }

        if (! $this->hasBothArtifacts($application)) {
            return [
                'ready' => false,
                'stop_reason' => 'missing_artifacts',
            ];
        }

        return [
            'ready' => true,
            'stop_reason' => null,
        ];
    }

    private function hasBothArtifacts(Application $application): bool
    {
        $kinds = DocArtifact::query()
            ->where('application_id', $application->id)
            ->pluck('kind')
            ->map(fn ($kind) => $kind instanceof DocArtifactKind ? $kind->value : (string) $kind)
            ->all();

        return in_array(DocArtifactKind::Cv->value, $kinds, true)
            && in_array(DocArtifactKind::Cover->value, $kinds, true);
    }
}
