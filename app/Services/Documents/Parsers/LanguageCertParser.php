<?php

namespace App\Services\Documents\Parsers;

final class LanguageCertParser
{
    /**
     * @return list<array{language: string, level: string, source: string, candidate_name?: string, date_of_birth?: string}>
     */
    public function parse(string $normalizedText): array
    {
        $hay = mb_strtolower($normalizedText);
        $entries = [];

        $level = null;
        $source = null;

        if (preg_match('/goethe[- ]zertifikat\s*(a1|a2|b1|b2|c1|c2)/ui', $normalizedText, $m)) {
            $level = strtoupper($m[1]);
            $source = 'goethe';
        } elseif (preg_match('/telc\s+deutsch\s*(a1|a2|b1|b2|c1|c2)/ui', $normalizedText, $m)) {
            $level = strtoupper($m[1]);
            $source = 'telc';
        } elseif (preg_match('/\b(telc|goethe)\b.*\b(a1|a2|b1|b2|c1|c2)\b/ui', $normalizedText, $m)) {
            $level = strtoupper($m[2]);
            $source = mb_strtolower($m[1]);
        }

        if ($level === null) {
            return [];
        }

        $entry = [
            'language' => 'Deutsch',
            'level' => $level,
            'source' => $source ?? 'cert',
        ];

        if (preg_match('/(?:vorname|pr[eé]noms?)\s*[:\s]+([A-Za-zÀ-öø-ÿ \-]+)/ui', $normalizedText, $m)) {
            $given = trim($m[1]);
            $surname = null;
            if (preg_match('/(?:name|nom)\s*[:\s]+([A-Za-zÀ-öø-ÿ \-]+)/ui', $normalizedText, $n)) {
                $surname = trim($n[1]);
            }
            $full = trim(implode(' ', array_filter([$given, $surname])));
            if ($full !== '') {
                $entry['candidate_name'] = $full;
            }
        } elseif (preg_match('/vorname und name[^A-Za-z]*([A-Za-zÀ-öø-ÿ]+(?:\s+[A-Za-zÀ-öø-ÿ]+)+)/ui', $normalizedText, $m)) {
            $entry['candidate_name'] = trim($m[1]);
        }

        if (preg_match('/geburtsdatum[^0-9]*(\d{2}[.\-\/]\d{2}[.\-\/]\d{4})/ui', $normalizedText, $m)) {
            $entry['date_of_birth'] = $this->normalizeDate($m[1]);
        }

        // Ignore legend-only pages (no personal data and grading table).
        if (! isset($entry['candidate_name']) && str_contains($hay, 'nicht bestanden') && str_contains($hay, 'sehr gut')) {
            return [];
        }

        $entries[] = $entry;

        return $entries;
    }

    private function normalizeDate(string $raw): ?string
    {
        $raw = str_replace(['/', '-'], '.', $raw);
        if (! preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $raw, $m)) {
            return null;
        }

        return sprintf('%s-%s-%s', $m[3], $m[2], $m[1]);
    }
}
