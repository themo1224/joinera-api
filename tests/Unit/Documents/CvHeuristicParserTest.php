<?php

namespace Tests\Unit\Documents;

use App\Services\Documents\Parsers\CvHeuristicParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CvHeuristicParserTest extends TestCase
{
    #[Test]
    public function it_extracts_multiple_languages_from_cv_list(): void
    {
        $text = <<<'TXT'
Lebenslauf
Max Mustermann

Sprachen:
Arabisch – Muttersprache
Deutsch: B2
Englisch - gute Kenntnisse
Französisch (A2)
TXT;

        $parsed = (new CvHeuristicParser)->parse($text);
        $languages = collect($parsed['languages'])->keyBy('language');

        $this->assertGreaterThanOrEqual(3, count($parsed['languages']));
        $this->assertSame('native', $languages['Arabisch']['level']);
        $this->assertSame('B2', $languages['Deutsch']['level']);
        $this->assertSame('B1', $languages['Englisch']['level']);
        $this->assertSame('A2', $languages['Französisch']['level']);
    }

    #[Test]
    public function it_extracts_name_from_vorname_nachname_fields(): void
    {
        $text = <<<'TXT'
Persönliche Daten
Vorname: Yassine
Nachname: Moussaid
Geburtsdatum: 14.08.2006

Sprachen
Deutsch B2
TXT;

        $parsed = (new CvHeuristicParser)->parse($text);

        $this->assertSame('Yassine Moussaid', $parsed['candidate_name']);
    }

    #[Test]
    public function it_extracts_name_from_name_label(): void
    {
        $text = "Name: Anna Schmidt\nSprachen\nDeutsch Muttersprache\nEnglisch B1";

        $parsed = (new CvHeuristicParser)->parse($text);

        $this->assertSame('Anna Schmidt', $parsed['candidate_name']);
        $this->assertCount(2, $parsed['languages']);
    }

    #[Test]
    public function it_extracts_experiences_and_inferred_skills_from_german_lebenslauf(): void
    {
        $text = <<<'TXT'
Lebenslauf
Vorname: Yassine
Nachname: Moussaid

Profil:
Motivierter Koch mit Erfahrung in der Gastronomie. Kenntnisse in HACCP und Hygiene.
Gäste betreuen und Bestellungen aufnehmen.

BERUFLICHE ERFAHRUNG
06/07/2026-10/08/2026 Servicekraft Restaurant MM
- Service und Bankett
06/2023 – 09/2023 Commis de Cuisine Mazagan Beach & Golf Resort
- Teamwork in der Küche
2022. Grillgehilfe Grillrestaurant Atlas
- Zubereitung und Hygiene

Sprachen
Deutsch B2
Arabisch Muttersprache
TXT;

        $parsed = (new CvHeuristicParser)->parse($text);

        $this->assertGreaterThanOrEqual(3, count($parsed['experiences']));

        $blob = mb_strtolower(json_encode($parsed['experiences'], JSON_UNESCAPED_UNICODE) ?: '');
        $this->assertStringContainsString('restaurant mm', $blob);
        $this->assertStringContainsString('mazagan', $blob);
        $this->assertStringContainsString('grillrestaurant', $blob);

        $this->assertGreaterThanOrEqual(1, count($parsed['skills']));
        $skillBlob = mb_strtolower(collect($parsed['skills'])->pluck('name')->implode(' '));
        $this->assertTrue(
            str_contains($skillBlob, 'haccp')
            || str_contains($skillBlob, 'service')
            || str_contains($skillBlob, 'gastronomie')
            || str_contains($skillBlob, 'hygiene'),
            'Expected HACCP or service-related skill, got: '.$skillBlob
        );
    }
}
