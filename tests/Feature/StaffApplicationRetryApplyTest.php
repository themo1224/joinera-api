<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ApplyAttemptKind;
use App\Enums\ApprovalStatus;
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
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StaffApplicationRetryApplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'joinera.queues.apply' => 'apply',
        ]);
    }

    public function test_staff_can_retry_apply_and_job_is_pushed(): void
    {
        Queue::fake([SubmitApplicationJob::class]);

        $staff = User::factory()->staff()->create();
        $application = $this->makeRetryableApplication();

        ApplyAttempt::factory()->create([
            'application_id' => $application->id,
            'kind' => ApplyAttemptKind::Retryable,
            'stop_reason' => 'login_timeout',
        ]);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/applications/'.$application->id.'/retry-apply')
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('applicationId', $application->id);

        Queue::assertPushedOn('apply', SubmitApplicationJob::class, function (SubmitApplicationJob $job) use ($application): bool {
            return $job->applicationId === $application->id;
        });
    }

    public function test_retry_apply_unpauses_site_identity(): void
    {
        Queue::fake([SubmitApplicationJob::class]);

        $staff = User::factory()->staff()->create();
        $application = $this->makeRetryableApplication(sitePaused: true);

        $identity = SiteIdentity::query()
            ->where('user_id', $application->user_id)
            ->where('site', $application->site)
            ->firstOrFail();
        $this->assertTrue($identity->paused);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/applications/'.$application->id.'/retry-apply')
            ->assertStatus(202);

        $this->assertFalse($identity->fresh()->paused);
        Queue::assertPushed(SubmitApplicationJob::class);
    }

    public function test_candidate_cannot_retry_apply(): void
    {
        Queue::fake([SubmitApplicationJob::class]);

        $candidate = User::factory()->create();
        $application = $this->makeRetryableApplication(owner: $candidate);

        $this->actingAs($candidate, 'sanctum')
            ->postJson('/api/v1/applications/'.$application->id.'/retry-apply')
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_submitted_returns_409(): void
    {
        Queue::fake([SubmitApplicationJob::class]);

        $staff = User::factory()->staff()->create();
        $application = $this->makeRetryableApplication(status: ApplicationStatus::Submitted);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/applications/'.$application->id.'/retry-apply')
            ->assertStatus(409)
            ->assertJsonPath('code', 'application_already_submitted');

        Queue::assertNothingPushed();
    }

    public function test_wrong_status_returns_409(): void
    {
        Queue::fake([SubmitApplicationJob::class]);

        $staff = User::factory()->staff()->create();
        $application = $this->makeRetryableApplication(status: ApplicationStatus::Queued);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/applications/'.$application->id.'/retry-apply')
            ->assertStatus(409)
            ->assertJsonPath('code', 'retry_apply_wrong_status');

        Queue::assertNothingPushed();
    }

    public function test_open_attempt_returns_409(): void
    {
        Queue::fake([SubmitApplicationJob::class]);

        $staff = User::factory()->staff()->create();
        $application = $this->makeRetryableApplication();

        ApplyAttempt::factory()->create([
            'application_id' => $application->id,
            'kind' => null,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/applications/'.$application->id.'/retry-apply')
            ->assertStatus(409)
            ->assertJsonPath('code', 'apply_in_progress');

        Queue::assertNothingPushed();
    }

    public function test_missing_docs_returns_409(): void
    {
        Queue::fake([SubmitApplicationJob::class]);

        $staff = User::factory()->staff()->create();
        $application = $this->makeRetryableApplication(withDocs: false);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/applications/'.$application->id.'/retry-apply')
            ->assertStatus(409)
            ->assertJsonPath('code', 'docs_not_ready');

        Queue::assertNothingPushed();
    }

    public function test_unapproved_profile_returns_409(): void
    {
        Queue::fake([SubmitApplicationJob::class]);

        $staff = User::factory()->staff()->create();
        $application = $this->makeRetryableApplication(approved: false);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/applications/'.$application->id.'/retry-apply')
            ->assertStatus(409)
            ->assertJsonPath('code', 'profile_not_approved');

        Queue::assertNothingPushed();
    }

    public function test_company_site_retry_without_site_identity_is_allowed(): void
    {
        Queue::fake([SubmitApplicationJob::class]);

        $staff = User::factory()->staff()->create();
        // Plan 29 — company apply_url does not need board SiteIdentity.
        $application = $this->makeRetryableApplication(withSiteIdentity: false);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/applications/'.$application->id.'/retry-apply')
            ->assertStatus(202)
            ->assertJsonPath('success', true);

        Queue::assertPushed(SubmitApplicationJob::class);
    }

    public function test_board_only_retry_without_site_identity_returns_409(): void
    {
        Queue::fake([SubmitApplicationJob::class]);

        $staff = User::factory()->staff()->create();
        $application = $this->makeRetryableApplication(
            withSiteIdentity: false,
            boardOnlyApplyUrl: true,
        );

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/applications/'.$application->id.'/retry-apply')
            ->assertStatus(409)
            ->assertJsonPath('code', 'site_not_ready');

        Queue::assertNothingPushed();
    }

    private function makeRetryableApplication(
        ?User $owner = null,
        ApplicationStatus $status = ApplicationStatus::Applying,
        bool $approved = true,
        bool $withSiteIdentity = true,
        bool $sitePaused = false,
        bool $withDocs = true,
        bool $boardOnlyApplyUrl = false,
    ): Application {
        $approver = User::factory()->staff()->create();
        $applicant = $owner ?? User::factory()->create();

        /** @var Profile $profile */
        $profile = $applicant->ensureProfile();
        $profile->fill([
            'industry' => ['IT'],
            'language' => 'de',
            'education' => 'Abitur',
            'desired_path' => 'ausbildung',
        ]);
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

        MailboxIdentity::factory()->ready()->for($applicant)->create();

        $listing = Listing::factory()->forSource('azubiyo')->create([
            'url' => 'https://www.azubiyo.de/stellen/retry',
            'apply_url' => $boardOnlyApplyUrl
                ? 'https://www.azubiyo.de/stellen/retry/bewerben'
                : 'https://karriere.firma.example.test/retry/apply',
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

        $application = Application::factory()
            ->for($applicant)
            ->for($listing)
            ->create([
                'site' => $listing->source,
                'status' => $status,
                'docs_failure_code' => null,
            ]);

        if ($withDocs) {
            DocArtifact::factory()->cv()->create(['application_id' => $application->id]);
            DocArtifact::factory()->cover()->create(['application_id' => $application->id]);
        }

        return $application;
    }
}
