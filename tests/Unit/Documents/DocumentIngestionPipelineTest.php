<?php

namespace Tests\Unit\Documents;

use App\Contracts\DocumentOcrClient;
use App\Services\Documents\DocumentIngestionPipeline;
use App\Services\Documents\IdentityMismatchGuard;
use App\Services\Documents\Ocr\NullDocumentOcrClient;
use App\Services\Documents\PageRoleClassifier;
use App\Services\Documents\PageTriage;
use App\Services\Documents\Parsers\CvHeuristicParser;
use App\Services\Documents\Parsers\EducationCertParser;
use App\Services\Documents\Parsers\LanguageCertParser;
use App\Services\Documents\Parsers\PassportMrzParser;
use App\Services\Documents\PdfPageImageRenderer;
use App\Services\Documents\PdfPageTextExtractor;
use App\Services\Documents\ResidualLlmStructurer;
use App\Services\Ai\FakeLlmClient;
use App\Support\Documents\LetterSpacingNormalizer;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocumentIngestionPipelineTest extends TestCase
{
    #[Test]
    public function it_extracts_cv_fields_from_text_pdf(): void
    {
        Storage::fake('local');
        $path = Storage::disk('local')->path('sample-cv.pdf');
        $this->writeMinimalTextPdf($path, "Lebenslauf\nName: Othmane El Badaoui\nBerufserfahrung\nKoch\nSprachkenntnisse\nDeutsch: B2\nFührerschein Klasse B\nBewerbung um eine Stelle als Koch");

        $pipeline = $this->makePipeline();
        $result = $pipeline->ingestFile($path, 'Othmane El Badaoui');

        $this->assertFalse($result->autoApplyBlocked);
        $this->assertNotEmpty($result->pages);
        $languages = collect($result->languages)->pluck('level', 'language')->all();
        $this->assertSame('B2', $languages['Deutsch'] ?? null);
        $this->assertContains('B', $result->drivingLicenses);
    }

    private function makePipeline(): DocumentIngestionPipeline
    {
        return new DocumentIngestionPipeline(
            new PdfPageTextExtractor,
            new LetterSpacingNormalizer,
            new PageTriage,
            new PageRoleClassifier,
            new CvHeuristicParser,
            new LanguageCertParser,
            new EducationCertParser,
            new PassportMrzParser,
            new IdentityMismatchGuard,
            new ResidualLlmStructurer(new FakeLlmClient),
            new NullDocumentOcrClient,
            new PdfPageImageRenderer,
        );
    }

    private function writeMinimalTextPdf(string $path, string $text): void
    {
        $html = '<html><body><pre>'.htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</pre></body></html>';
        $dompdf = new \Dompdf\Dompdf;
        $dompdf->loadHtml($html);
        $dompdf->render();
        file_put_contents($path, $dompdf->output());
    }
}
