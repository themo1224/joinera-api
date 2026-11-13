<?php

namespace App\Services\Docs;

use App\Contracts\PdfRenderer;
use App\Exceptions\NonPdfOutputException;

class FakePdfRenderer implements PdfRenderer
{
    public const HTML_MARKER = 'FORCE_HTML_NOT_PDF';

    public function render(string $html): string
    {
        if (str_contains($html, self::HTML_MARKER)) {
            throw new NonPdfOutputException('Fake PDF renderer forced non_pdf.');
        }

        $body = trim(strip_tags($html));
        $payload = "%PDF-1.4\n%fake\n".$body."\n%%EOF\n";

        if (! str_starts_with($payload, '%PDF')) {
            throw new NonPdfOutputException('Fake PDF renderer produced non-PDF bytes.');
        }

        return $payload;
    }
}
