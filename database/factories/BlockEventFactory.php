<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\BlockEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlockEvent>
 */
class BlockEventFactory extends Factory
{
    protected $model = BlockEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'site' => 'azubiyo',
            'application_id' => Application::factory(),
            'observed_kind' => BlockEvent::KIND_CAPTCHA,
            // Plan 25 — factory defaults to open pause (unresolved).
            'resolved_at' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (): array => [
            'resolved_at' => now(),
        ]);
    }
}
