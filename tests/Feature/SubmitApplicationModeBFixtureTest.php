<?php

namespace Tests\Feature;

use App\Contracts\ModeBBrowserPort;
use App\Enums\ApplicationStatus;
use App\Enums\ApplyAttemptKind;
use App\Enums\ApprovalStatus;
use App\Enums\DocsFailureCode;
use App\Enums\ModeBBrowserOutcome;
use App\Enums\SiteIdentityState;
use App\Events\ApplicationStatusChanged;
use App\Jobs\RecordApplyChallengeAlert;
use App\Jobs\SubmitApplicationJob;
use App\Models\Application;
use App\Models\ApplyAttempt;
use App\Models\DocArtifact;
use App\Models\Listing;
use App\Models\MailboxIdentity;
use App\Models\Profile;
use App\Models\SiteIdentity;
use App\Models\User;
use App\Services\Apply\FixtureModeBBrowserAdapter;
use App\Services\Apply\ModeBBrowserResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Plan 3 — Mode B fixture submit after docs gate.
 *
 * @see docs/api/IMPLEMENTATION_PHASES.md P0-16
 */
class SubmitApplicationModeBFixtureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'joinera.queues.apply' => 'apply',
        ]);
    }

    public function test_fixture_confirmation_moves_application_to_submitted(): void
    {
        Event::fake([ApplicationStatusChanged::class]);

        $application = $this->makeReadyApplication();

        $this->runJob($application->id);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Submitted, $application->status);

        $attempt = ApplyAttempt::query()->where('application_id', $application->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame(ApplyAttemptKind::Success, $attempt->kind);
        $this->assertSame('fixture-'.$application->id, $attempt->confirmation_reference);

        Event::assertDispatched(ApplicationStatusChanged::class, function (ApplicationStatusChanged $event) use ($application): bool {
            return $event->applicationId === $application->id
                && $event->from === ApplicationStatus::Applying->value
                && $event->to === ApplicationStatus::Submitted->value;
        });
    }

    public function test_docs_failure_code_never_calls_browser_port(): void
    {
        $counter = new CountingModeBBrowserPort;
        $this->app->instance(ModeBBrowserPort::class, $counter);

        $application = $this->makeReadyApplication();
        $application->docs_failure_code = DocsFailureCode::CategoryUnknown->value;
        $application->save();

        $this->runJob($application->id);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Applying, $application->status);
        $this->assertSame(0, $counter->calls);

        $attempt = ApplyAttempt::query()->where('application_id', $application->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame(ApplyAttemptKind::HardFail, $attempt->kind);
    }

    public function test_identity_mismatch_does_not_submit(): void
    {
        $this->app->instance(ModeBBrowserPort::class, new FixedModeBBrowserPort(
            ModeBBrowserResult::identityMismatch('forced'),
        ));

        $application = $this->makeReadyApplication();
        $this->runJob($application->id);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Applying, $application->status);

        $attempt = ApplyAttempt::query()->where('application_id', $application->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame(ApplyAttemptKind::HardFail, $attempt->kind);
        $this->assertSame('forced', $attempt->stop_reason);
        $this->assertNull($attempt->confirmation_reference);
    }

    public function test_challenge_leaves_status_not_submitted(): void
    {
        Queue::fake([
            RecordApplyChallengeAlert::class,
        ]);

        $this->app->instance(ModeBBrowserPort::class, new FixedModeBBrowserPort(
            ModeBBrowserResult::challenge('captcha'),
        ));

        $application = $this->makeReadyApplication();
        $this->runJob($application->id);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Applying, $application->status);

        $attempt = ApplyAttempt::query()->where('application_id', $application->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame(ApplyAttemptKind::Challenge, $attempt->kind);
        $this->assertNull($attempt->confirmation_reference);
    }

    public function test_retryable_leaves_status_applying_and_records_attempt(): void
    {
        $this->app->instance(ModeBBrowserPort::class, new FixedModeBBrowserPort(
            ModeBBrowserResult::retryable('timeout'),
        ));

        $application = $this->makeReadyApplication();

        try {
            $this->runJob($application->id);
            $this->fail('Expected ModeBRetryableException');
        } catch (\App\Exceptions\ModeBRetryableException $e) {
            $this->assertSame('timeout', $e->getMessage());
        }

        $application->refresh();
        $this->assertSame(ApplicationStatus::Applying, $application->status);

        $attempt = ApplyAttempt::query()->where('application_id', $application->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame(ApplyAttemptKind::Retryable, $attempt->kind);
        $this->assertSame('timeout', $attempt->stop_reason);
        $this->assertNull($attempt->confirmation_reference);
    }

    public function test_retry_after_submitted_does_not_call_adapter_again(): void
    {
        $application = $this->makeReadyApplication();
        $this->runJob($application->id);
        $this->assertSame(ApplicationStatus::Submitted, $application->fresh()->status);

        $counter = new CountingModeBBrowserPort;
        $this->app->instance(ModeBBrowserPort::class, $counter);

        $this->runJob($application->id);

        $this->assertSame(0, $counter->calls);
        $this->assertSame(ApplicationStatus::Submitted, $application->fresh()->status);
        $this->assertSame(
            1,
            ApplyAttempt::query()->where('application_id', $application->id)->count(),
        );
    }

    public function test_per_job_approve_route_is_404(): void
    {
        $this->assertFalse(Route::has('applications.approve'));

        $application = $this->makeReadyApplication();
        $user = $application->user;

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/applications/'.$application->id.'/approve')
            ->assertNotFound();
    }

    public function test_fixture_adapter_refuses_foreign_user_id(): void
    {
        $application = $this->makeReadyApplication();
        $otherUser = User::factory()->create();

        $result = app(FixtureModeBBrowserAdapter::class)->submit(
            (int) $otherUser->id,
            (string) $application->site,
            (int) $application->id,
        );

        $this->assertSame(ModeBBrowserOutcome::IdentityMismatch, $result->outcome);
        $this->assertNull($result->confirmationReference);
    }

    private function runJob(int $applicationId): void
    {
        $this->app->call([new SubmitApplicationJob($applicationId), 'handle']);
    }

    private function makeReadyApplication(): Application
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
            'url' => 'https://www.ausbildung.de/stellen/mode-b-fixture',
            'apply_url' => 'https://karriere.firma.example.test/mode-b-fixture/apply',
        ]);

        SiteIdentity::factory()
            ->for($applicant)
            ->healthy()
            ->create([
                'site' => $listing->source,
                'paused' => false,
                'state' => SiteIdentityState::Healthy,
            ]);

        $application = Application::factory()
            ->for($applicant)
            ->for($listing)
            ->create([
                'site' => $listing->source,
                'status' => ApplicationStatus::Applying,
                'docs_failure_code' => null,
            ]);

        DocArtifact::factory()->cv()->create(['application_id' => $application->id]);
        DocArtifact::factory()->cover()->create(['application_id' => $application->id]);

        return $application;
    }
}

final class CountingModeBBrowserPort implements ModeBBrowserPort
{
    public int $calls = 0;

    public function submit(int $userId, string $siteId, int $applicationId): ModeBBrowserResult
    {
        $this->calls++;

        return ModeBBrowserResult::success('counting-'.$applicationId);
    }
}

final class FixedModeBBrowserPort implements ModeBBrowserPort
{
    public function __construct(
        private readonly ModeBBrowserResult $result,
    ) {}

    public function submit(int $userId, string $siteId, int $applicationId): ModeBBrowserResult
    {
        return $this->result;
    }
}
