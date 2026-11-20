<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ApplyAttemptKind;
use App\Enums\ApprovalStatus;
use App\Enums\MailboxState;
use App\Enums\SiteIdentityState;
use App\Jobs\SubmitApplicationJob;
use App\Models\Application;
use App\Models\ApplyAttempt;
use App\Models\Listing;
use App\Models\MailboxIdentity;
use App\Models\Profile;
use App\Models\SiteIdentity;
use App\Models\User;
use App\Services\ClaimApplyingApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Mode B exclusive claim — company-site path (plans 3–4).
 *
 * @see docs/COMPANY_SITE_APPLY_PLANS.md plans 3–4
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

    public function test_claim_succeeds_without_board_site_identity(): void
    {
        $application = $this->makeClaimableApplication(withSiteIdentity: false);

        $attempt = app(ClaimApplyingApplication::class)->handle($application->id);

        $this->assertNotNull($attempt);
        $this->assertNull($attempt->kind);
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

    public function test_unapproved_profile_writes_hard_fail_attempt(): void
    {
        $application = $this->makeClaimableApplication(approved: false);

        $this->runJob($application->id);

        $attempt = ApplyAttempt::query()->where('application_id', $application->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame(ApplyAttemptKind::HardFail, $attempt->kind);
        $this->assertSame('profile_not_approved', $attempt->stop_reason);
        $this->assertSame(ApplicationStatus::Applying, $application->fresh()->status);
    }

    public function test_mailbox_not_ready_writes_hard_fail_attempt(): void
    {
        $application = $this->makeClaimableApplication(mailboxReady: false);

        $this->runJob($application->id);

        $attempt = ApplyAttempt::query()->where('application_id', $application->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame(ApplyAttemptKind::HardFail, $attempt->kind);
        $this->assertSame('mailbox_not_ready', $attempt->stop_reason);
        $this->assertSame(ApplicationStatus::Applying, $application->fresh()->status);
    }

    public function test_board_only_apply_url_writes_hard_fail_attempt(): void
    {
        $application = $this->makeClaimableApplication(
            applyUrl: 'https://www.azubiyo.de/stellen/1/bewerben',
            listingUrl: 'https://www.azubiyo.de/stellen/1',
            source: 'azubiyo',
        );

        $this->runJob($application->id);

        $attempt = ApplyAttempt::query()->where('application_id', $application->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame(ApplyAttemptKind::HardFail, $attempt->kind);
        $this->assertSame('missing_company_apply_url', $attempt->stop_reason);
        $this->assertSame(ApplicationStatus::Applying, $application->fresh()->status);
    }

    public function test_paused_board_site_identity_does_not_block_company_claim(): void
    {
        $application = $this->makeClaimableApplication(sitePaused: true);

        $attempt = app(ClaimApplyingApplication::class)->handle($application->id);

        $this->assertNotNull($attempt);
        $this->assertNull($attempt->kind);
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
        bool $mailboxReady = true,
        string $source = 'azubiyo',
        string $listingUrl = 'https://www.azubiyo.de/stellen/1',
        string $applyUrl = 'https://karriere.firma.example.test/apply',
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

        if ($mailboxReady) {
            MailboxIdentity::factory()->ready()->for($applicant)->create();
        } else {
            MailboxIdentity::factory()->for($applicant)->create([
                'state' => MailboxState::Pending,
            ]);
        }

        $listing = Listing::factory()->forSource($source)->create([
            'url' => $listingUrl,
            'apply_url' => $applyUrl,
        ]);

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
