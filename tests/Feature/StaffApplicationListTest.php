<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\DocsFailureCode;
use App\Enums\MatchHardSkipReason;
use App\Models\Application;
use App\Models\CandidateListingMatch;
use App\Models\DocArtifact;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffApplicationListTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_lists_applications_with_ops_payload(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create([
            'name' => 'Ops Applicant',
            'email' => 'ops.applicant@example.test',
        ]);
        $listing = Listing::factory()->forSource('azubiyo')->create([
            'title' => 'Nursing Ausbildung',
            'city' => 'Munich',
        ]);
        $match = CandidateListingMatch::factory()
            ->for($candidate)
            ->for($listing)
            ->queued()
            ->create();

        $application = Application::factory()
            ->for($candidate)
            ->for($listing)
            ->forMatch($match)
            ->preparingDocs()
            ->create([
                'docs_failure_code' => DocsFailureCode::LlmFailed->value,
            ]);

        DocArtifact::factory()->cv()->create(['application_id' => $application->id]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applications')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('offset', 0)
            ->assertJsonPath('limit', 20)
            ->assertJsonPath('applications.0.id', $application->id)
            ->assertJsonPath('applications.0.status', 'preparing_docs')
            ->assertJsonPath('applications.0.site', 'azubiyo')
            ->assertJsonPath('applications.0.userId', $candidate->id)
            ->assertJsonPath('applications.0.userName', 'Ops Applicant')
            ->assertJsonPath('applications.0.userEmail', 'ops.applicant@example.test')
            ->assertJsonPath('applications.0.listingId', $listing->id)
            ->assertJsonPath('applications.0.listingTitle', 'Nursing Ausbildung')
            ->assertJsonPath('applications.0.listingSource', 'azubiyo')
            ->assertJsonPath('applications.0.listingCity', 'Munich')
            ->assertJsonPath('applications.0.candidateListingMatchId', $match->id)
            ->assertJsonPath('applications.0.matchStatus', 'queued')
            ->assertJsonPath('applications.0.hardSkipReason', null)
            ->assertJsonPath('applications.0.docsFailureCode', DocsFailureCode::LlmFailed->value)
            ->assertJsonPath('applications.0.hasCv', true)
            ->assertJsonPath('applications.0.hasCover', false)
            ->assertJsonPath('statusCounts.preparing_docs', 1)
            ->assertJsonStructure(['applications' => [['updatedAt']], 'statusCounts']);
    }

    public function test_list_includes_status_counts_across_pages(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create();

        Application::factory()->for($candidate)->count(2)->preparingDocs()->create();
        Application::factory()->for($candidate)->applying()->create();
        Application::factory()->for($candidate)->queued()->create();

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applications?page=1&limit=2')
            ->assertOk()
            ->assertJsonPath('total', 4)
            ->assertJsonPath('statusCounts.preparing_docs', 2)
            ->assertJsonPath('statusCounts.applying', 1)
            ->assertJsonPath('statusCounts.queued', 1)
            ->assertJsonCount(2, 'applications');
    }

    public function test_admin_can_list_applications(): void
    {
        $admin = User::factory()->admin()->create();
        Application::factory()->queued()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/applications')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_list_filters_by_comma_separated_status(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create();

        Application::factory()->for($candidate)->queued()->create();
        Application::factory()->for($candidate)->preparingDocs()->create();
        Application::factory()->for($candidate)->applying()->create();

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applications?status=queued,preparing_docs')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonCount(2, 'applications');

        $statuses = collect($this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applications?status=queued,preparing_docs')
            ->json('applications.*.status'))
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['preparing_docs', 'queued'], $statuses);
    }

    public function test_list_filters_by_user_id_site_and_search(): void
    {
        $staff = User::factory()->staff()->create();
        $one = User::factory()->create([
            'name' => 'Alpha Candidate',
            'email' => 'alpha@example.test',
        ]);
        $two = User::factory()->create([
            'name' => 'Beta Candidate',
            'email' => 'beta@example.test',
        ]);

        $listingOne = Listing::factory()->forSource('azubiyo')->create([
            'title' => 'Berlin Nursing Role',
        ]);
        $listingTwo = Listing::factory()->forSource('ausbildung.de')->create([
            'title' => 'Hamburg IT Role',
        ]);

        Application::factory()->for($one)->for($listingOne)->queued()->create([
            'site' => 'azubiyo',
        ]);
        Application::factory()->for($two)->for($listingTwo)->queued()->create([
            'site' => 'ausbildung.de',
        ]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applications?userId='.$one->id)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('applications.0.userId', $one->id);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applications?site=azubiyo')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('applications.0.site', 'azubiyo');

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applications?search=Alpha')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('applications.0.userName', 'Alpha Candidate');

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applications?search=Hamburg')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('applications.0.listingTitle', 'Hamburg IT Role');

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applications?search=beta@example.test')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('applications.0.userEmail', 'beta@example.test');

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applications?search=azubiyo')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('applications.0.site', 'azubiyo');

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applications?search='.$one->id)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('applications.0.userId', $one->id);
    }

    public function test_list_rejects_invalid_status(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applications?status=not_a_status')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed');
    }

    public function test_list_includes_hard_skip_reason_from_linked_match(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create();
        $listing = Listing::factory()->create();
        $match = CandidateListingMatch::factory()
            ->for($candidate)
            ->for($listing)
            ->hardSkip(MatchHardSkipReason::Industry)
            ->create();

        Application::factory()
            ->for($candidate)
            ->for($listing)
            ->forMatch($match)
            ->matched()
            ->create();

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applications')
            ->assertOk()
            ->assertJsonPath('applications.0.matchStatus', 'skipped')
            ->assertJsonPath('applications.0.hardSkipReason', 'industry');
    }

    public function test_user_role_cannot_list_applications(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/applications')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_guest_cannot_list_applications(): void
    {
        $this->getJson('/api/v1/applications')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_browser_style_guest_get_returns_json_401_not_login_redirect(): void
    {
        $this->get('/api/v1/applications?userId=10', [
            'Accept' => 'text/html',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_list_paginates_with_page_and_limit(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create();

        Application::factory()->for($candidate)->count(3)->create([
            'status' => ApplicationStatus::Queued,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applications?page=2&limit=2')
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('offset', 2)
            ->assertJsonPath('limit', 2)
            ->assertJsonCount(1, 'applications');
    }
}
