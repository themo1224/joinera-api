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
}
