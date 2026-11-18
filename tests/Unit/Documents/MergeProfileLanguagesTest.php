<?php

namespace Tests\Unit\Documents;

use App\Support\MergeProfileLanguages;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MergeProfileLanguagesTest extends TestCase
{
    #[Test]
    public function it_merges_cert_deutsch_with_cv_languages(): void
    {
        $existing = [
            ['language' => 'Deutsch', 'level' => 'B2'],
        ];
        $incoming = [
            ['language' => 'Arabisch', 'level' => 'native'],
            ['language' => 'Englisch', 'level' => 'B1'],
            ['language' => 'Deutsch', 'level' => 'B1'],
        ];

        $merged = collect(MergeProfileLanguages::merge($existing, $incoming))->keyBy('language');

        $this->assertCount(3, $merged);
        $this->assertSame('B2', $merged['Deutsch']['level']);
        $this->assertSame('native', $merged['Arabisch']['level']);
        $this->assertSame('B1', $merged['Englisch']['level']);
    }

    #[Test]
    public function it_prefers_higher_cefr_on_collision(): void
    {
        $merged = MergeProfileLanguages::merge(
            [['language' => 'Englisch', 'level' => 'A2']],
            [['language' => 'Englisch', 'level' => 'C1']]
        );

        $this->assertCount(1, $merged);
        $this->assertSame('C1', $merged[0]['level']);
    }
}
