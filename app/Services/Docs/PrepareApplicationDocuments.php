<?php

namespace App\Services\Docs;

use App\Contracts\PdfRenderer;
use App\Enums\ApplicationStatus;
use App\Enums\DocArtifactKind;
use App\Enums\DocGenerationRule;
use App\Enums\DocsFailureCode;
use App\Events\ApplicationStatusChanged;
use App\Exceptions\LlmRequestException;
use App\Exceptions\NonPdfOutputException;
use App\Models\Application;
use App\Models\CvAsset;
use App\Models\DocArtifact;
use App\Models\Listing;
use App\Models\Profile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PrepareApplicationDocuments
{
    public function __construct(
        private readonly ResolveDocGenerationRule $resolveRule,
        private readonly GenerateCoverLetterText $generateCover,
        private readonly GenerateCvText $generateCv,
        private readonly WrapDocHtml $wrapHtml,
        private readonly PdfRenderer $pdfRenderer,
        private readonly StoreDocArtifact $storeArtifact,
    ) {}

    public function handle(int $applicationId): void
    {
        $application = Application::query()->find($applicationId);
        if ($application === null) {
            return;
        }

        if ($this->isComplete($application)) {
            return;
        }

        if (! in_array($application->status, [
            ApplicationStatus::Queued,
            ApplicationStatus::PreparingDocs,
        ], true)) {
            return;
        }

        if (! $this->consumeRateLimit($application->id)) {
            $this->fail($application, DocsFailureCode::RateLimited);

            return;
        }

        $this->markPreparing($application);

        $application->loadMissing(['user.profile', 'user.cvAsset', 'listing']);
        $profile = $application->user?->profile;
        $listing = $application->listing;

        if ($listing === null || $profile === null) {
            $this->fail($application, DocsFailureCode::CategoryUnknown);

            return;
        }

        $rule = $this->resolveRule->handle($profile, $listing);
        if ($rule === DocGenerationRule::CategoryUnknown) {
            $this->fail($application, DocsFailureCode::CategoryUnknown);

            return;
        }

        try {
            if ($rule === DocGenerationRule::CoverOnly) {
                $this->prepareCoverOnly($application, $profile, $listing);
            } else {
                $this->prepareCvAndCover($application, $profile, $listing);
            }
        } catch (NonPdfOutputException $exception) {
            Log::warning('docs.non_pdf', [
                'application_id' => $application->id,
                'message' => $exception->getMessage(),
            ]);
            $this->discardArtifacts($application);
            $this->fail($application, DocsFailureCode::NonPdf);

            return;
        } catch (LlmRequestException $exception) {
            Log::warning('docs.llm_failed', [
                'application_id' => $application->id,
                'message' => $exception->getMessage(),
            ]);
            $this->discardArtifacts($application);
            $this->fail($application, DocsFailureCode::LlmFailed);

            throw $exception;
        }

        $application->refresh();
        if ($application->docs_failure_code !== null) {
            return;
        }

        if (! $this->hasBothArtifacts($application)) {
            return;
        }

        $this->markApplying($application);
    }

    private function prepareCoverOnly(Application $application, Profile $profile, Listing $listing): void
    {
        $cvAsset = $application->user?->cvAsset;
        $pdfBytes = $this->readBaseCvPdf($cvAsset);
        if ($pdfBytes === null) {
            $this->fail($application, DocsFailureCode::MissingBaseCv);

            return;
        }

        $this->storeArtifact->handle(
            $application,
            DocArtifactKind::Cv,
            DocGenerationRule::CoverOnly,
            $pdfBytes,
            cacheHit: true,
        );

        $cover = $this->generateCover->handle($profile, $listing);
        if ($cover->text === '') {
            $this->discardArtifacts($application);
            $this->fail($application, DocsFailureCode::LlmEmpty);

            return;
        }

        $html = $this->wrapHtml->handle('Anschreiben', $cover->text);
        $coverPdf = $this->pdfRenderer->render($html);

        $this->storeArtifact->handle(
            $application,
            DocArtifactKind::Cover,
            DocGenerationRule::CoverOnly,
            $coverPdf,
            cacheHit: false,
            llm: $cover,
        );
    }

    private function prepareCvAndCover(Application $application, Profile $profile, Listing $listing): void
    {
        $cv = $this->generateCv->handle($profile, $listing);
        if ($cv->text === '') {
            $this->fail($application, DocsFailureCode::LlmEmpty);

            return;
        }

        $cover = $this->generateCover->handle($profile, $listing);
        if ($cover->text === '') {
            $this->fail($application, DocsFailureCode::LlmEmpty);

            return;
        }

        $cvPdf = $this->pdfRenderer->render(
            $this->wrapHtml->handle('Lebenslauf', $cv->text),
        );
        $coverPdf = $this->pdfRenderer->render(
            $this->wrapHtml->handle('Anschreiben', $cover->text),
        );

        $this->storeArtifact->handle(
            $application,
            DocArtifactKind::Cv,
            DocGenerationRule::CvAndCover,
            $cvPdf,
            cacheHit: false,
            llm: $cv,
        );
        $this->storeArtifact->handle(
            $application,
            DocArtifactKind::Cover,
            DocGenerationRule::CvAndCover,
            $coverPdf,
            cacheHit: false,
            llm: $cover,
        );
    }

    private function readBaseCvPdf(?CvAsset $cvAsset): ?string
    {
        if ($cvAsset === null) {
            return null;
        }

        if (! str_contains(strtolower((string) $cvAsset->mime), 'pdf')) {
            return null;
        }

        if (! Storage::disk($cvAsset->disk)->exists($cvAsset->path)) {
            return null;
        }

        $bytes = Storage::disk($cvAsset->disk)->get($cvAsset->path);
        if (! is_string($bytes) || ! str_starts_with($bytes, '%PDF')) {
            return null;
        }

        return $bytes;
    }

    private function markPreparing(Application $application): void
    {
        DB::transaction(function () use ($application): void {
            /** @var Application $locked */
            $locked = Application::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === ApplicationStatus::Queued) {
                $from = $locked->status->value;
                $locked->status = ApplicationStatus::PreparingDocs;
                $locked->docs_failure_code = null;
                $locked->save();

                ApplicationStatusChanged::dispatch(
                    $locked->id,
                    $from,
                    ApplicationStatus::PreparingDocs->value,
                );
            } elseif ($locked->status === ApplicationStatus::PreparingDocs) {
                $locked->docs_failure_code = null;
                $locked->save();
            }

            $application->refresh();
        });
    }

    private function markApplying(Application $application): void
    {
        DB::transaction(function () use ($application): void {
            /** @var Application $locked */
            $locked = Application::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ApplicationStatus::PreparingDocs) {
                return;
            }

            $from = $locked->status->value;
            $locked->status = ApplicationStatus::Applying;
            $locked->docs_failure_code = null;
            $locked->save();

            ApplicationStatusChanged::dispatch(
                $locked->id,
                $from,
                ApplicationStatus::Applying->value,
            );

            $application->refresh();
        });
    }

    private function fail(Application $application, DocsFailureCode $code): void
    {
        $application->docs_failure_code = $code->value;
        if ($application->status === ApplicationStatus::Queued) {
            $from = $application->status->value;
            $application->status = ApplicationStatus::PreparingDocs;
            $application->save();
            ApplicationStatusChanged::dispatch(
                $application->id,
                $from,
                ApplicationStatus::PreparingDocs->value,
            );
        } else {
            $application->save();
        }
    }

    private function isComplete(Application $application): bool
    {
        return $application->status === ApplicationStatus::Applying
            && $this->hasBothArtifacts($application);
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

    private function discardArtifacts(Application $application): void
    {
        $artifacts = DocArtifact::query()
            ->where('application_id', $application->id)
            ->get();

        foreach ($artifacts as $artifact) {
            Storage::disk($artifact->disk)->delete($artifact->path);
            $artifact->delete();
        }
    }

    private function consumeRateLimit(int $applicationId): bool
    {
        $max = (int) config('joinera.docs.regenerate_max_per_hour', 3);
        $key = 'docs:prepare:'.$applicationId;
        $count = (int) Cache::get($key, 0);

        if ($count >= $max) {
            return false;
        }

        if ($count === 0) {
            Cache::put($key, 1, now()->addHour());
        } else {
            Cache::increment($key);
        }

        return true;
    }
}
