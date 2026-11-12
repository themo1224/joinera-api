<?php

namespace Database\Factories;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\CandidateListingMatch;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    protected $model = Application::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'listing_id' => Listing::factory(),
            'candidate_listing_match_id' => null,
            'site' => fn (array $attributes): string => (string) (
                Listing::query()->whereKey($attributes['listing_id'])->value('source') ?? 'fixture'
            ),
            'status' => ApplicationStatus::Matched,
        ];
    }

    public function matched(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ApplicationStatus::Matched,
        ]);
    }

    public function queued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ApplicationStatus::Queued,
        ]);
    }

    public function preparingDocs(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ApplicationStatus::PreparingDocs,
        ]);
    }

    public function forMatch(CandidateListingMatch $match): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $match->user_id,
            'listing_id' => $match->listing_id,
            'candidate_listing_match_id' => $match->id,
            'site' => (string) (
                $match->listing?->source
                ?? Listing::query()->whereKey($match->listing_id)->value('source')
                ?? 'fixture'
            ),
        ]);
    }
}
