<?php

namespace Database\Factories;

use App\Enums\ApplyAttemptKind;
use App\Models\Application;
use App\Models\ApplyAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplyAttempt>
 */
class ApplyAttemptFactory extends Factory
{
    protected $model = ApplyAttempt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory()->applying(),
            'kind' => null,
            'confirmation_reference' => null,
            'stop_reason' => null,
        ];
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => null,
            'confirmation_reference' => null,
            'stop_reason' => null,
        ]);
    }

    public function success(?string $confirmationReference = 'fixture-confirmation'): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => ApplyAttemptKind::Success,
            'confirmation_reference' => $confirmationReference,
            'stop_reason' => null,
        ]);
    }

    public function retryable(?string $stopReason = 'timeout'): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => ApplyAttemptKind::Retryable,
            'confirmation_reference' => null,
            'stop_reason' => $stopReason,
        ]);
    }

    public function challenge(?string $stopReason = 'captcha'): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => ApplyAttemptKind::Challenge,
            'confirmation_reference' => null,
            'stop_reason' => $stopReason,
        ]);
    }

    public function hardFail(?string $stopReason = 'identity_mismatch'): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => ApplyAttemptKind::HardFail,
            'confirmation_reference' => null,
            'stop_reason' => $stopReason,
        ]);
    }
}
