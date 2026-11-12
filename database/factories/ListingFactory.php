<?php

namespace Database\Factories;

use App\Enums\ListingKind;
use App\Models\Listing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    protected $model = Listing::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $externalId = 'ext-'.fake()->unique()->numerify('######');
        $source = fake()->randomElement(['stepstone', 'indeed', 'xing', 'curated']);

        return [
            'source' => $source,
            'external_id' => $externalId,
            'fingerprint' => hash('sha256', $source.'|'.$externalId),
            'kind' => ListingKind::Job,
            'category' => fake()->randomElement(['Nursing', 'IT', 'Hospitality', null]),
            'title' => fake()->jobTitle(),
            'company' => fake()->company(),
            'city' => fake()->randomElement(['Berlin', 'Hamburg', 'Munich', 'Cologne']),
            'url' => 'https://jobs.joinera.test/'.$externalId,
            'apply_url' => null,
            'description' => null,
            'language_requirement' => null,
            'education_requirement' => null,
            'country' => 'DE',
            'posted_at' => null,
            'first_seen_at' => now()->subDays(3),
            'last_seen_at' => now()->subDay(),
        ];
    }

    public function job(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => ListingKind::Job,
        ]);
    }

    public function ausbildung(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => ListingKind::Ausbildung,
            'title' => 'Ausbildung '.fake()->words(2, true),
        ]);
    }

    public function forSource(string $source, ?string $externalId = null): static
    {
        $externalId ??= 'ext-'.Str::lower(Str::random(8));

        return $this->state(fn (array $attributes) => [
            'source' => $source,
            'external_id' => $externalId,
            'fingerprint' => hash('sha256', $source.'|'.$externalId),
            'url' => 'https://jobs.joinera.test/'.$source.'/'.$externalId,
        ]);
    }
}
