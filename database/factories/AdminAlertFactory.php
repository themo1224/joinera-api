<?php

namespace Database\Factories;

use App\Models\AdminAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminAlert>
 */
class AdminAlertFactory extends Factory
{
    protected $model = AdminAlert::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $site = fake()->randomElement(['stepstone', 'indeed', 'xing']);

        return [
            'kind' => AdminAlert::KIND_INTAKE_SITE_REPEATED_FAILURE,
            'site' => $site,
            'payload' => [
                'intake_run_id' => fake()->numberBetween(1, 100),
            ],
        ];
    }

    public function forIntakeRun(int $intakeRunId, string $site): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => AdminAlert::KIND_INTAKE_SITE_REPEATED_FAILURE,
            'site' => $site,
            'payload' => [
                'intake_run_id' => $intakeRunId,
            ],
        ]);
    }
}
