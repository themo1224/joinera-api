<?php

namespace Tests\Feature;

use App\Enums\MatchHardSkipReason;
use App\Enums\MatchStatus;
use App\Models\CandidateListingMatch;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CandidateListingMatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_match_status_and_hard_skip_reason_enum_values(): void
    {
        $this->assertSame('skipped', MatchStatus::Skipped->value);
        $this->assertSame('queued', MatchStatus::Queued->value);
        $this->assertSame('industry', MatchHardSkipReason::Industry->value);
        $this->assertSame('not_germany', MatchHardSkipReason::NotGermany->value);
        $this->assertSame('education', MatchHardSkipReason::Education->value);
    }

    public function test_user_and_listing_pair_is_unique(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create();

        CandidateListingMatch::factory()->for($user)->for($listing)->create();

        $this->expectException(QueryException::class);

        CandidateListingMatch::factory()->for($user)->for($listing)->create();
    }

    public function test_queued_factory_casts_status_to_enum(): void
    {
        $match = CandidateListingMatch::factory()->queued()->create();

        $this->assertInstanceOf(MatchStatus::class, $match->status);
        $this->assertSame(MatchStatus::Queued, $match->status);
        $this->assertNull($match->hard_skip_reason);
        $this->assertNotNull($match->queued_at);
    }

    public function test_hard_skip_reason_casts_to_enum(): void
    {
        $match = CandidateListingMatch::factory()
            ->hardSkip(MatchHardSkipReason::NotGermany)
            ->create();

        $this->assertSame(MatchStatus::Skipped, $match->status);
        $this->assertSame(MatchHardSkipReason::NotGermany, $match->hard_skip_reason);
    }

    public function test_force_queued_by_relation_and_user_listing_inverses(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create();
        $listing = Listing::factory()->create();

        $match = CandidateListingMatch::factory()
            ->for($candidate)
            ->for($listing)
            ->forceQueuedBy($staff)
            ->create();

        $this->assertSame(MatchStatus::Queued, $match->status);
        $this->assertTrue($match->forceQueuedBy->is($staff));
        $this->assertTrue($candidate->candidateListingMatches()->whereKey($match->id)->exists());
        $this->assertTrue($listing->candidateListingMatches()->whereKey($match->id)->exists());
    }
}
