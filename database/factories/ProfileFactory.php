<?php

namespace Database\Factories;

use App\Enums\ApprovalStatus;
use App\Enums\ParseStatus;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
{
    protected $model = Profile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'industry' => fake()->randomElement(['Healthcare', 'IT', 'Hospitality', 'Logistics']),
            'city' => fake()->randomElement(['Berlin', 'Hamburg', 'Munich', 'Cologne']),
            'province' => fake()->randomElement(['BE', 'HH', 'BY', 'NW']),
            'language' => fake()->randomElement(['de', 'en', 'de+en']),
            'parse_status' => ParseStatus::Unparsed,
            'user_edited_fields' => null,
            'priorities' => Profile::DEFAULT_PRIORITIES,
            'approval' => ApprovalStatus::Pending,
            'approved_by' => null,
            'approved_at' => null,
        ];
    }

    public function parsed(): static
    {
        return $this->state(fn (array $attributes) => [
            'parse_status' => ParseStatus::Parsed,
        ]);
    }

    public function parseFailed(): static
    {
        return $this->state(fn (array $attributes) => [
            'parse_status' => ParseStatus::ParseFailed,
        ]);
    }

    public function unparsed(): static
    {
        return $this->state(fn (array $attributes) => [
            'parse_status' => ParseStatus::Unparsed,
        ]);
    }

    public function approved(?User $approver = null): static
    {
        return $this->state(fn (array $attributes) => [
            'approval' => ApprovalStatus::Approved,
            'approved_by' => $approver?->id,
            'approved_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'approval' => ApprovalStatus::Pending,
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }

    public function incomplete(): static
    {
        return $this->state(fn (array $attributes) => [
            'industry' => null,
            'city' => null,
            'province' => null,
            'language' => null,
        ]);
    }
}
