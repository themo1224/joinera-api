<?php

namespace Database\Factories;

use App\Models\Industry;
use App\Models\SubIndustry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubIndustry>
 */
class SubIndustryFactory extends Factory
{
    protected $model = SubIndustry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'industry_id' => Industry::factory(),
            'slug' => $slug,
            'label_en' => ucfirst(str_replace('-', ' ', $slug)),
            'label_de' => ucfirst(str_replace('-', ' ', $slug)),
            'sort_order' => 0,
            'archived_at' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => [
            'archived_at' => now(),
        ]);
    }
}
