<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\MatchStatus;
use App\Jobs\PrepareApplicationDocsJob;
use App\Models\Application;
use App\Models\CandidateListingMatch;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StaffQueueSelectedMatchesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'joinera.apply.cap' => 10,
            'joinera.apply.cap_window' => 'daily',
        ]);

        Queue::fake([PrepareApplicationDocsJob::class]);
    }

    public function test_staff_lists_proposed_matches_with_apply_cap_for_user(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create(['name' => 'Pick Candidate']);
        $listing = Listing::factory()->forSource('azubiyo')->create([
            'title' => 'Proposed Job',
            'city' => 'Berlin',
        ]);

        CandidateListingMatch::factory()
            ->for($candidate)
            ->for($listing)
            ->proposed()
            ->withSoftSignals([
                'language_level' => [
                    'mismatch' => true,
                    'reason' => 'language_code_mismatch',
                ],
            ])
            ->create();

        CandidateListingMatch::factory()->for($candidate)->queued()->create();

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/matches?status=proposed&userId='.$candidate->id)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('matches.0.status', 'proposed')
            ->assertJsonPath('matches.0.listingTitle', 'Proposed Job')
            ->assertJsonPath('matches.0.softSignals.language_level.reason', 'language_code_mismatch')
            ->assertJsonPath('applyCap.cap', 10)
            ->assertJsonPath('applyCap.remaining', 10)
            ->assertJsonPath('applyCap.used', 0);
    }

    public function test_staff_queues_selected_proposed_matches(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create();
        $listingA = Listing::factory()->forSource('azubiyo')->create(['title' => 'A']);
        $listingB = Listing::factory()->forSource('stepstone')->create(['title' => 'B']);

        $matchA = CandidateListingMatch::factory()->for($candidate)->for($listingA)->proposed()->create();
        $matchB = CandidateListingMatch::factory()->for($candidate)->for($listingB)->proposed()->create();
        CandidateListingMatch::factory()->for($candidate)->proposed()->create();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/matches/queue-selected', [
                'userId' => $candidate->id,
                'matchIds' => [$matchA->id, $matchB->id],
            ])
            ->assertOk()
            ->assertJsonPath('applications.0.status', 'queued')
            ->assertJsonPath('applications.1.status', 'queued')
            ->assertJsonPath('applyCap.used', 2)
            ->assertJsonPath('applyCap.remaining', 8);

        $this->assertSame(MatchStatus::Queued, $matchA->fresh()->status);
        $this->assertSame(MatchStatus::Queued, $matchB->fresh()->status);
        $this->assertSame(2, Application::query()->count());
        $this->assertSame(
            1,
            CandidateListingMatch::query()->where('status', MatchStatus::Proposed)->count(),
        );

        Queue::assertPushed(PrepareApplicationDocsJob::class, 2);
    }

    public function test_queue_selected_enforces_apply_cap(): void
    {
        config(['joinera.apply.cap' => 1]);

        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create();
        $matchA = CandidateListingMatch::factory()->for($candidate)->proposed()->create();
        $matchB = CandidateListingMatch::factory()->for($candidate)->proposed()->create();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/matches/queue-selected', [
                'userId' => $candidate->id,
                'matchIds' => [$matchA->id, $matchB->id],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'apply_cap_reached')
            ->assertJsonPath('applyCap.remaining', 1);

        $this->assertSame(0, Application::query()->count());
        $this->assertSame(MatchStatus::Proposed, $matchA->fresh()->status);
    }

    public function test_queue_selected_rejects_non_proposed(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create();
        $match = CandidateListingMatch::factory()->for($candidate)->hardSkip()->create();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/matches/queue-selected', [
                'userId' => $candidate->id,
                'matchIds' => [$match->id],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'match_not_proposed');
    }

    public function test_queue_selected_rejects_other_users_matches(): void
    {
        $staff = User::factory()->staff()->create();
        $one = User::factory()->create();
        $two = User::factory()->create();
        $match = CandidateListingMatch::factory()->for($two)->proposed()->create();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/matches/queue-selected', [
                'userId' => $one->id,
                'matchIds' => [$match->id],
            ])
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    }

    public function test_queued_application_counts_toward_cap(): void
    {
        config(['joinera.apply.cap' => 1]);

        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create();
        $listing = Listing::factory()->create();
        $queuedMatch = CandidateListingMatch::factory()->for($candidate)->for($listing)->queued()->create();
        Application::factory()->forMatch($queuedMatch)->create([
            'status' => ApplicationStatus::Queued,
        ]);

        $proposed = CandidateListingMatch::factory()->for($candidate)->proposed()->create();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/matches/queue-selected', [
                'userId' => $candidate->id,
                'matchIds' => [$proposed->id],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'apply_cap_reached')
            ->assertJsonPath('applyCap.used', 1)
            ->assertJsonPath('applyCap.remaining', 0);
    }
}
