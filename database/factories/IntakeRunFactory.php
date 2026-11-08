<?php

namespace Database\Factories;

use App\Models\IntakeRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntakeRun>
 */
class IntakeRunFactory extends Factory
{
    protected $model = IntakeRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'started_at' => now()->subHours(2),
            'finished_at' => null,
        ];
    }

    public function finished(): static
    {
        return $this->state(fn (array $attributes) => [
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHour(),
        ]);
    }
}
