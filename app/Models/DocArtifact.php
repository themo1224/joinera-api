<?php

namespace App\Models;

use App\Enums\DocArtifactKind;
use App\Enums\DocGenerationRule;
use Database\Factories\DocArtifactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'application_id',
    'kind',
    'rule',
    'cache_hit',
    'disk',
    'path',
    'mime',
    'size',
    'llm_model',
    'llm_prompt_tokens',
    'llm_completion_tokens',
    'llm_total_tokens',
])]
class DocArtifact extends Model
{
    /** @use HasFactory<DocArtifactFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DocArtifactKind::class,
            'rule' => DocGenerationRule::class,
            'cache_hit' => 'boolean',
            'llm_prompt_tokens' => 'integer',
            'llm_completion_tokens' => 'integer',
            'llm_total_tokens' => 'integer',
        ];
    }
}
