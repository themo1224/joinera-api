<?php

namespace App\Services\Docs;

use App\Contracts\PdfRenderer;
use App\Exceptions\NonPdfOutputException;
use Dompdf\Dompdf;
use Dompdf\Options;

class DompdfPdfRenderer implements PdfRenderer
{
    public function render(string $html): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $bytes = $dompdf->output();

        if (! is_string($bytes) || ! str_starts_with($bytes, '%PDF')) {
            throw new NonPdfOutputException('Dompdf did not produce PDF bytes.');
        }

        return $bytes;
    }
}
