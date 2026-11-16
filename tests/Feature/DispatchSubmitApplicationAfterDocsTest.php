<?php

namespace Tests\Feature;

use App\Contracts\LlmClient;
use App\Enums\ApplicationStatus;
use App\Enums\DocsFailureCode;
use App\Jobs\SubmitApplicationJob;
use App\Models\Application;
use App\Models\Listing;
use App\Models\Profile;
use App\Models\User;
use App\Services\Ai\FakeLlmClient;
use App\Services\Docs\PrepareApplicationDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Plan 1 — docs success dispatches Mode B SubmitApplicationJob; docs failure does not.
 */
class DispatchSubmitApplicationAfterDocsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'joinera.docs.disk' => 'local',
            'joinera.docs.regenerate_max_per_hour' => 5,
            'joinera.queues.apply' => 'apply',
            'joinera.ai.driver' => 'fake',
        ]);
    }

    public function test_success_path_dispatches_submit_application_job(): void
    {
        Queue::fake([SubmitApplicationJob::class]);

        $application = $this->makeQueuedApplication(industry: 'IT', category: 'IT');

        app(PrepareApplicationDocuments::class)->handle($application->id);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Applying, $application->status);
        $this->assertNull($application->docs_failure_code);

        Queue::assertPushedOn('apply', SubmitApplicationJob::class);
        Queue::assertPushed(SubmitApplicationJob::class, function (SubmitApplicationJob $job) use ($application): bool {
            return $job->applicationId === $application->id;
        });
    }

    public function test_empty_listing_category_still_dispatches_submit_after_docs_interim(): void
    {
        Queue::fake([SubmitApplicationJob::class]);

        $application = $this->makeQueuedApplication(industry: 'IT', category: null);

        app(PrepareApplicationDocuments::class)->handle($application->id);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Applying, $application->status);
        $this->assertNull($application->docs_failure_code);

        Queue::assertPushedOn('apply', SubmitApplicationJob::class);
    }

    public function test_docs_failure_does_not_dispatch_submit_application_job(): void
    {
        Queue::fake([SubmitApplicationJob::class]);

        $application = $this->makeQueuedApplication(industry: 'IT', category: 'nursing');

        /** @var FakeLlmClient $llm */
        $llm = app(FakeLlmClient::class);
        $llm->queueContent('');
        $llm->queueContent('');
        $this->app->instance(LlmClient::class, $llm);

        app(PrepareApplicationDocuments::class)->handle($application->id);

        $application->refresh();
        $this->assertSame(DocsFailureCode::LlmEmpty->value, $application->docs_failure_code);
        $this->assertNotSame(ApplicationStatus::Applying, $application->status);

        Queue::assertNotPushed(SubmitApplicationJob::class);
    }

    private function makeQueuedApplication(?string $industry, ?string $category): Application
    {
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

        $listing = Listing::factory()->create([
            'category' => $category,
            'title' => 'Test Stelle',
            'company' => 'Firma GmbH',
            'description' => 'Beschreibung',
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
