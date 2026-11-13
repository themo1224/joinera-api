<?php

namespace App\Contracts;

use App\Exceptions\NonPdfOutputException;

interface PdfRenderer
{
    /**
     * Render HTML (or plain text wrapped by the caller) into PDF bytes.
     *
     * @throws NonPdfOutputException when the result is not a PDF
     */
    public function render(string $html): string;
}
