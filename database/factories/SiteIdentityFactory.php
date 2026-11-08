<?php

namespace Database\Factories;

use App\Enums\SiteIdentityState;
use App\Models\SiteIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteIdentity>
 */
class SiteIdentityFactory extends Factory
{
    protected $model = SiteIdentity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'site' => fake()->unique()->slug(1),
            'site_login' => fake()->unique()->safeEmail(),
            'secret' => 'demo-site-secret',
            'state' => SiteIdentityState::Healthy,
            'paused' => false,
        ];
    }

    public function healthy(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => SiteIdentityState::Healthy,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => SiteIdentityState::Failed,
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'paused' => true,
        ]);
    }
}
