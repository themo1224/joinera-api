<?php

namespace Tests\Unit\Documents;

use App\Enums\DocumentPageRole;
use App\Enums\PageTextKind;
use App\Services\Documents\PageRoleClassifier;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageRoleClassifierTest extends TestCase
{
    #[Test]
    public function it_routes_anschreiben_and_skips(): void
    {
        $classifier = new PageRoleClassifier;
        $text = "Sehr geehrte Damen und Herren,\n...\nMit freundlichen Grüßen\nOthmane";
        $role = $classifier->classify($text, PageTextKind::Text);

        $this->assertSame(DocumentPageRole::Anschreiben, $role);
        $this->assertTrue($classifier->shouldSkip($role));
    }

    #[Test]
    public function it_routes_language_cert(): void
    {
        $classifier = new PageRoleClassifier;
        $text = "telc Deutsch B2\nName: El Badaoui\nVorname: Othmane\nGeburtsdatum: 31.05.1999";
        $role = $classifier->classify($text, PageTextKind::Text);

        $this->assertSame(DocumentPageRole::LanguageCert, $role);
        $this->assertFalse($classifier->shouldSkip($role));
    }

    #[Test]
    public function it_routes_cv(): void
    {
        $classifier = new PageRoleClassifier;
        $text = "Lebenslauf\nBerufserfahrung\nBildungsweg\nSprachkenntnisse\nFührerschein Klasse B";
        $role = $classifier->classify($text, PageTextKind::Text);

        $this->assertSame(DocumentPageRole::Cv, $role);
    }
}
