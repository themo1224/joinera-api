<?php

namespace App\Contracts;

interface DocumentOcrClient
{
    public function isAvailable(): bool;

    /**
     * OCR a single page image file path. Returns empty string when unavailable/failed.
     *
     * @param  list<string>  $languages  e.g. ['deu','eng'] or ['ara','eng']
     */
    public function recognizeFile(string $absolutePath, array $languages = ['deu', 'eng']): string;
}
