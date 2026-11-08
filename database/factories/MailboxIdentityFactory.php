<?php

namespace Database\Factories;

use App\Enums\MailboxState;
use App\Models\MailboxIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MailboxIdentity>
 */
class MailboxIdentityFactory extends Factory
{
    protected $model = MailboxIdentity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = Str::lower(fake()->unique()->bothify('demo-####'));
        $domain = $slug.'.joinera.test';

        return [
            'user_id' => User::factory(),
            'domain' => $domain,
            'address' => 'hello@'.$domain,
            'state' => MailboxState::Pending,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => MailboxState::Ready,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => MailboxState::Pending,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => MailboxState::Failed,
        ]);
    }
}
