<?php

namespace Database\Factories;

use App\Enums\DocArtifactKind;
use App\Enums\DocGenerationRule;
use App\Models\Application;
use App\Models\DocArtifact;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocArtifact>
 */
class DocArtifactFactory extends Factory
{
    protected $model = DocArtifact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $kind = DocArtifactKind::Cover;
        $name = $kind->value.'-'.Str::uuid().'.pdf';

        return [
            'application_id' => Application::factory(),
            'kind' => $kind,
            'rule' => DocGenerationRule::CoverOnly,
            'cache_hit' => false,
            'disk' => 'local',
            'path' => 'docs/demo/'.$name,
            'mime' => 'application/pdf',
            'size' => fake()->numberBetween(1_000, 50_000),
            'llm_model' => null,
            'llm_prompt_tokens' => null,
            'llm_completion_tokens' => null,
            'llm_total_tokens' => null,
        ];
    }

    public function cv(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => DocArtifactKind::Cv,
        ]);
    }

    public function cover(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => DocArtifactKind::Cover,
        ]);
    }
}
