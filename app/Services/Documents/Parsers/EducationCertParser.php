<?php

namespace App\Services\Documents\Parsers;

final class EducationCertParser
{
    /**
     * @return array{
     *   education_entries: list<array<string, mixed>>,
     *   education?: string|null,
     *   candidate_name?: string|null,
     *   date_of_birth?: string|null
     * }
     */
    public function parse(string $normalizedText): array
    {
        $result = [
            'education_entries' => [],
        ];

        $name = $this->extractName($normalizedText);
        if ($name !== null) {
            $result['candidate_name'] = $name;
        }

        $dob = $this->extractDob($normalizedText);
        if ($dob !== null) {
            $result['date_of_birth'] = $dob;
        }

        $level = $this->inferLevel($normalizedText);
        $institution = $this->extractInstitution($normalizedText);
        $program = $this->extractProgram($normalizedText);
        $year = $this->extractYear($normalizedText);
        $grade = $this->extractGrade($normalizedText);

        if ($level !== null || $institution !== null || $program !== null) {
            $entry = array_filter([
                'level' => $level,
                'institution' => $institution,
                'program' => $program,
                'end' => $year,
                'status' => 'completed',
                'grade_note' => $grade,
            ], static fn ($v) => $v !== null && $v !== '');

            $result['education_entries'][] = $entry;
            $result['education'] = $this->mapScalarEducation($level);
        }

        return $result;
    }

    private function extractName(string $text): ?string
    {
        $nameChars = '[\p{L}\p{M}\'\- ]';
        $patterns = [
            '/(?:herr|frau)\s*:\s*('.$nameChars.'+)/ui',
            '/\b(?:herr|frau)\s+('.$nameChars.'+?)\s+(?:hat|wurde|ist|geboren|geb\.)/ui',
            '/(?:der|die)\s+sch[uü]ler(?:in)?\s+('.$nameChars.'+?)\s+(?:hat|wurde|ist|geboren)/ui',
            '/(?:familienname und vorname|nom et pr[eé]nom)\s*:\s*('.$nameChars.'+)/ui',
            '/(?:name[, ]+vorname|vorname und name)\s*[:\s]+('.$nameChars.'+)/ui',
            '/\b(?:name)\s*:\s*('.$nameChars.'+)/ui',
            '/(?:geboren(?:e|er)?)\s+('.$nameChars.'+?)\s+(?:am|geboren am)/ui',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $name = trim(preg_replace('/\s+/u', ' ', $m[1]) ?? '');
                if ($this->looksLikePersonName($name)) {
                    return $name;
                }
            }
        }

        return null;
    }

    private function extractDob(string $text): ?string
    {
        if (preg_match('/(?:geboren am|geburtsdatum|n[eé] le|date de naissance)[^0-9]*(\d{2}[.\-\/]\d{2}[.\-\/]\d{4})/ui', $text, $m)) {
            return $this->normalizeDate($m[1]);
        }

        return null;
    }

    private function inferLevel(string $text): ?string
    {
        $hay = mb_strtolower($text);

        if (str_contains($hay, 'bachelor') || str_contains($hay, 'licence') || str_contains($hay, 'diplome d\'etudes universitaires') || str_contains($hay, 'diplôme d\'études universitaires')) {
            return 'Bachelor';
        }
        if (str_contains($hay, 'master')) {
            return 'Master';
        }
        if (str_contains($hay, 'baccalauréat') || str_contains($hay, 'baccalaureat') || str_contains($hay, 'abitur') || str_contains($hay, 'reifezeugnis')) {
            return 'Abitur';
        }
        if (str_contains($hay, 'schulbescheinigung') || str_contains($hay, '1st baccalaureate') || str_contains($hay, '1. baccalauréat') || str_contains($hay, 'erste') && str_contains($hay, 'baccalauréat')) {
            return 'Abitur';
        }

        return null;
    }

    private function mapScalarEducation(?string $level): ?string
    {
        return match ($level) {
            'Abitur', 'Bachelor', 'Master', 'Hauptschulabschluss', 'Mittlere Reife', 'Realschulabschluss', 'Fachhochschulreife' => $level,
            default => $level,
        };
    }

    private function extractInstitution(string $text): ?string
    {
        $patterns = [
            '/(?:etablissement|établissement|schule|gymnasium|universit[eä]t|lycee|lycée)\s*[:\s]+([^\n,]{3,120})/ui',
            '/(?:oberschule|privates gymnasium)\s+([^\n,]{3,80})/ui',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    private function extractProgram(string $text): ?string
    {
        $patterns = [
            '/(?:fachrichtung|schwerpunkt|studiengang|niveau|stream|fili[eè]re)\s*[:\s]+([^\n]{3,120})/ui',
            '/(?:sciences humaines|wirtschaftswissenschaften|naturwissenschaften|moderne literatur)[^\n]{0,40}/ui',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return trim($m[1] ?? $m[0]);
            }
        }

        return null;
    }

    private function extractYear(string $text): ?string
    {
        if (preg_match('/(?:session|pr[uü]fungsperiode|juli|juin|june|juni)\s+(\d{4})/ui', $text, $m)) {
            return $m[1];
        }
        if (preg_match('/\b(20\d{2})\b/', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractGrade(string $text): ?string
    {
        if (preg_match('/(?:pr[aä]dikat|mention|note)\s*[:\s]*(befriedigend|ausreichend|gut|sehr gut|passable|assez bien|bien)/ui', $text, $m)) {
            return ucfirst(mb_strtolower($m[1]));
        }

        return null;
    }

    private function looksLikePersonName(string $name): bool
    {
        if (mb_strlen($name) < 3 || mb_strlen($name) > 80) {
            return false;
        }

        $blocked = ['übersetzer', 'translator', 'telc', 'goethe', 'ministerium', 'royaume'];
        $hay = mb_strtolower($name);
        foreach ($blocked as $b) {
            if (str_contains($hay, $b)) {
                return false;
            }
        }

        return (bool) preg_match('/\p{L}{2,}/u', $name);
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
