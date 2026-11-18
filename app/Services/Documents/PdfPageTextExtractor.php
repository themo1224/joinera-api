<?php

namespace App\Services\Documents;

use App\Exceptions\CvUnreadableException;
use Smalot\PdfParser\Parser;

final class PdfPageTextExtractor
{
    public function __construct(private readonly Parser $parser = new Parser)
    {
    }

    /**
     * @return list<string> 1-indexed page texts (index 0 unused conceptually — returns 0-based list)
     *
     * @throws CvUnreadableException
     */
    public function extractPageTexts(string $absolutePdfPath): array
    {
        if (! is_file($absolutePdfPath)) {
            throw new CvUnreadableException('The CV could not be read.');
        }

        try {
            $pdf = $this->parser->parseFile($absolutePdfPath);
            $pages = $pdf->getPages();
        } catch (\Throwable $e) {
            throw new CvUnreadableException('The CV could not be read.', previous: $e);
        }

        $texts = [];
        foreach ($pages as $page) {
            try {
                $texts[] = (string) $page->getText();
            } catch (\Throwable) {
                $texts[] = '';
            }
        }

        if ($texts === []) {
            throw new CvUnreadableException('The CV could not be read.');
        }

        return $texts;
    }
}
