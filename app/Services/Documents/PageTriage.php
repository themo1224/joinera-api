<?php

namespace App\Services\Documents;

use App\Enums\PageTextKind;

final class PageTriage
{
    public function __construct(
        private readonly int $textMinNonWs = 200,
        private readonly int $scanMaxNonWs = 40,
    ) {
    }

    public function classify(string $rawText): PageTextKind
    {
        $nonWs = $this->nonWsCount($rawText);

        if ($nonWs === 0) {
            return PageTextKind::Empty;
        }

        if ($nonWs < $this->scanMaxNonWs) {
            // Watermark-only / garbage extract still counts as scan-ish empty usable text.
            if ($this->looksLikeWatermarkOnly($rawText)) {
                return PageTextKind::Empty;
            }

            return PageTextKind::Scan;
        }

        if ($nonWs >= $this->textMinNonWs) {
            if ($this->looksLikeGarbledExtract($rawText, $nonWs)) {
                return PageTextKind::Scan;
            }

            return PageTextKind::Text;
        }

        return PageTextKind::Hybrid;
    }

    public function nonWsCount(string $text): int
    {
        return mb_strlen(preg_replace('/\s+/u', '', $text) ?? '');
    }

    public function looksLikeWatermarkOnly(string $text): bool
    {
        $compact = mb_strtolower(preg_replace('/\s+/u', '', $text) ?? '');

        return $compact === 'camscanner' || str_contains($compact, 'camscanner') && mb_strlen($compact) < 40;
    }

    /**
     * High char count but mostly punctuation/noise (embedded OCR garbage).
     */
    public function looksLikeGarbledExtract(string $text, ?int $nonWs = null): bool
    {
        $nonWs ??= $this->nonWsCount($text);
        if ($nonWs < 80) {
            return false;
        }

        $letters = preg_match_all('/\p{L}/u', $text) ?: 0;
        $ratio = $letters / max(1, $nonWs);

        return $ratio < 0.45;
    }
}
