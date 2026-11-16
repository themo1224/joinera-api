<?php

namespace Tests\Feature;

use App\Enums\ApplyAttemptKind;
use App\Enums\DocArtifactKind;
use App\Enums\DocsFailureCode;
use App\Enums\MatchHardSkipReason;
use App\Models\Application;
use App\Models\ApplyAttempt;
use App\Models\CandidateListingMatch;
use App\Models\DocArtifact;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffApplicationShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_shows_application_with_ops_payload(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create([
            'name' => 'Show Applicant',
            'email' => 'show.applicant@example.test',
        ]);
        $listing = Listing::factory()->forSource('ausbildung.de')->create([
            'title' => 'IT Ausbildung',
            'city' => 'Berlin',
        ]);
        $match = CandidateListingMatch::factory()
            ->for($candidate)
            ->for($listing)
            ->hardSkip(MatchHardSkipReason::NotGermany)
            ->create();

        $application = Application::factory()
            ->for($candidate)
            ->for($listing)
            ->forMatch($match)
            ->preparingDocs()
            ->create([
                'docs_failure_code' => DocsFailureCode::CategoryUnknown->value,
            ]);

        DocArtifact::factory()->cv()->create(['application_id' => $application->id]);
        DocArtifact::factory()->cover()->create(['application_id' => $application->id]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applications/'.$application->id)
            ->assertOk()
            ->assertJsonPath('id', $application->id)
            ->assertJsonPath('status', 'preparing_docs')
            ->assertJsonPath('site', 'ausbildung.de')
            ->assertJsonPath('userId', $candidate->id)
            ->assertJsonPath('userName', 'Show Applicant')
            ->assertJsonPath('userEmail', 'show.applicant@example.test')
            ->assertJsonPath('listingId', $listing->id)
            ->assertJsonPath('listingTitle', 'IT Ausbildung')
            ->assertJsonPath('listingSource', 'ausbildung.de')
            ->assertJsonPath('listingCity', 'Berlin')
            ->assertJsonPath('candidateListingMatchId', $match->id)
            ->assertJsonPath('matchStatus', 'skipped')
            ->assertJsonPath('hardSkipReason', 'not_germany')
            ->assertJsonPath('docsFailureCode', DocsFailureCode::CategoryUnknown->value)
            ->assertJsonPath('hasCv', true)
            ->assertJsonPath('hasCover', true)
            ->assertJsonPath('attempts', [])
            ->assertJsonStructure(['updatedAt', 'attempts']);
    }

    public function test_show_includes_capped_attempts_newest_first(): void
    {
        $staff = User::factory()->staff()->create();
        $application = Application::factory()->applying()->create();

        ApplyAttempt::factory()->retryable('old')->create([
            'application_id' => $application->id,
            'created_at' => now()->subMinutes(5),
        ]);
        ApplyAttempt::factory()->hardFail('identity_mismatch')->create([
            'application_id' => $application->id,
            'created_at' => now()->subMinute(),
        ]);
        ApplyAttempt::factory()->challenge('captcha')->create([
            'application_id' => $application->id,
            'created_at' => now(),
        ]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applications/'.$application->id)
            ->assertOk()
            ->assertJsonCount(3, 'attempts')
            ->assertJsonPath('attempts.0.kind', ApplyAttemptKind::Challenge->value)
            ->assertJsonPath('attempts.0.stopReason', 'captcha')
            ->assertJsonPath('attempts.1.kind', ApplyAttemptKind::HardFail->value)
            ->assertJsonPath('attempts.2.kind', ApplyAttemptKind::Retryable->value)
            ->assertJsonStructure([
                'attempts' => [['kind', 'stopReason', 'createdAt']],
            ]);
    }

    public function test_admin_can_show_application(): void
    {
        $admin = User::factory()->admin()->create();
        $application = Application::factory()->queued()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/applications/'.$application->id)
            ->assertOk()
            ->assertJsonPath('id', $application->id)
            ->assertJsonPath('status', 'queued');
    }

    public function test_show_missing_application_is_404(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applications/999999')
            ->assertNotFound();
    }

    public function test_user_role_cannot_show_application(): void
    {
        $candidate = User::factory()->create();
        $application = Application::factory()->for($candidate)->queued()->create();

        $this->actingAs($candidate, 'sanctum')
            ->getJson('/api/v1/applications/'.$application->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_guest_cannot_show_application(): void
    {
        $application = Application::factory()->queued()->create();

        $this->getJson('/api/v1/applications/'.$application->id)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_show_reports_docs_flags_and_failure_code(): void
    {
        $staff = User::factory()->staff()->create();
        $application = Application::factory()->applying()->create([
            'docs_failure_code' => DocsFailureCode::MissingBaseCv->value,
        ]);

        DocArtifact::factory()->create([
            'application_id' => $application->id,
            'kind' => DocArtifactKind::Cover,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applications/'.$application->id)
            ->assertOk()
            ->assertJsonPath('status', 'applying')
            ->assertJsonPath('docsFailureCode', DocsFailureCode::MissingBaseCv->value)
            ->assertJsonPath('hasCv', false)
            ->assertJsonPath('hasCover', true);
    }
}
