<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\MatchHardSkipReason;
use App\Enums\MatchStatus;
use App\Models\Application;
use App\Models\CandidateListingMatch;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffForceQueueMatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_force_queues_skipped_match_and_creates_queued_application(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create(['name' => 'Force Queue Candidate']);
        $listing = Listing::factory()->forSource('ausbildung.de')->create([
            'title' => 'IT Ausbildung Berlin',
            'city' => 'Berlin',
            'country' => 'DE',
            'category' => 'IT',
        ]);

        $match = CandidateListingMatch::factory()
            ->for($candidate)
            ->for($listing)
            ->hardSkip(MatchHardSkipReason::Industry)
            ->withSoftSignals([
                'language_level' => [
                    'mismatch' => true,
                    'reason' => 'language_code_mismatch',
                ],
            ])
            ->create();

        $response = $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/matches/'.$match->id.'/force-queue')
            ->assertOk()
            ->assertJsonPath('match.status', 'queued')
            ->assertJsonPath('match.hardSkipReason', null)
            ->assertJsonPath('match.forceQueuedBy', $staff->id)
            ->assertJsonPath('match.softSignals.language_level.reason', 'language_code_mismatch')
            ->assertJsonPath('match.listingSource', 'ausbildung.de')
            ->assertJsonPath('application.status', 'queued')
            ->assertJsonPath('application.site', 'ausbildung.de')
            ->assertJsonPath('application.userId', $candidate->id)
            ->assertJsonPath('application.listingId', $listing->id);

        $match->refresh();
        $this->assertSame(MatchStatus::Queued, $match->status);
        $this->assertNull($match->hard_skip_reason);
        $this->assertSame($staff->id, $match->force_queued_by);
        $this->assertNotNull($match->force_queued_at);
        $this->assertNotNull($match->queued_at);
        $this->assertSame(
            'language_code_mismatch',
            $match->soft_signals['language_level']['reason'] ?? null,
        );

        $this->assertSame(1, Application::query()->count());
        $application = Application::query()->first();
        $this->assertNotNull($application);
        $this->assertSame(ApplicationStatus::Queued, $application->status);
        $this->assertSame($match->id, $application->candidate_listing_match_id);
        $this->assertNotSame(ApplicationStatus::Submitted, $application->status);
        $this->assertSame($application->id, $response->json('application.id'));
    }

    public function test_repeat_force_queue_on_queued_match_returns_409(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create();
        $listing = Listing::factory()->create();

        $match = CandidateListingMatch::factory()
            ->for($candidate)
            ->for($listing)
            ->hardSkip(MatchHardSkipReason::NotGermany)
            ->create();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/matches/'.$match->id.'/force-queue')
            ->assertOk();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/matches/'.$match->id.'/force-queue')
            ->assertStatus(409)
            ->assertJsonPath('code', 'match_not_skipped');

        $this->assertSame(1, Application::query()->count());
        $application = Application::query()->first();
        $this->assertNotNull($application);
        $this->assertSame(ApplicationStatus::Queued, $application->status);
        $this->assertNotSame(ApplicationStatus::Submitted, $application->status);
    }

    public function test_auto_queued_match_cannot_be_force_queued(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create();
        $listing = Listing::factory()->create();

        $match = CandidateListingMatch::factory()
            ->for($candidate)
            ->for($listing)
            ->queued()
            ->create();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/matches/'.$match->id.'/force-queue')
            ->assertStatus(409)
            ->assertJsonPath('code', 'match_not_skipped');

        $this->assertSame(0, Application::query()->count());
    }

    public function test_user_role_cannot_force_queue(): void
    {
        $user = User::factory()->create();
        $candidate = User::factory()->create();
        $match = CandidateListingMatch::factory()
            ->for($candidate)
            ->hardSkip()
            ->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/matches/'.$match->id.'/force-queue')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_guest_cannot_force_queue(): void
    {
        $match = CandidateListingMatch::factory()->hardSkip()->create();

        $this->postJson('/api/v1/matches/'.$match->id.'/force-queue')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_unknown_match_returns_404(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/matches/999999/force-queue')
            ->assertNotFound();
    }

    public function test_admin_can_force_queue_skipped_match(): void
    {
        $admin = User::factory()->admin()->create();
        $candidate = User::factory()->create();
        $match = CandidateListingMatch::factory()
            ->for($candidate)
            ->hardSkip(MatchHardSkipReason::Education)
            ->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/matches/'.$match->id.'/force-queue')
            ->assertOk()
            ->assertJsonPath('match.forceQueuedBy', $admin->id)
            ->assertJsonPath('application.status', 'queued');
    }
}
