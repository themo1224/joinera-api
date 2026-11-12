<?php

namespace Database\Factories;

use App\Enums\MatchHardSkipReason;
use App\Enums\MatchStatus;
use App\Models\CandidateListingMatch;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidateListingMatch>
 */
class CandidateListingMatchFactory extends Factory
{
    protected $model = CandidateListingMatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'listing_id' => Listing::factory(),
            'status' => MatchStatus::Skipped,
            'hard_skip_reason' => null,
            'soft_signals' => null,
            'rank_score' => null,
            'ranked_at' => null,
            'queued_at' => null,
            'force_queued_by' => null,
            'force_queued_at' => null,
        ];
    }

    public function skipped(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MatchStatus::Skipped,
            'queued_at' => null,
            'force_queued_by' => null,
            'force_queued_at' => null,
        ]);
    }

    public function queued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MatchStatus::Queued,
            'hard_skip_reason' => null,
            'queued_at' => now(),
        ]);
    }

    public function hardSkip(MatchHardSkipReason $reason = MatchHardSkipReason::Industry): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MatchStatus::Skipped,
            'hard_skip_reason' => $reason,
            'queued_at' => null,
            'force_queued_by' => null,
            'force_queued_at' => null,
        ]);
    }

    public function forceQueuedBy(User $actor): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MatchStatus::Queued,
            'hard_skip_reason' => null,
            'queued_at' => now(),
            'force_queued_by' => $actor->id,
            'force_queued_at' => now(),
        ]);
    }
}
