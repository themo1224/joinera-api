<?php

namespace Tests\Feature;

use App\Enums\MatchHardSkipReason;
use App\Enums\MatchStatus;
use App\Models\CandidateListingMatch;
use App\Models\Listing;
use App\Models\User;
use App\Services\StoreMatchSoftSignals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreMatchSoftSignalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_persists_soft_signal_json_without_changing_status(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->fill([
            'industry' => 'IT',
            'city' => 'Munich',
            'province' => 'BY',
            'language' => 'fa',
        ])->save();

        $listing = Listing::factory()->create([
            'category' => 'IT',
            'city' => '12435 Berlin',
            'country' => 'DE',
            'language_requirement' => 'de C1',
        ]);

        $match = CandidateListingMatch::factory()
            ->for($user)
            ->for($listing)
            ->hardSkip(MatchHardSkipReason::Industry)
            ->create();

        $stored = app(StoreMatchSoftSignals::class)->handle($match, $profile->fresh(), $listing);

        $this->assertSame(MatchStatus::Skipped, $stored->status);
        $this->assertSame(MatchHardSkipReason::Industry, $stored->hard_skip_reason);
        $this->assertIsArray($stored->soft_signals);
        $this->assertSame('language_code_mismatch', $stored->soft_signals['language_level']['reason']);
        $this->assertSame('city_mismatch', $stored->soft_signals['location']['reason']);
    }

    public function test_clean_pair_stores_null_soft_signals(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->fill([
            'industry' => 'IT',
            'city' => 'Berlin',
            'province' => 'BE',
            'language' => 'de',
        ])->save();

        $listing = Listing::factory()->create([
            'category' => 'IT',
            'city' => '12435 Berlin',
            'country' => 'DE',
            'language_requirement' => 'B1 German',
        ]);

        $match = CandidateListingMatch::factory()
            ->for($user)
            ->for($listing)
            ->queued()
            ->withSoftSignals(['location' => ['mismatch' => true, 'reason' => 'stale']])
            ->create();

        $stored = app(StoreMatchSoftSignals::class)->handle($match, $profile->fresh(), $listing);

        $this->assertSame(MatchStatus::Queued, $stored->status);
        $this->assertNull($stored->hard_skip_reason);
        $this->assertNull($stored->soft_signals);
    }
}
