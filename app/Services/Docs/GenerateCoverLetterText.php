<?php

namespace App\Services\Docs;

use App\Contracts\LlmClient;
use App\Exceptions\LlmRequestException;
use App\Models\Listing;
use App\Models\Profile;
use App\Support\GeneratedDocText;

class GenerateCoverLetterText
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
            'Erstelle ein kurzes, professionelles Anschreiben auf Deutsch für diese Bewerbung.',
            'Nur Fließtext, keine Markdown-Überschriften, keine Preis- oder Zahlungsangaben.',
            '',
            'Profil:',
            '- Branche: '.($profile->industry ?? ''),
            '- Stadt: '.($profile->city ?? ''),
            '- Bundesland: '.($profile->province ?? ''),
            '- Sprache: '.($profile->language ?? ''),
            '- Ausbildung: '.($profile->education ?? ''),
            '',
            'Stelle:',
            '- Titel: '.($listing->title ?? ''),
            '- Unternehmen: '.($listing->company ?? ''),
            '- Kategorie: '.($listing->category ?? ''),
            '- Stadt: '.($listing->city ?? ''),
            '- Quelle: '.($listing->source ?? ''),
            '- Beschreibung:',
            $description,
        ]);

        $result = $this->llm->chat([
            [
                'role' => 'system',
                'content' => 'Du schreibst deutsche Bewerbungsanschreiben. Antworte nur mit dem Anschreibentext.',
            ],
            [
                'role' => 'user',
                'content' => $userPrompt,
            ],
        ], [
            'temperature' => 0.4,
            'max_tokens' => 900,
        ]);

        return new GeneratedDocText(
            text: trim($result->content),
            model: $result->model,
            usage: $result->usage,
        );
    }
}
