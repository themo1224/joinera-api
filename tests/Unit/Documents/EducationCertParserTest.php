<?php

namespace Tests\Unit\Documents;

use App\Services\Documents\Parsers\EducationCertParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EducationCertParserTest extends TestCase
{
    #[Test]
    public function it_extracts_narrative_herr_name_without_name_label(): void
    {
        $text = <<<'TXT'
Zeugnis der allgemeinen Hochschulreife
Herr Max Mustermann hat die Abiturprüfung im Jahr 2022 bestanden.
Prädikat: gut
TXT;

        $parsed = (new EducationCertParser)->parse($text);

        $this->assertSame('Max Mustermann', $parsed['candidate_name']);
        $this->assertSame('Abitur', $parsed['education'] ?? null);
    }

    #[Test]
    public function it_extracts_schueler_narrative_name(): void
    {
        $text = <<<'TXT'
Schulbescheinigung
Der Schüler Yassine Moussaid hat die Prüfung abgelegt.
Abitur 2021
TXT;

        $parsed = (new EducationCertParser)->parse($text);

        $this->assertSame('Yassine Moussaid', $parsed['candidate_name']);
    }
}
