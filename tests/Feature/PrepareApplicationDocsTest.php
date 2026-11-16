<?php

namespace Tests\Feature;

use App\Contracts\LlmClient;
use App\Enums\ApplicationStatus;
use App\Enums\DocArtifactKind;
use App\Enums\DocGenerationRule;
use App\Enums\DocsFailureCode;
use App\Events\ApplicationStatusChanged;
use App\Exceptions\LlmRequestException;
use App\Jobs\PrepareApplicationDocsJob;
use App\Models\Application;
use App\Models\CvAsset;
use App\Models\DocArtifact;
use App\Models\Listing;
use App\Models\Profile;
use App\Models\User;
use App\Services\Ai\FakeLlmClient;
use App\Services\Docs\FakePdfRenderer;
use App\Services\Docs\PrepareApplicationDocuments;
use App\Support\LlmChatResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * P0-13 preparing_docs acceptance (pipeline + artifacts).
 *
 * Staff/Admin download + generate + approve-404 + Candidate 403 are covered in
 * {@see StaffApplicationDocsTest} (Candidates have no panel; no Candidate download route).
 *
 * @see docs/api/IMPLEMENTATION_PHASES.md P0-13
 */
class PrepareApplicationDocsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'joinera.docs.disk' => 'local',
            'joinera.docs.regenerate_max_per_hour' => 5,
            'joinera.queues.docs' => 'docs',
            'joinera.ai.driver' => 'fake',
        ]);
    }

    public function test_same_category_reuses_base_cv_and_stores_new_cover(): void
    {
        Event::fake([ApplicationStatusChanged::class]);

        $application = $this->makeQueuedApplication(industry: 'IT', category: 'IT', withBaseCv: true);

        app(PrepareApplicationDocuments::class)->handle($application->id);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Applying, $application->status);
        $this->assertNull($application->docs_failure_code);

        $cv = DocArtifact::query()
            ->where('application_id', $application->id)
            ->where('kind', DocArtifactKind::Cv)
            ->first();
        $cover = DocArtifact::query()
            ->where('application_id', $application->id)
            ->where('kind', DocArtifactKind::Cover)
            ->first();

        $this->assertNotNull($cv);
        $this->assertNotNull($cover);
        $this->assertTrue($cv->cache_hit);
        $this->assertFalse($cover->cache_hit);
        $this->assertSame(DocGenerationRule::CoverOnly, $cv->rule);
        $this->assertSame(DocGenerationRule::CoverOnly, $cover->rule);
        $this->assertSame('application/pdf', $cv->mime);
        $this->assertSame('application/pdf', $cover->mime);
        Storage::disk('local')->assertExists($cv->path);
        Storage::disk('local')->assertExists($cover->path);

        Event::assertDispatched(ApplicationStatusChanged::class, function (ApplicationStatusChanged $event) use ($application): bool {
            return $event->applicationId === $application->id
                && $event->from === ApplicationStatus::Queued->value
                && $event->to === ApplicationStatus::PreparingDocs->value;
        });
        Event::assertDispatched(ApplicationStatusChanged::class, function (ApplicationStatusChanged $event) use ($application): bool {
            return $event->applicationId === $application->id
                && $event->from === ApplicationStatus::PreparingDocs->value
                && $event->to === ApplicationStatus::Applying->value;
        });
    }

    public function test_different_category_stores_new_cv_and_cover(): void
    {
        $application = $this->makeQueuedApplication(industry: 'IT', category: 'nursing', withBaseCv: true);

        app(PrepareApplicationDocuments::class)->handle($application->id);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Applying, $application->status);

        $cv = DocArtifact::query()
            ->where('application_id', $application->id)
            ->where('kind', DocArtifactKind::Cv)
            ->first();
        $cover = DocArtifact::query()
            ->where('application_id', $application->id)
            ->where('kind', DocArtifactKind::Cover)
            ->first();

        $this->assertNotNull($cv);
        $this->assertNotNull($cover);
        $this->assertFalse($cv->cache_hit);
        $this->assertFalse($cover->cache_hit);
        $this->assertSame(DocGenerationRule::CvAndCover, $cv->rule);
        $this->assertSame(DocGenerationRule::CvAndCover, $cover->rule);
        $this->assertNotNull($cv->llm_model);
        $this->assertNotNull($cover->llm_model);
    }

    public function test_missing_listing_category_generates_cv_and_cover_interim(): void
    {
        $application = $this->makeQueuedApplication(industry: 'IT', category: null, withBaseCv: true);

        app(PrepareApplicationDocuments::class)->handle($application->id);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Applying, $application->status);
        $this->assertNull($application->docs_failure_code);
        $this->assertSame(2, DocArtifact::query()->where('application_id', $application->id)->count());
        $this->assertTrue(
            DocArtifact::query()
                ->where('application_id', $application->id)
                ->where('kind', DocArtifactKind::Cv)
                ->exists()
        );
        $this->assertTrue(
            DocArtifact::query()
                ->where('application_id', $application->id)
                ->where('kind', DocArtifactKind::Cover)
                ->exists()
        );
    }

    public function test_non_pdf_generator_stores_nothing(): void
    {
        $application = $this->makeQueuedApplication(industry: 'IT', category: 'nursing', withBaseCv: true);

        /** @var FakeLlmClient $llm */
        $llm = app(FakeLlmClient::class);
        $llm->queueContent(FakePdfRenderer::HTML_MARKER.' cv body');
        $llm->queueContent(FakePdfRenderer::HTML_MARKER.' cover body');
        $this->app->instance(LlmClient::class, $llm);

        app(PrepareApplicationDocuments::class)->handle($application->id);

        $application->refresh();
        $this->assertSame(ApplicationStatus::PreparingDocs, $application->status);
        $this->assertSame(DocsFailureCode::NonPdf->value, $application->docs_failure_code);
        $this->assertSame(0, DocArtifact::query()->where('application_id', $application->id)->count());
    }

    public function test_missing_base_cv_on_cover_only_falls_back_to_generate(): void
    {
        $application = $this->makeQueuedApplication(industry: 'IT', category: 'IT', withBaseCv: false);

        app(PrepareApplicationDocuments::class)->handle($application->id);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Applying, $application->status);
        $this->assertNull($application->docs_failure_code);

        $cv = DocArtifact::query()
            ->where('application_id', $application->id)
            ->where('kind', DocArtifactKind::Cv)
            ->first();
        $cover = DocArtifact::query()
            ->where('application_id', $application->id)
            ->where('kind', DocArtifactKind::Cover)
            ->first();

        $this->assertNotNull($cv);
        $this->assertNotNull($cover);
        $this->assertFalse($cv->cache_hit);
        $this->assertSame(DocGenerationRule::CvAndCover, $cv->rule);
        $this->assertSame(DocGenerationRule::CvAndCover, $cover->rule);
    }

    public function test_empty_llm_cover_fails_without_artifacts(): void
    {
        $application = $this->makeQueuedApplication(industry: 'IT', category: 'IT', withBaseCv: true);

        $this->app->instance(LlmClient::class, new class implements LlmClient
        {
            public function chat(array $messages, array $options = []): LlmChatResult
            {
                return new LlmChatResult(content: '', model: 'fake/empty');
            }
        });

        app(PrepareApplicationDocuments::class)->handle($application->id);

        $application->refresh();
        $this->assertSame(DocsFailureCode::LlmEmpty->value, $application->docs_failure_code);
        $this->assertSame(0, DocArtifact::query()->where('application_id', $application->id)->count());
    }

    public function test_llm_failure_sets_code_and_rethrows(): void
    {
        $application = $this->makeQueuedApplication(industry: 'IT', category: 'nursing', withBaseCv: true);

        $this->app->instance(LlmClient::class, new class implements LlmClient
        {
            public function chat(array $messages, array $options = []): LlmChatResult
            {
                throw new LlmRequestException('boom', 429);
            }
        });

        try {
            app(PrepareApplicationDocuments::class)->handle($application->id);
            $this->fail('Expected LlmRequestException');
        } catch (LlmRequestException) {
            // expected for job retry path
        }

        $application->refresh();
        $this->assertSame(ApplicationStatus::PreparingDocs, $application->status);
        $this->assertSame(DocsFailureCode::LlmFailed->value, $application->docs_failure_code);
        $this->assertSame(0, DocArtifact::query()->where('application_id', $application->id)->count());
    }

    public function test_job_is_idempotent_when_already_applying_with_artifacts(): void
    {
        $application = $this->makeQueuedApplication(industry: 'IT', category: 'IT', withBaseCv: true);
        app(PrepareApplicationDocuments::class)->handle($application->id);
        $application->refresh();
        $this->assertSame(ApplicationStatus::Applying, $application->status);

        $before = DocArtifact::query()->where('application_id', $application->id)->pluck('path')->all();

        app(PrepareApplicationDocuments::class)->handle($application->id);

        $after = DocArtifact::query()->where('application_id', $application->id)->pluck('path')->all();
        $this->assertSame($before, $after);
        $this->assertSame(ApplicationStatus::Applying, $application->fresh()->status);
    }

    public function test_prepare_application_docs_job_runs_on_docs_queue(): void
    {
        $job = new PrepareApplicationDocsJob(42);

        $this->assertSame('docs', $job->queue);
    }

    public function test_docs_approve_route_is_not_registered(): void
    {
        $this->assertFalse(Route::has('applications.docs.approve'));

        $this->postJson('/api/v1/applications/1/docs/approve')
            ->assertNotFound();
    }

    public function test_force_regenerate_from_applying_overwrites_artifacts(): void
    {
        $application = $this->makeQueuedApplication(industry: 'IT', category: 'IT', withBaseCv: true);
        app(PrepareApplicationDocuments::class)->handle($application->id);
        $application->refresh();
        $this->assertSame(ApplicationStatus::Applying, $application->status);

        $beforeCover = DocArtifact::query()
            ->where('application_id', $application->id)
            ->where('kind', DocArtifactKind::Cover)
            ->value('path');
        $this->assertNotNull($beforeCover);

        app(PrepareApplicationDocuments::class)->handle($application->id, force: true);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Applying, $application->status);
        $this->assertNull($application->docs_failure_code);

        $afterCover = DocArtifact::query()
            ->where('application_id', $application->id)
            ->where('kind', DocArtifactKind::Cover)
            ->value('path');
        $this->assertNotNull($afterCover);
        $this->assertNotSame($beforeCover, $afterCover);
        Storage::disk('local')->assertMissing($beforeCover);
        Storage::disk('local')->assertExists($afterCover);
    }

    public function test_rate_limited_prepare_sets_docs_failure_code_and_stores_nothing(): void
    {
        config(['joinera.docs.regenerate_max_per_hour' => 1]);

        $application = $this->makeQueuedApplication(industry: 'IT', category: 'IT', withBaseCv: true);
        Cache::put('docs:prepare:'.$application->id, 1, now()->addHour());

        app(PrepareApplicationDocuments::class)->handle($application->id);

        $application->refresh();
        $this->assertSame(ApplicationStatus::PreparingDocs, $application->status);
        $this->assertSame(DocsFailureCode::RateLimited->value, $application->docs_failure_code);
        $this->assertSame(0, DocArtifact::query()->where('application_id', $application->id)->count());
    }

    private function makeQueuedApplication(
        ?string $industry,
        ?string $category,
        bool $withBaseCv,
    ): Application {
        $user = User::factory()->create();

        /** @var Profile $profile */
        $profile = $user->ensureProfile();
        $profile->fill([
            'industry' => $industry,
            'city' => 'Berlin',
            'province' => 'BE',
            'language' => 'de',
        ]);
        $profile->save();

        if ($withBaseCv) {
            $path = 'cvs/'.$user->id.'/base.pdf';
            Storage::disk('local')->put($path, "%PDF-1.4\n%base-cv\n%%EOF\n");
            CvAsset::factory()->for($user)->create([
                'disk' => 'local',
                'path' => $path,
                'mime' => 'application/pdf',
                'size' => 32,
            ]);
        }

        $listing = Listing::factory()->create([
            'category' => $category,
            'title' => 'Test Stelle',
            'company' => 'Firma GmbH',
            'description' => 'Beschreibung der Stelle',
            'country' => 'DE',
        ]);

        return Application::factory()
            ->for($user)
            ->for($listing)
            ->queued()
            ->create([
                'site' => (string) $listing->source,
            ]);
    }
}
