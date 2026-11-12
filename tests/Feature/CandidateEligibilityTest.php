<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\MailboxState;
use App\Models\CvAsset;
use App\Models\MailboxIdentity;
use App\Models\Profile;
use App\Models\User;
use App\Services\ResolveCandidateEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CandidateEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'joinera.mailbox.mode' => 'gmx_manual',
            'joinera.mailbox.driver' => 'fake',
            'joinera.mailbox.allowed_hosts' => ['gmx.de', 'gmx.net'],
            'joinera.sites.v1' => ['stepstone'],
        ]);
    }

    public function test_all_three_gates_pass_is_eligible_and_directory_exposes_flags(): void
    {
        $staff = User::factory()->staff()->create();
        $applicant = $this->createEligibleApplicant($staff);

        $result = app(ResolveCandidateEligibility::class)->handle($applicant->fresh());

        $this->assertTrue($result['eligible']);
        $this->assertSame([], $result['reasons']);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applicants/'.$applicant->id)
            ->assertOk()
            ->assertJsonPath('matchEligible', true)
            ->assertJsonPath('matchEligibilityReasons', []);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applicants')
            ->assertOk()
            ->assertJsonPath('users.0.matchEligible', true)
            ->assertJsonPath('users.0.matchEligibilityReasons', []);
    }

    public function test_pending_approval_is_not_eligible(): void
    {
        $staff = User::factory()->staff()->create();
        $applicant = $this->createEligibleApplicant($staff, approved: false);

        $result = app(ResolveCandidateEligibility::class)->handle($applicant->fresh());

        $this->assertFalse($result['eligible']);
        $this->assertSame(['profile_not_approved'], $result['reasons']);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applicants/'.$applicant->id)
            ->assertOk()
            ->assertJsonPath('matchEligible', false)
            ->assertJsonPath('matchEligibilityReasons', ['profile_not_approved']);
    }

    public function test_incomplete_profile_is_not_eligible(): void
    {
        $staff = User::factory()->staff()->create();
        $applicant = $this->createEligibleApplicant($staff, complete: false);

        $result = app(ResolveCandidateEligibility::class)->handle($applicant->fresh());

        $this->assertFalse($result['eligible']);
        $this->assertSame(['profile_incomplete'], $result['reasons']);
    }

    public function test_missing_cv_is_not_eligible(): void
    {
        $staff = User::factory()->staff()->create();
        $applicant = $this->createEligibleApplicant($staff, withCv: false);

        $result = app(ResolveCandidateEligibility::class)->handle($applicant->fresh());

        $this->assertFalse($result['eligible']);
        $this->assertSame(['profile_incomplete'], $result['reasons']);
    }

    public function test_mailbox_not_ready_is_not_eligible(): void
    {
        $staff = User::factory()->staff()->create();
        $applicant = $this->createEligibleApplicant($staff, mailboxState: MailboxState::Pending);

        $result = app(ResolveCandidateEligibility::class)->handle($applicant->fresh());

        $this->assertFalse($result['eligible']);
        $this->assertSame(['mailbox_not_ready'], $result['reasons']);
    }

    public function test_missing_mailbox_is_not_eligible(): void
    {
        $staff = User::factory()->staff()->create();
        $applicant = $this->createEligibleApplicant($staff, withMailbox: false);

        $result = app(ResolveCandidateEligibility::class)->handle($applicant->fresh());

        $this->assertFalse($result['eligible']);
        $this->assertSame(['mailbox_not_ready'], $result['reasons']);
    }

    public function test_failed_mailbox_is_not_eligible(): void
    {
        $staff = User::factory()->staff()->create();
        $applicant = $this->createEligibleApplicant($staff, mailboxState: MailboxState::Failed);

        $result = app(ResolveCandidateEligibility::class)->handle($applicant->fresh());

        $this->assertFalse($result['eligible']);
        $this->assertSame(['mailbox_not_ready'], $result['reasons']);
    }

    public function test_multiple_failures_return_all_reason_codes(): void
    {
        $applicant = User::factory()->create(['name' => 'Not Ready Yet']);
        $applicant->ensureProfile();

        $result = app(ResolveCandidateEligibility::class)->handle($applicant->fresh());

        $this->assertFalse($result['eligible']);
        $this->assertSame(
            ['mailbox_not_ready', 'profile_incomplete', 'profile_not_approved'],
            $result['reasons'],
        );

        $staff = User::factory()->staff()->create();
        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applicants/'.$applicant->id)
            ->assertOk()
            ->assertJsonPath('matchEligible', false)
            ->assertJsonPath('matchEligibilityReasons', [
                'mailbox_not_ready',
                'profile_incomplete',
                'profile_not_approved',
            ]);
    }

    private function createEligibleApplicant(
        User $approver,
        bool $approved = true,
        bool $complete = true,
        bool $withCv = true,
        bool $withMailbox = true,
        MailboxState $mailboxState = MailboxState::Ready,
    ): User {
        $applicant = User::factory()->create(['name' => 'Eligible Candidate']);

        /** @var Profile $profile */
        $profile = $applicant->ensureProfile();
        $profile->fill([
            'industry' => $complete ? 'IT' : null,
            'city' => $complete ? 'Berlin' : null,
            'province' => $complete ? 'BE' : null,
            'language' => $complete ? 'de' : null,
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

        if ($withCv) {
            CvAsset::factory()->for($applicant)->create();
        }

        if ($withMailbox) {
            $factory = MailboxIdentity::factory()->for($applicant);
            $factory = match ($mailboxState) {
                MailboxState::Ready => $factory->ready(),
                MailboxState::Failed => $factory->failed(),
                MailboxState::Pending => $factory->pending(),
            };
            $factory->create([
                'domain' => 'gmx.de',
                'address' => 'eligible.candidate@gmx.de',
                'state' => $mailboxState,
            ]);
        }

        return $applicant->fresh(['profile', 'cvAsset', 'mailboxIdentity']) ?? $applicant;
    }
}
