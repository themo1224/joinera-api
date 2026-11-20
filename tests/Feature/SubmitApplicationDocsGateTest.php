<?php

namespace Tests\Feature;

use App\Contracts\ModeBSubmitAfterDocsReady;
use App\Enums\ApplicationStatus;
use App\Enums\ApplyAttemptKind;
use App\Enums\ApprovalStatus;
use App\Enums\DocsFailureCode;
use App\Enums\SiteIdentityState;
use App\Jobs\SubmitApplicationJob;
use App\Models\Application;
use App\Models\ApplyAttempt;
use App\Models\DocArtifact;
use App\Models\Listing;
use App\Models\MailboxIdentity;
use App\Models\Profile;
use App\Models\SiteIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan 2 — Mode B docs readiness gate after claim.
 */
class SubmitApplicationDocsGateTest extends TestCase
{
    use RefreshDatabase;

    private CountingModeBSubmitAfterDocsReady $afterDocsReady;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'joinera.queues.apply' => 'apply',
        ]);

        $this->afterDocsReady = new CountingModeBSubmitAfterDocsReady;
        $this->app->instance(ModeBSubmitAfterDocsReady::class, $this->afterDocsReady);
    }

    public function test_docs_failure_code_stops_without_calling_after_docs_hook(): void
    {
        $application = $this->makeClaimableApplication();
        $application->docs_failure_code = DocsFailureCode::CategoryUnknown->value;
        $application->save();
        $this->attachArtifacts($application, cv: true, cover: true);

        $this->runJob($application->id);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Applying, $application->status);

        $attempt = ApplyAttempt::query()->where('application_id', $application->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame(ApplyAttemptKind::HardFail, $attempt->kind);
        $this->assertSame(DocsFailureCode::CategoryUnknown->value, $attempt->stop_reason);
        $this->assertSame(0, $this->afterDocsReady->calls);
    }

    public function test_missing_cover_stops_without_calling_after_docs_hook(): void
    {
        $application = $this->makeClaimableApplication();
        $this->attachArtifacts($application, cv: true, cover: false);

        $this->runJob($application->id);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Applying, $application->status);

        $attempt = ApplyAttempt::query()->where('application_id', $application->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame(ApplyAttemptKind::HardFail, $attempt->kind);
        $this->assertSame('missing_artifacts', $attempt->stop_reason);
        $this->assertSame(0, $this->afterDocsReady->calls);
    }

    public function test_missing_cv_stops_without_calling_after_docs_hook(): void
    {
        $application = $this->makeClaimableApplication();
        $this->attachArtifacts($application, cv: false, cover: true);

        $this->runJob($application->id);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Applying, $application->status);

        $attempt = ApplyAttempt::query()->where('application_id', $application->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame(ApplyAttemptKind::HardFail, $attempt->kind);
        $this->assertSame('missing_artifacts', $attempt->stop_reason);
        $this->assertSame(0, $this->afterDocsReady->calls);
    }

    public function test_ready_docs_call_after_docs_hook_and_leave_attempt_open(): void
    {
        $application = $this->makeClaimableApplication();
        $this->attachArtifacts($application, cv: true, cover: true);

        $this->runJob($application->id);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Applying, $application->status);
        $this->assertNull($application->docs_failure_code);

        $attempt = ApplyAttempt::query()->where('application_id', $application->id)->first();
        $this->assertNotNull($attempt);
        $this->assertNull($attempt->kind);
        $this->assertNull($attempt->stop_reason);
        $this->assertSame(1, $this->afterDocsReady->calls);
    }

    private function runJob(int $applicationId): void
    {
        $this->app->call([new SubmitApplicationJob($applicationId), 'handle']);
    }

    private function attachArtifacts(Application $application, bool $cv, bool $cover): void
    {
        if ($cv) {
            DocArtifact::factory()->cv()->create(['application_id' => $application->id]);
        }
        if ($cover) {
            DocArtifact::factory()->cover()->create(['application_id' => $application->id]);
        }
    }

    private function makeClaimableApplication(): Application
    {
        $approver = User::factory()->staff()->create();
        $applicant = User::factory()->create();

        /** @var Profile $profile */
        $profile = $applicant->ensureProfile();
        $profile->approval = ApprovalStatus::Approved;
        $profile->approved_by = $approver->id;
        $profile->approved_at = now();
        $profile->save();

        MailboxIdentity::factory()->ready()->for($applicant)->create();

        $listing = Listing::factory()->forSource('ausbildung.de')->create([
            'url' => 'https://www.ausbildung.de/stellen/docs-gate',
            'apply_url' => 'https://karriere.firma.example.test/docs-gate/apply',
        ]);

        SiteIdentity::factory()
            ->for($applicant)
            ->healthy()
            ->create([
                'site' => $listing->source,
                'paused' => false,
                'state' => SiteIdentityState::Healthy,
            ]);

        return Application::factory()
            ->for($applicant)
            ->for($listing)
            ->create([
                'site' => $listing->source,
                'status' => ApplicationStatus::Applying,
                'docs_failure_code' => null,
            ]);
    }
}

final class CountingModeBSubmitAfterDocsReady implements ModeBSubmitAfterDocsReady
{
    public int $calls = 0;

    public function handle(Application $application, ApplyAttempt $attempt): void
    {
        $this->calls++;
    }
}
