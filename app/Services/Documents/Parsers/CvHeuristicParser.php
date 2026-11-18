<?php

namespace App\Services\Documents\Parsers;

final class CvHeuristicParser
{
    /**
     * @return array{
     *   preferred_occupation?: string|null,
     *   about?: string|null,
     *   education_entries: list<array<string, mixed>>,
     *   experiences: list<array<string, mixed>>,
     *   skills: list<array<string, mixed>>,
     *   languages: list<array{language: string, level: string}>,
     *   driving_licenses: list<string>,
     *   education?: string|null,
     *   desired_path?: string|null,
     *   candidate_name?: string|null,
     *   date_of_birth?: string|null
     * }
     */
    public function parse(string $normalizedText): array
    {
        $text = $normalizedText;
        $result = [
            'education_entries' => [],
            'experiences' => [],
            'skills' => [],
            'languages' => [],
            'driving_licenses' => [],
        ];

        $name = $this->extractName($text);
        if ($name !== null) {
            $result['candidate_name'] = $name;
        }

        if (preg_match('/geburtsdatum[^0-9]*(\d{2}[.\-\/]\d{2}[.\-\/]\d{4})/ui', $text, $m)) {
            $result['date_of_birth'] = $this->normalizeDate($m[1]);
        }

        $result['languages'] = $this->extractLanguages($text);
        $result['driving_licenses'] = $this->extractLicenses($text);
        $result['preferred_occupation'] = $this->extractOccupation($text);
        $result['desired_path'] = $this->extractDesiredPath($text);
        $result['about'] = $this->extractAbout($text);
        $result['education_entries'] = $this->extractEducation($text);
        $result['experiences'] = $this->extractExperiences($text);
        $result['skills'] = $this->extractSkills($text);

        if ($result['education_entries'] !== []) {
            $result['education'] = $result['education_entries'][0]['level'] ?? null;
        }

        return $result;
    }

    private function extractName(string $text): ?string
    {
        $nameChars = '[\p{L}\p{M}\'\- ]';
        $patterns = [
            '/(?:nachname|familienname)\s*:\s*('.$nameChars.'+).*?(?:vorname)\s*:\s*('.$nameChars.'+)/uis',
            '/(?:vorname)\s*:\s*('.$nameChars.'+).*?(?:nachname|familienname|name)\s*:\s*('.$nameChars.'+)/uis',
            '/(?:vorname und(?:er)? name|name und vorname)\s*:\s*('.$nameChars.'+)/ui',
            '/(?:^|\n)\s*(?:name)\s*:\s*('.$nameChars.'+)/ui',
            '/(?:persönliche daten|personal\s*data)[^\n]{0,40}\n\s*('.$nameChars.'{3,80})\s*(?:\n|$)/ui',
        ];

        foreach ($patterns as $i => $pattern) {
            if (! preg_match($pattern, $text, $m)) {
                continue;
            }
            if ($i <= 1 && isset($m[1], $m[2])) {
                $combined = $i === 0
                    ? trim($m[2]).' '.trim($m[1])
                    : trim($m[1]).' '.trim($m[2]);
                $combined = trim(preg_replace('/\s+/u', ' ', $combined) ?? '');
                if ($this->looksLikePersonName($combined)) {
                    return $combined;
                }
            }
            $single = trim(preg_replace('/\s+/u', ' ', $m[1]) ?? '');
            if ($this->looksLikePersonName($single)) {
                return $single;
            }
        }

        if (preg_match('/^([A-ZÀ-ÖØ-Þ\p{L}][\p{L}\'\-]+(?:\s+[A-ZÀ-ÖØ-Þ\p{L}][\p{L}\'\-]+)+)/u', $text, $m)) {
            $fallback = trim($m[1]);
            if ($this->looksLikePersonName($fallback)) {
                return $fallback;
            }
        }

        return null;
    }

    private function looksLikePersonName(string $name): bool
    {
        if (mb_strlen($name) < 3 || mb_strlen($name) > 80) {
            return false;
        }

        $blocked = ['lebenslauf', 'curriculum', 'bewerbung', 'persönliche', 'sprachen'];
        $hay = mb_strtolower($name);
        foreach ($blocked as $b) {
            if (str_contains($hay, $b)) {
                return false;
            }
        }

        return (bool) preg_match('/\p{L}{2,}(?:\s+\p{L}{2,})+/u', $name);
    }

    /**
     * @return list<array{language: string, level: string}>
     */
    private function extractLanguages(string $text): array
    {
        $out = [];
        $lang = '(arabisch|deutsch|englisch|französisch|franz[oö]sisch|spanisch|english|french|german|arabic|italienisch|italian|t[uü]rkisch|turkish|persisch|farsi|kurdisch)';
        $level = '(muttersprache|native|muttersprachler(?:in)?|a1|a2|b1|b2|c1|c2|gute kenntnisse|grundkenntnisse|flie[sß]end[^,\n]*|verhandlungssicher|sehr gut|gut)';
        $patterns = [
            '/'.$lang.'\s*[:\-–]\s*'.$level.'/ui',
            '/'.$lang.'\s+'.$level.'/ui',
            '/'.$lang.'\s*\((?:niveau\s*)?(a1|a2|b1|b2|c1|c2|muttersprache|native)\)/ui',
            '/[-•*]\s*'.$lang.'\s*[:\-–,]?\s*'.$level.'/ui',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $canonicalLang = $this->canonicalizeLanguage($m[1]);
                    $canonicalLevel = $this->canonicalizeCefr($m[2]);
                    if ($canonicalLang === null || $canonicalLevel === null) {
                        continue;
                    }
                    $out[$canonicalLang] = ['language' => $canonicalLang, 'level' => $canonicalLevel];
                }
            }
        }

        return array_values($out);
    }

    /**
     * @return list<string>
     */
    private function extractLicenses(string $text): array
    {
        $licenses = [];
        if (preg_match_all('/f[uü]hrerschein[^.\n]{0,40}(?:klasse\s*)?([A-Z]{1,3})/ui', $text, $m)) {
            foreach ($m[1] as $code) {
                $licenses[] = strtoupper($code);
            }
        }
        if (preg_match_all('/\bklasse\s+([A-Z]{1,3})\b/ui', $text, $m)) {
            foreach ($m[1] as $code) {
                $licenses[] = strtoupper($code);
            }
        }

        return array_values(array_unique($licenses));
    }

    private function extractOccupation(string $text): ?string
    {
        if (preg_match('/bewerbung um[^\n]{0,80}(?:als|zum|zur)\s+([^\n,]{3,60})/ui', $text, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/\b(k[oö]chin?|commis|sicherheits\w*|pflege\w*)\b/ui', $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function extractDesiredPath(string $text): ?string
    {
        $hay = mb_strtolower($text);
        if (str_contains($hay, 'ausbildungsplatz') || str_contains($hay, 'ausbildung')) {
            return 'ausbildung';
        }
        if (str_contains($hay, 'stelle als') || str_contains($hay, 'bewerbung um eine stelle')) {
            return 'job';
        }

        return null;
    }

    private function extractAbout(string $text): ?string
    {
        if (preg_match('/(?:kurzprofil|profil)\s*[:\n]\s*(.+?)(?=\n(?:beruf|bildung|schule|sprachen|f[uü]hrer|hobby)|$)/uis', $text, $m)) {
            $about = trim(preg_replace('/\s+/u', ' ', $m[1]) ?? '');
            if (mb_strlen($about) >= 40) {
                return mb_substr($about, 0, 2000);
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractEducation(string $text): array
    {
        $entries = [];
        if (preg_match_all('/(?:\b(?:20\d{2}|19\d{2})\b[^\n]{0,10})?(abitur|baccalaur[eé]at|bachelor|licence|master|diplom)[^\n]{0,120}/ui', $text, $matches)) {
            foreach ($matches[0] as $i => $line) {
                $levelRaw = $matches[1][$i] ?? '';
                $level = $this->mapEducationLevel($levelRaw);
                $entries[] = array_filter([
                    'level' => $level,
                    'program' => trim($line),
                    'status' => 'completed',
                ]);
            }
        }

        return array_slice($entries, 0, 8);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractExperiences(string $text): array
    {
        $entries = [];
        if (preg_match_all('/(?:\d{2}[.\-\/]\d{2}[.\-\/]\d{4}|\d{2}\/\d{4}|\d{4})\s*[-–_bis]+\s*(?:heute|today|\d{2}[.\-\/]\d{2}[.\-\/]\d{4}|\d{2}\/\d{4}|\d{4})?[^\n]{0,160}/ui', $text, $matches)) {
            foreach ($matches[0] as $chunk) {
                $entries[] = [
                    'title' => null,
                    'organization' => trim(mb_substr($chunk, 0, 255)),
                    'description' => trim(mb_substr($chunk, 0, 500)),
                    'current' => (bool) preg_match('/heute|today/ui', $chunk),
                ];
            }
        }

        return array_slice($entries, 0, 15);
    }

    /**
     * @return list<array{name: string, level: string|null}>
     */
    private function extractSkills(string $text): array
    {
        $skills = [];
        $keywords = ['teamarbeit', 'zuverlässigkeit', 'kommunikation', 'lernbereitschaft', 'hygiene', 'haccp', 'microsoft', 'office'];
        $hay = mb_strtolower($text);
        foreach ($keywords as $kw) {
            if (str_contains($hay, $kw)) {
                $skills[] = ['name' => ucfirst($kw), 'level' => null];
            }
        }

        return $skills;
    }

    private function canonicalizeLanguage(string $raw): ?string
    {
        $key = mb_strtolower(trim($raw));

        return match (true) {
            str_starts_with($key, 'arab') => 'Arabisch',
            str_starts_with($key, 'deut') || $key === 'german' => 'Deutsch',
            str_starts_with($key, 'engl') => 'Englisch',
            str_starts_with($key, 'franz') || $key === 'french' => 'Französisch',
            str_starts_with($key, 'span') => 'Spanisch',
            str_starts_with($key, 'ital') => 'Italienisch',
            (str_contains($key, 'ürkisch') || str_contains($key, 'urkisch') || $key === 'turkish') => 'Türkisch',
            str_starts_with($key, 'pers') || $key === 'farsi' => 'Persisch',
            str_starts_with($key, 'kurd') => 'Kurdisch',
            default => ucfirst($raw),
        };
    }

    private function canonicalizeCefr(string $raw): ?string
    {
        $key = mb_strtolower(trim($raw));
        if (str_contains($key, 'mutter') || $key === 'native') {
            return 'native';
        }
        if (preg_match('/\b(a1|a2|b1|b2|c1|c2)\b/i', $key, $m)) {
            return strtoupper($m[1]);
        }
        if (str_contains($key, 'grund')) {
            return 'A2';
        }
        if (str_contains($key, 'verhandlung')) {
            return 'C1';
        }
        if (str_contains($key, 'sehr gut')) {
            return 'B2';
        }
        if (str_contains($key, 'gute') || $key === 'gut') {
            return 'B1';
        }
        if (str_contains($key, 'flie')) {
            return 'B2';
        }

        return null;
    }

    private function mapEducationLevel(string $raw): string
    {
        $key = mb_strtolower($raw);

        return match (true) {
            str_contains($key, 'master') => 'Master',
            str_contains($key, 'bachelor') || str_contains($key, 'licence') => 'Bachelor',
            str_contains($key, 'abitur') || str_contains($key, 'bac') => 'Abitur',
            default => ucfirst($raw),
        };
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
