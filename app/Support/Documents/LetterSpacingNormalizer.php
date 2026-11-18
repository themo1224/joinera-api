<?php

namespace App\Support\Documents;

final class LetterSpacingNormalizer
{
    /**
     * Collapse letter-spaced OCR/PDF text: "O t h m a n e" → "Othmane".
     * Preserves normal words/spaces when spacing is not single-character runs.
     */
    public function normalize(string $text): string
    {
        if (trim($text) === '') {
            return '';
        }

        $lines = preg_split("/\R/u", $text) ?: [];
        $out = [];

        foreach ($lines as $line) {
            $out[] = $this->normalizeLine($line);
        }

        $joined = implode("\n", $out);
        $joined = preg_replace('/[ \t]{2,}/u', ' ', $joined) ?? $joined;

        return trim($joined);
    }

    private function normalizeLine(string $line): string
    {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return '';
        }

        // Pattern: single non-space tokens separated by single spaces across most of the line.
        if (! preg_match('/^(?:\S(?: \S)+)$/u', $trimmed)) {
            // Partial letter-spacing in segments: collapse runs of "X Y Z" (3+ singles).
            return preg_replace_callback(
                '/(?<![^\s])(?:\S(?: \S){2,})(?![^\s])/u',
                static function (array $m): string {
                    return str_replace(' ', '', $m[0]);
                },
                $trimmed,
            ) ?? $trimmed;
        }

        return str_replace(' ', '', $trimmed);
    }
}
