<?php

namespace Tests\Unit\Documents;

use App\Services\Documents\Parsers\PassportMrzParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PassportMrzParserTest extends TestCase
{
    #[Test]
    public function it_parses_td3_mrz_lines(): void
    {
        $text = <<<'TXT'
PASSEPORT
P<MARMODDEN<MALKI<<CHAIMAE<<<<<<<<<<<<<<<<<<<
GC83571637MAR8902085F3010286K462108<<<<<<<18
TXT;

        $parsed = (new PassportMrzParser)->parseFromText($text);

        $this->assertNotNull($parsed);
        $this->assertSame('Chaimae Modden Malki', $parsed['full_name']);
        $this->assertSame('1989-02-08', $parsed['date_of_birth']);
        $this->assertSame('GC8357163', $parsed['passport_number']);
        $this->assertSame('F', $parsed['sex']);
    }
}
