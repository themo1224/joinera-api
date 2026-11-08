<?php

namespace Database\Factories;

use App\Enums\IntakeRunSiteStatus;
use App\Models\IntakeRun;
use App\Models\IntakeRunSite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntakeRunSite>
 */
class IntakeRunSiteFactory extends Factory
{
    protected $model = IntakeRunSite::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'intake_run_id' => IntakeRun::factory(),
            'site' => fake()->unique()->slug(1),
            'status' => IntakeRunSiteStatus::Ok,
            'error' => null,
            'listings_created' => fake()->numberBetween(0, 25),
        ];
    }

    public function ok(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IntakeRunSiteStatus::Ok,
            'error' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IntakeRunSiteStatus::Failed,
            'error' => 'Demo intake failure: site adapter returned an error.',
            'listings_created' => 0,
        ]);
    }
}
