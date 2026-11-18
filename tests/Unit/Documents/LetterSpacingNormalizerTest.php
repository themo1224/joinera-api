<?php

namespace Tests\Unit\Documents;

use App\Support\Documents\LetterSpacingNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LetterSpacingNormalizerTest extends TestCase
{
    #[Test]
    public function it_collapses_letter_spaced_lines(): void
    {
        $normalizer = new LetterSpacingNormalizer;
        $out = $normalizer->normalize("O t h m a n e\nNormal word line");

        $this->assertStringContainsString('Othmane', $out);
        $this->assertStringContainsString('Normal word line', $out);
    }
}
