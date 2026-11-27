<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Enums\DocArtifactKind;
use App\Jobs\PrepareApplicationDocsJob;
use App\Models\Application;
use App\Models\DocArtifact;
use App\Services\Docs\PrepareApplicationDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffApplicationDocsController extends Controller
{
    public function __construct(
        private readonly PrepareApplicationDocuments $prepareDocuments,
    ) {}

    public function download(Application $application, string $kind): StreamedResponse|JsonResponse
    {
        $artifactKind = DocArtifactKind::tryFrom($kind);
        if ($artifactKind === null) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Not found.',
            ], 404);
        }

        $artifact = DocArtifact::query()
            ->where('application_id', $application->id)
            ->where('kind', $artifactKind)
            ->first();

        if ($artifact === null || ! Storage::disk($artifact->disk)->exists($artifact->path)) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Not found.',
            ], 404);
        }

        $filename = sprintf('application-%d-%s.pdf', $application->id, $artifactKind->value);

        return Storage::disk($artifact->disk)->response(
            $artifact->path,
            $filename,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ],
        );
    }

    public function generate(Application $application): JsonResponse
    {
        if (! in_array($application->status, [
            ApplicationStatus::Queued,
            ApplicationStatus::PreparingDocs,
            ApplicationStatus::Applying,
        ], true)) {
            return response()->json([
                'code' => 'docs_generate_not_allowed',
                'message' => 'Documents cannot be generated for this application status.',
            ], 409);
        }

        if ($this->prepareDocuments->isRateLimited($application->id)) {
            return response()->json([
                'code' => 'docs_rate_limited',
                'message' => 'Document generation rate limit reached for this application.',
            ], 409);
        }

        // force=true so Queued / PreparingDocs / Applying can all re-run from Admin.
        PrepareApplicationDocsJob::dispatch($application->id, force: true);

        return response()->json([
            'success' => true,
            'applicationId' => $application->id,
        ], 202);
    }
}
