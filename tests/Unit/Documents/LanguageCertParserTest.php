<?php

namespace Tests\Unit\Documents;

use App\Services\Documents\Parsers\LanguageCertParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LanguageCertParserTest extends TestCase
{
    #[Test]
    public function it_extracts_telc_b2(): void
    {
        $text = "Zertifikat\ntelc Deutsch B2\nName: Moussaid\nVorname: Yassine\nGeburtsdatum: 14.08.2006";
        $entries = (new LanguageCertParser)->parse($text);

        $this->assertCount(1, $entries);
        $this->assertSame('Deutsch', $entries[0]['language']);
        $this->assertSame('B2', $entries[0]['level']);
        $this->assertSame('telc', $entries[0]['source']);
        $this->assertSame('2006-08-14', $entries[0]['date_of_birth']);
    }
}
