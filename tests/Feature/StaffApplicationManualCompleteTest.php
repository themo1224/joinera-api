<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ApprovalStatus;
use App\Enums\SiteIdentityState;
use App\Events\ApplicationStatusChanged;
use App\Models\Application;
use App\Models\ApplyAttempt;
use App\Models\BlockEvent;
use App\Models\DocArtifact;
use App\Models\Listing;
use App\Models\MailboxIdentity;
use App\Models\Profile;
use App\Models\SiteIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class StaffApplicationManualCompleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_manual_complete_applying_with_open_block_event(): void
    {
        Event::fake([ApplicationStatusChanged::class]);

        $staff = User::factory()->staff()->create();
        $application = $this->makeApplyingApplication(sitePaused: true);

        BlockEvent::factory()->create([
            'user_id' => $application->user_id,
            'application_id' => $application->id,
            'site' => $application->site,
            'observed_kind' => BlockEvent::KIND_CAPTCHA,
            'resolved_at' => null,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/applications/'.$application->id.'/manual-complete')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('alreadySubmitted', false)
            ->assertJsonPath('application.status', 'submitted')
            ->assertJsonPath('application.manuallyCompletedById', $staff->id)
            ->assertJsonPath('application.blockPaused', false);

        $fresh = $application->fresh();
        $this->assertSame(ApplicationStatus::Submitted, $fresh->status);
        $this->assertNotNull($fresh->manually_completed_at);
        $this->assertSame($staff->id, $fresh->manually_completed_by);
        $this->assertSame(
            0,
            BlockEvent::query()->where('application_id', $application->id)->whereNull('resolved_at')->count(),
        );

        $identity = SiteIdentity::query()
            ->where('user_id', $application->user_id)
            ->where('site', $application->site)
            ->firstOrFail();
        $this->assertFalse($identity->paused);

        Event::assertDispatched(ApplicationStatusChanged::class, function (ApplicationStatusChanged $event) use ($application): bool {
            return $event->applicationId === $application->id
                && $event->from === ApplicationStatus::Applying->value
                && $event->to === ApplicationStatus::Submitted->value;
        });

        $this->assertNotNull(MailboxIdentity::query()->where('user_id', $application->user_id)->first());
    }

    public function test_staff_can_manual_complete_applying_with_hard_fail_attempt(): void
    {
        $staff = User::factory()->staff()->create();
        $application = $this->makeApplyingApplication();

        ApplyAttempt::factory()->hardFail()->create([
            'application_id' => $application->id,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/applications/'.$application->id.'/manual-complete')
            ->assertOk()
            ->assertJsonPath('application.status', 'submitted')
            ->assertJsonPath('application.lastAttemptKind', 'hard_fail');
    }

    public function test_manual_complete_is_idempotent_when_already_submitted(): void
    {
        $staff = User::factory()->staff()->create();
        $application = $this->makeApplyingApplication();
        ApplyAttempt::factory()->hardFail()->create([
            'application_id' => $application->id,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/applications/'.$application->id.'/manual-complete')
            ->assertOk();

        $completedAt = $application->fresh()->manually_completed_at;

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/applications/'.$application->id.'/manual-complete')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('alreadySubmitted', true)
            ->assertJsonPath('application.status', 'submitted');

        $this->assertTrue(
            $completedAt?->equalTo($application->fresh()->manually_completed_at) ?? false,
        );
    }

    public function test_manual_complete_rejects_applying_without_block_or_hard_fail(): void
    {
        $staff = User::factory()->staff()->create();
        $application = $this->makeApplyingApplication();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/applications/'.$application->id.'/manual-complete')
            ->assertStatus(409)
            ->assertJsonPath('code', 'not_eligible');

        $this->assertSame(ApplicationStatus::Applying, $application->fresh()->status);
    }

    public function test_candidate_cannot_manual_complete(): void
    {
        $application = $this->makeApplyingApplication();
        ApplyAttempt::factory()->hardFail()->create([
            'application_id' => $application->id,
        ]);
        $candidate = $application->user;

        $this->actingAs($candidate, 'sanctum')
            ->postJson('/api/v1/applications/'.$application->id.'/manual-complete')
            ->assertForbidden();
    }

    public function test_show_includes_block_paused_and_manual_complete_fields(): void
    {
        $staff = User::factory()->staff()->create();
        $application = $this->makeApplyingApplication();

        BlockEvent::factory()->create([
            'user_id' => $application->user_id,
            'application_id' => $application->id,
            'site' => $application->site,
            'observed_kind' => BlockEvent::KIND_CAPTCHA,
            'resolved_at' => null,
        ]);
        ApplyAttempt::factory()->challenge()->create([
            'application_id' => $application->id,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applications/'.$application->id)
            ->assertOk()
            ->assertJsonPath('blockPaused', true)
            ->assertJsonPath('lastAttemptKind', 'challenge')
            ->assertJsonPath('manuallyCompletedAt', null)
            ->assertJsonPath('manuallyCompletedById', null);
    }

    private function makeApplyingApplication(bool $sitePaused = false): Application
    {
        $approver = User::factory()->staff()->create();
        $applicant = User::factory()->create();

        /** @var Profile $profile */
        $profile = $applicant->ensureProfile();
        $profile->fill([
            'industry' => ['IT'],
            'language' => 'de',
            'education' => 'Abitur',
            'desired_path' => 'ausbildung',
        ]);
        $profile->approval = ApprovalStatus::Approved;
        $profile->approved_by = $approver->id;
        $profile->approved_at = now();
        $profile->save();

        MailboxIdentity::factory()->ready()->for($applicant)->create();

        $listing = Listing::factory()->forSource('azubiyo')->create([
            'url' => 'https://www.azubiyo.de/stellen/manual',
            'apply_url' => 'https://karriere.firma.example.test/manual/apply',
        ]);

        SiteIdentity::factory()
            ->for($applicant)
            ->healthy()
            ->create([
                'site' => $listing->source,
                'paused' => $sitePaused,
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
