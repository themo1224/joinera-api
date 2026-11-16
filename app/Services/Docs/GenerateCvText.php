<?php

namespace App\Services\Docs;

use App\Contracts\LlmClient;
use App\Exceptions\LlmRequestException;
use App\Models\Listing;
use App\Models\Profile;
use App\Support\GeneratedDocText;
use App\Support\ProfilePromptContext;

class GenerateCvText
{
    public function __construct(
        private readonly LlmClient $llm,
    ) {}

    /**
     * @throws LlmRequestException
     */
    public function handle(Profile $profile, Listing $listing): GeneratedDocText
    {
        $maxChars = (int) config('joinera.docs.listing_description_max_chars', 2000);
        $description = (string) ($listing->description ?? '');
        if (mb_strlen($description) > $maxChars) {
            $description = mb_substr($description, 0, $maxChars).'…';
        }

        $userPrompt = implode("\n", [
            'Schreibe nur den Profil-/Über-mich-Absatz für einen Lebenslauf auf Deutsch (3–5 Sätze).',
            'Passe den Ton an die Stellenkategorie an. Keine Abschnittsüberschriften, keine Aufzählungen.',
            'Nutze die strukturierten Profildaten. Erfinde keine Abschlüsse, Arbeitgeber oder Sprachen.',
            'Keine Markdown-Codeblöcke, keine Preis- oder Zahlungsangaben.',
            '',
            ...ProfilePromptContext::lines($profile),
            '',
            'Zielstelle:',
            '- Titel: '.($listing->title ?? ''),
            '- Unternehmen: '.($listing->company ?? ''),
            '- Kategorie: '.($listing->category ?? ''),
            '- Beschreibung:',
            $description,
        ]);

        $result = $this->llm->chat([
            [
                'role' => 'system',
                'content' => 'Du schreibst kurze deutsche Profiltexte für Lebensläufe. Antworte nur mit dem Absatz.',
            ],
            [
                'role' => 'user',
                'content' => $userPrompt,
            ],
        ], [
            'temperature' => 0.3,
            'max_tokens' => 500,
        ]);

        return new GeneratedDocText(
            text: trim($result->content),
            model: $result->model,
            usage: $result->usage,
        );
    }
}
