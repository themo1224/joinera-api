<?php

namespace App\Services\Documents\Ocr;

use App\Contracts\DocumentOcrClient;

final class NullDocumentOcrClient implements DocumentOcrClient
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function recognizeFile(string $absolutePath, array $languages = ['deu', 'eng']): string
    {
        return '';
    }
}
