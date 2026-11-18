<?php

namespace App\Services\Documents;

/**
 * Best-effort page rasterization for local OCR. Uses Imagick when available.
 */
final class PdfPageImageRenderer
{
    public function isAvailable(): bool
    {
        return extension_loaded('imagick') && class_exists(\Imagick::class);
    }

    /**
     * Render 1-based page to PNG. Returns absolute path or null.
     */
    public function renderPage(string $absolutePdfPath, int $pageNumber, string $destinationPng): ?string
    {
        if (! $this->isAvailable() || ! is_file($absolutePdfPath) || $pageNumber < 1) {
            return null;
        }

        try {
            $imagick = new \Imagick;
            $imagick->setResolution(150, 150);
            $imagick->readImage($absolutePdfPath.'['.($pageNumber - 1).']');
            $imagick->setImageFormat('png');
            $imagick->writeImage($destinationPng);
            $imagick->clear();
            $imagick->destroy();
        } catch (\Throwable) {
            return null;
        }

        return is_file($destinationPng) ? $destinationPng : null;
    }
}
