<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ApprovalStatus;
use App\Enums\SiteIdentityState;
use App\Jobs\SubmitApplicationJob;
use App\Models\Application;
use App\Models\ApplyAttempt;
use App\Models\Listing;
use App\Models\Profile;
use App\Models\SiteIdentity;
use App\Models\User;
use App\Services\ClaimApplyingApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Plan 1 — Mode B exclusive claim of an applying Application into an open ApplyAttempt.
 *
 * @see docs/api/IMPLEMENTATION_PHASES.md P0-15 (adapted: claim from applying)
 */
class SubmitApplicationClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'joinera.queues.apply' => 'apply',
        ]);
    }

    public function test_job_is_queued_on_apply(): void
    {
        $job = new SubmitApplicationJob(42);

        $this->assertSame('apply', $job->queue);
    }

    public function test_claim_creates_one_open_attempt_and_keeps_applying(): void
    {
        $application = $this->makeClaimableApplication();

        $attempt = app(ClaimApplyingApplication::class)->handle($application->id);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Applying, $application->status);
        $this->assertNotNull($attempt);
        $this->assertNull($attempt->kind);
        $this->assertNull($attempt->confirmation_reference);
        $this->assertSame(1, ApplyAttempt::query()->where('application_id', $application->id)->count());
    }

    public function test_concurrent_claims_create_only_one_open_attempt(): void
    {
        $application = $this->makeClaimableApplication();
        $claim = app(ClaimApplyingApplication::class);

        $first = $claim->handle($application->id);
        $second = $claim->handle($application->id);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ApplyAttempt::query()->where('application_id', $application->id)->count());
        $this->assertSame(1, Application::query()->whereKey($application->id)->count());
    }

    public function test_submitted_application_adds_no_attempt(): void
    {
        $application = $this->makeClaimableApplication(status: ApplicationStatus::Submitted);

        $this->runJob($application->id);

        $this->assertSame(0, ApplyAttempt::query()->where('application_id', $application->id)->count());
        $this->assertSame(ApplicationStatus::Submitted, $application->fresh()->status);
    }

    public function test_unapproved_profile_adds_no_attempt(): void
    {
        $application = $this->makeClaimableApplication(approved: false);

        $this->runJob($application->id);

        $this->assertSame(0, ApplyAttempt::query()->where('application_id', $application->id)->count());
        $this->assertSame(ApplicationStatus::Applying, $application->fresh()->status);
    }

    public function test_paused_site_identity_adds_no_attempt(): void
    {
        $application = $this->makeClaimableApplication(sitePaused: true);

        $this->runJob($application->id);

        $this->assertSame(0, ApplyAttempt::query()->where('application_id', $application->id)->count());
        $this->assertSame(ApplicationStatus::Applying, $application->fresh()->status);
    }

    public function test_missing_site_identity_adds_no_attempt(): void
    {
        $application = $this->makeClaimableApplication(withSiteIdentity: false);

        $this->runJob($application->id);

        $this->assertSame(0, ApplyAttempt::query()->where('application_id', $application->id)->count());
        $this->assertSame(ApplicationStatus::Applying, $application->fresh()->status);
    }

    public function test_job_can_be_dispatched_onto_apply_queue(): void
    {
        Queue::fake([SubmitApplicationJob::class]);

        $application = $this->makeClaimableApplication();
        SubmitApplicationJob::dispatch($application->id);

        Queue::assertPushedOn('apply', SubmitApplicationJob::class);
    }

    public function test_per_job_approve_route_is_404(): void
    {
        $this->assertFalse(Route::has('applications.approve'));

        $user = User::factory()->create();
        $application = $this->makeClaimableApplication(owner: $user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/applications/'.$application->id.'/approve')
            ->assertNotFound();
    }

    private function runJob(int $applicationId): void
    {
        $this->app->call([new SubmitApplicationJob($applicationId), 'handle']);
    }

    private function makeClaimableApplication(
        ?User $owner = null,
        ApplicationStatus $status = ApplicationStatus::Applying,
        bool $approved = true,
        bool $withSiteIdentity = true,
        bool $sitePaused = false,
    ): Application {
        $approver = User::factory()->staff()->create();
        $applicant = $owner ?? User::factory()->create();

        /** @var Profile $profile */
        $profile = $applicant->ensureProfile();
        if ($approved) {
            $profile->approval = ApprovalStatus::Approved;
            $profile->approved_by = $approver->id;
            $profile->approved_at = now();
        } else {
            $profile->approval = ApprovalStatus::Pending;
            $profile->approved_by = null;
            $profile->approved_at = null;
        }
        $profile->save();

        $listing = Listing::factory()->forSource('ausbildung.de')->create();

        if ($withSiteIdentity) {
            SiteIdentity::factory()
                ->for($applicant)
                ->healthy()
                ->create([
                    'site' => $listing->source,
                    'paused' => $sitePaused,
                    'state' => SiteIdentityState::Healthy,
                ]);
        }

        return Application::factory()
            ->for($applicant)
            ->for($listing)
            ->create([
                'site' => $listing->source,
                'status' => $status,
            ]);
    }
}
