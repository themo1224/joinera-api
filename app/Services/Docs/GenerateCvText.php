<?php

namespace App\Services\Docs;

use App\Contracts\LlmClient;
use App\Exceptions\LlmRequestException;
use App\Models\Listing;
use App\Models\Profile;
use App\Support\GeneratedDocText;

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
            'Erstelle den Textinhalt eines Lebenslaufs auf Deutsch, passend zur Stellenkategorie.',
            'Nur strukturierter Fließtext mit klaren Abschnitten (Profil, Erfahrung, Ausbildung, Kenntnisse).',
            'Keine Markdown-Codeblöcke, keine Preis- oder Zahlungsangaben.',
            '',
            'Profil:',
            '- Branche: '.($profile->industry ?? ''),
            '- Stadt: '.($profile->city ?? ''),
            '- Bundesland: '.($profile->province ?? ''),
            '- Sprache: '.($profile->language ?? ''),
            '- Ausbildung: '.($profile->education ?? ''),
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
                'content' => 'Du schreibst deutsche Lebenslauf-Inhalte für Bewerbungen. Antworte nur mit dem CV-Text.',
            ],
            [
                'role' => 'user',
                'content' => $userPrompt,
            ],
        ], [
            'temperature' => 0.3,
            'max_tokens' => 1600,
        ]);

        return new GeneratedDocText(
            text: trim($result->content),
            model: $result->model,
            usage: $result->usage,
        );
    }
}
