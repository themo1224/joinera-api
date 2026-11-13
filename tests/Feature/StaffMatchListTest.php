<?php

namespace Tests\Feature;

use App\Enums\MatchHardSkipReason;
use App\Models\CandidateListingMatch;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffMatchListTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_lists_skipped_matches_with_soft_signals_and_hard_skip_reason(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create([
            'name' => 'Ops Candidate',
            'email' => 'ops.candidate@example.test',
        ]);
        $listing = Listing::factory()->forSource('azubiyo')->create([
            'title' => 'Skipped Job',
            'city' => 'Munich',
            'country' => 'DE',
            'category' => 'Nursing',
        ]);

        CandidateListingMatch::factory()
            ->for($candidate)
            ->for($listing)
            ->hardSkip(MatchHardSkipReason::Industry)
            ->withSoftSignals([
                'location' => [
                    'mismatch' => true,
                    'reason' => 'city_mismatch',
                ],
            ])
            ->create();

        CandidateListingMatch::factory()
            ->for($candidate)
            ->queued()
            ->create();

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/matches?status=skipped')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('matches.0.status', 'skipped')
            ->assertJsonPath('matches.0.hardSkipReason', 'industry')
            ->assertJsonPath('matches.0.softSignals.location.reason', 'city_mismatch')
            ->assertJsonPath('matches.0.listingTitle', 'Skipped Job')
            ->assertJsonPath('matches.0.listingSource', 'azubiyo')
            ->assertJsonPath('matches.0.userName', 'Ops Candidate')
            ->assertJsonPath('matches.0.userEmail', 'ops.candidate@example.test');
    }

    public function test_staff_lists_queued_matches_only(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create();

        CandidateListingMatch::factory()->for($candidate)->queued()->create();
        CandidateListingMatch::factory()->for($candidate)->hardSkip()->create();

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/matches?status=queued')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('matches.0.status', 'queued')
            ->assertJsonPath('matches.0.hardSkipReason', null);
    }

    public function test_list_can_filter_by_user_id(): void
    {
        $staff = User::factory()->staff()->create();
        $one = User::factory()->create();
        $two = User::factory()->create();

        CandidateListingMatch::factory()->for($one)->hardSkip()->create();
        CandidateListingMatch::factory()->for($two)->hardSkip()->create();

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/matches?status=skipped&userId='.$one->id)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('matches.0.userId', $one->id);
    }

    public function test_list_requires_valid_status(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/matches')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed');

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/matches?status=recommended')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed');
    }

    public function test_user_role_cannot_list_matches(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/matches?status=skipped')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_guest_cannot_list_matches(): void
    {
        $this->getJson('/api/v1/matches?status=skipped')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_credentials');
    }
}
