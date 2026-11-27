<?php

namespace App\Services\Documents;

use App\Contracts\LlmClient;
use App\Exceptions\LlmRequestException;

/**
 * Tiny text-only residual structurer. Disabled unless joinera.documents.residual_llm is true.
 */
final class ResidualLlmStructurer
{
    public function __construct(private readonly LlmClient $llm)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('joinera.documents.residual_llm', false);
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array{status: 'disabled'|'skipped'|'ran'|'failed', fields: array<string, mixed>}
     */
    public function fillGaps(string $cleanedText, array $current): array
    {
        if (! $this->enabled()) {
            return ['status' => 'disabled', 'fields' => []];
        }

        $trimmed = trim($cleanedText);
        if ($trimmed === '') {
            return ['status' => 'skipped', 'fields' => []];
        }

        if (mb_strlen($trimmed) > 3500) {
            $trimmed = mb_substr($trimmed, 0, 3500);
        }

        $prompt = <<<'PROMPT'
Extract Joinera profile fields from the CV/certificate text. Return ONLY compact JSON with keys among:
suggested_name, date_of_birth, preferred_occupation, about, education, education_entries, experiences, skills, languages, driving_licenses, desired_path.
Use suggested_name for the applicant full name when present (name is accepted as an alias).
Use ISO date YYYY-MM-DD for date_of_birth when present.
Use CEFR levels A1-C2 or native for languages[].level.

experiences: list of {title, organization, start, end, current, description}. Extract every work/praktikum entry supported by the text (German Lebenslauf date layouts included). Never fabricate employers or date ranges; omit an experience rather than inventing employer/dates.

skills: list of {name, level, source}. Include explicit skills sections AND infer skills from job titles, duty bullets, and profile text when no Skills heading exists (e.g. HACCP, Service, Gäste betreuen, Bestellungen, Bankett, Teamwork). Only emit skills present in or clearly entailed by the text. Set source to "explicit" or "inferred". level may be null.

Do not invent names, degrees, languages, employers, or date ranges not supported by the text.
Omit keys that have no grounded value — except fill experiences/skills whenever the CV text supports them (including inferred skills from duties).
PROMPT;

        try {
            $result = $this->llm->chat([
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => "Known so far:\n".json_encode($current, JSON_UNESCAPED_UNICODE)."\n\nText:\n".$trimmed],
            ], [
                'temperature' => 0,
                'max_tokens' => 1200,
            ]);
        } catch (LlmRequestException) {
            return ['status' => 'failed', 'fields' => []];
        }

        $content = trim($result->content ?? '');
        if ($content === '') {
            return ['status' => 'failed', 'fields' => []];
        }

        if (preg_match('/\{.*\}/s', $content, $m)) {
            $content = $m[0];
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['status' => 'failed', 'fields' => []];
        }

        if (! is_array($decoded)) {
            return ['status' => 'failed', 'fields' => []];
        }

        if (isset($decoded['name']) && ! isset($decoded['suggested_name']) && is_string($decoded['name'])) {
            $decoded['suggested_name'] = $decoded['name'];
        }

        return ['status' => 'ran', 'fields' => $decoded];
    }
}
