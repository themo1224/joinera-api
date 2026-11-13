<?php

namespace App\Services\Docs;

use App\Enums\DocArtifactKind;
use App\Enums\DocGenerationRule;
use App\Models\Application;
use App\Models\DocArtifact;
use App\Support\GeneratedDocText;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StoreDocArtifact
{
    /**
     * @param  array{model?: string|null, usage?: array<string, mixed>|null}|null  $llmMeta
     */
    public function handle(
        Application $application,
        DocArtifactKind $kind,
        DocGenerationRule $rule,
        string $pdfBytes,
        bool $cacheHit,
        ?GeneratedDocText $llm = null,
    ): DocArtifact {
        $disk = (string) config('joinera.docs.disk', 'local');
        $path = sprintf(
            'docs/%d/%s-%s.pdf',
            $application->id,
            $kind->value,
            Str::uuid()->toString(),
        );

        $existing = DocArtifact::query()
            ->where('application_id', $application->id)
            ->where('kind', $kind)
            ->first();

        if ($existing !== null) {
            Storage::disk($existing->disk)->delete($existing->path);
            $existing->delete();
        }

        Storage::disk($disk)->put($path, $pdfBytes);

        $usage = $llm?->usage;

        return DocArtifact::query()->create([
            'application_id' => $application->id,
            'kind' => $kind,
            'rule' => $rule,
            'cache_hit' => $cacheHit,
            'disk' => $disk,
            'path' => $path,
            'mime' => 'application/pdf',
            'size' => strlen($pdfBytes),
            'llm_model' => $llm?->model,
            'llm_prompt_tokens' => $this->token($usage, 'prompt_tokens'),
            'llm_completion_tokens' => $this->token($usage, 'completion_tokens'),
            'llm_total_tokens' => $this->token($usage, 'total_tokens'),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $usage
     */
    private function token(?array $usage, string $key): ?int
    {
        if ($usage === null || ! array_key_exists($key, $usage)) {
            return null;
        }

        $value = $usage[$key];

        return is_numeric($value) ? (int) $value : null;
    }
}
