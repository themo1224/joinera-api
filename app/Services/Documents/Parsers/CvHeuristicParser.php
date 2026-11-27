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
        $result['skills'] = $this->extractSkills($text, $result['experiences'], $result['about'] ?? null);

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
        $section = $this->sliceExperienceSection($text);
        $lines = preg_split('/\R/u', $section) ?: [];
        $entries = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }

            $parsed = $this->parseExperienceDateLine($line);
            if ($parsed === null) {
                continue;
            }

            $remainder = $parsed['remainder'];
            $descParts = [];
            $j = $i + 1;

            // When the date line has no job text, take up to two following non-bullet lines as title/org.
            $extraLines = 0;
            while ($j < $count && $extraLines < 2) {
                $next = trim($lines[$j]);
                if ($next === '') {
                    $j++;
                    continue;
                }
                if ($this->parseExperienceDateLine($next) !== null || $this->looksLikeSectionHeading($next)) {
                    break;
                }
                if (preg_match('/^[-•*–—]/u', $next)) {
                    break;
                }
                if ($remainder === '') {
                    $remainder = $next;
                    $j++;
                    $extraLines++;
                    continue;
                }
                [$probeTitle, $probeOrg] = $this->splitTitleOrganization($remainder);
                if ($probeTitle !== null && $probeOrg !== null) {
                    break;
                }
                $remainder = trim($remainder.' '.$next);
                $j++;
                $extraLines++;
            }

            [$title, $organization] = $this->splitTitleOrganization($remainder);
            if ($title === null && $organization === null && $remainder !== '') {
                $title = mb_substr($remainder, 0, 120);
            }

            // Collect bullet / duty lines until next date or heading.
            while ($j < $count) {
                $next = trim($lines[$j]);
                if ($next === '') {
                    $j++;
                    continue;
                }
                if ($this->parseExperienceDateLine($next) !== null || $this->looksLikeSectionHeading($next)) {
                    break;
                }
                $duty = preg_replace('/^[-•*–—]\s*/u', '', $next) ?? $next;
                $duty = trim($duty);
                if ($duty !== '') {
                    $descParts[] = $duty;
                }
                $j++;
                // Stop after a blank-separated block of duties when next non-empty is not a bullet
                // and looks like a new job without a date — handled by date/heading checks above.
            }

            $i = max($i, $j - 1);

            $description = $descParts !== []
                ? mb_substr(implode('; ', $descParts), 0, 1000)
                : null;

            if ($title === null && $organization === null && $description === null && $parsed['start'] === null) {
                continue;
            }

            $entry = [
                'title' => $title,
                'organization' => $organization,
                'start' => $parsed['start'],
                'end' => $parsed['end'],
                'current' => $parsed['current'],
                'description' => $description,
            ];
            $entries[] = array_filter(
                $entry,
                static fn (mixed $v, string $k): bool => $k === 'current' || ($v !== null && $v !== ''),
                ARRAY_FILTER_USE_BOTH
            );
        }

        return array_slice($entries, 0, 15);
    }

    private function sliceExperienceSection(string $text): string
    {
        if (preg_match(
            '#(?:berufliche\s+erfahrung|berufserfahrung|praktika|work\s+experience|beruflicher\s+werdegang)\s*[:\n]#uis',
            $text,
            $startMatch,
            PREG_OFFSET_CAPTURE
        )) {
            $start = $startMatch[0][1] + strlen($startMatch[0][0]);
            $rest = substr($text, $start);
            if (preg_match(
                '#\n\s*(?:bildung|ausbildung|schule|schulbildung|sprachen|sprachkenntnisse|kenntnisse|fähigkeiten|fahigkeiten|kompetenzen|skills|hobby|hobbys|interessen|führerschein|fuhrerschein|edv|it[-\s]?kenntnisse)\b#ui',
                $rest,
                $endMatch,
                PREG_OFFSET_CAPTURE
            )) {
                return trim(substr($rest, 0, $endMatch[0][1]));
            }

            return trim($rest);
        }

        return $text;
    }

    /**
     * @return array{start: ?string, end: ?string, current: bool, remainder: string}|null
     */
    private function parseExperienceDateLine(string $line): ?array
    {
        $dateToken = '(?:\d{1,2}[.\-/]\d{1,2}[.\-/]\d{4}|\d{1,2}[.\-/]\d{4}|\d{4})';
        $sep = '(?:\s*[-–—]\s*|\s+bis\s+)';
        $endToken = '(?:heute|today|jetzt|'.$dateToken.')';

        // Range: start – end [remainder]
        if (preg_match('#^('.$dateToken.')'.$sep.'('.$endToken.')(?:\s*[:|]?\s*|\s+)(.*)$#ui', $line, $m)) {
            $endRaw = $m[2];
            $current = (bool) preg_match('#^(?:heute|today|jetzt)$#ui', $endRaw);

            return [
                'start' => $this->normalizeExperienceDate($m[1]),
                'end' => $current ? null : $this->normalizeExperienceDate($endRaw),
                'current' => $current,
                'remainder' => trim($m[3] ?? ''),
            ];
        }

        // Compact range without space after end date: 06/07/2026-10/08/2026Title
        if (preg_match('#^('.$dateToken.')'.$sep.'('.$endToken.')(.*)$#ui', $line, $m)) {
            $endRaw = $m[2];
            $current = (bool) preg_match('#^(?:heute|today|jetzt)$#ui', $endRaw);

            return [
                'start' => $this->normalizeExperienceDate($m[1]),
                'end' => $current ? null : $this->normalizeExperienceDate($endRaw),
                'current' => $current,
                'remainder' => trim($m[3] ?? ''),
            ];
        }

        // Open / single-year style: YYYY. Title … or YYYY - Title …
        if (preg_match('#^(\d{4})\s*[.\-–—:]\s+(.+)$#u', $line, $m)) {
            return [
                'start' => $m[1],
                'end' => null,
                'current' => false,
                'remainder' => trim($m[2]),
            ];
        }

        return null;
    }

    private function normalizeExperienceDate(string $raw): ?string
    {
        $raw = trim($raw);
        if (preg_match('#^(\d{1,2})[.\-/](\d{1,2})[.\-/](\d{4})$#', $raw, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('#^(\d{1,2})[.\-/](\d{4})$#', $raw, $m)) {
            return sprintf('%04d-%02d', (int) $m[2], (int) $m[1]);
        }
        if (preg_match('#^(\d{4})$#', $raw, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function splitTitleOrganization(string $remainder): array
    {
        $remainder = trim(preg_replace('#\s+#u', ' ', $remainder) ?? '');
        if ($remainder === '') {
            return [null, null];
        }

        $patterns = [
            '#^(.+?)\s+bei\s+(.+)$#ui',
            '#^(.+?)\s*[|/]\s*(.+)$#u',
            '#^(.+?)\s+[–—]\s+(.+)$#u',
            '#^(.+?)\s+-\s+(.+)$#u',
            '#^(.+?),\s+(.+)$#u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $remainder, $m)) {
                $title = trim($m[1]);
                $org = trim($m[2]);
                if ($title !== '' && $org !== '') {
                    return [mb_substr($title, 0, 120), mb_substr($org, 0, 255)];
                }
            }
        }

        // "Servicekraft Restaurant MM" — first 1–3 words as title if rest looks like an org.
        if (preg_match('#^((?:\p{L}[\p{L}\'\-]*(?:\s+\p{L}[\p{L}\'\-]*){0,2}))\s+(.+)$#u', $remainder, $m)) {
            $title = trim($m[1]);
            $org = trim($m[2]);
            if ($org !== '' && (preg_match('#\b(restaurant|hotel|resort|gmbh|kg|ag|café|cafe|grill|beach|bar|bistro|küche|kitchen)\b#ui', $org)
                || mb_strlen($org) >= 8)) {
                return [mb_substr($title, 0, 120), mb_substr($org, 0, 255)];
            }
        }

        return [mb_substr($remainder, 0, 120), null];
    }

    private function looksLikeSectionHeading(string $line): bool
    {
        $hay = mb_strtolower(trim($line));
        if (mb_strlen($hay) > 60) {
            return false;
        }

        return (bool) preg_match(
            '#^(?:bildung|ausbildung|schule|schulbildung|sprachen|sprachkenntnisse|kenntnisse|fähigkeiten|fahigkeiten|kompetenzen|skills|hobby|hobbys|interessen|führerschein|fuhrerschein|edv|it[-\s]?kenntnisse|persönliche\s+daten|profil|kurzprofil)\b#ui',
            $hay
        );
    }

    /**
     * @param  list<array<string, mixed>>  $experiences
     * @return list<array{name: string, level: string|null, source: string}>
     */
    private function extractSkills(string $text, array $experiences = [], ?string $about = null): array
    {
        $skills = [];

        foreach ($this->extractExplicitSkillNames($text) as $name) {
            $skills[$this->skillKey($name)] = [
                'name' => $name,
                'level' => null,
                'source' => 'explicit',
            ];
        }

        $inferenceCorpus = mb_strtolower(implode("\n", array_filter([
            $text,
            $about,
            ...array_map(
                static fn (array $e): string => trim(($e['title'] ?? '').' '.($e['organization'] ?? '').' '.($e['description'] ?? '')),
                $experiences
            ),
        ])));

        foreach ($this->skillPhraseMap() as $needle => $label) {
            if (! str_contains($inferenceCorpus, $needle)) {
                continue;
            }
            $key = $this->skillKey($label);
            if (isset($skills[$key])) {
                continue;
            }
            $skills[$key] = [
                'name' => $label,
                'level' => null,
                'source' => 'inferred',
            ];
        }

        return array_values($skills);
    }

    /**
     * @return list<string>
     */
    private function extractExplicitSkillNames(string $text): array
    {
        $names = [];
        if (! preg_match(
            '#(?:kenntnisse|fähigkeiten|fahigkeiten|kompetenzen|skills|edv|it[-\s]?kenntnisse)\s*[:\n]+#uis',
            $text,
            $startMatch,
            PREG_OFFSET_CAPTURE
        )) {
            return [];
        }

        $start = $startMatch[0][1] + strlen($startMatch[0][0]);
        $rest = substr($text, $start);
        $endPos = strlen($rest);
        if (preg_match(
            '#\n\s*(?:bildung|ausbildung|schule|sprachen|sprachkenntnisse|hobby|hobbys|interessen|führerschein|fuhrerschein|berufliche\s+erfahrung|berufserfahrung|praktika|profil|kurzprofil)\b#ui',
            $rest,
            $endMatch,
            PREG_OFFSET_CAPTURE
        )) {
            $endPos = $endMatch[0][1];
        }
        $block = substr($rest, 0, $endPos);
        $parts = preg_split('#[\n,;•|/]+#u', $block) ?: [];
        foreach ($parts as $part) {
            $name = trim(preg_replace('#^[-–—*]\s*#u', '', $part) ?? '');
            $name = trim(preg_replace('#\s+#u', ' ', $name) ?? '');
            if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 60) {
                continue;
            }
            if ($this->looksLikeSectionHeading($name) || $this->parseExperienceDateLine($name) !== null) {
                continue;
            }
            $names[] = $name;
        }

        return $names;
    }

    /**
     * @return array<string, string> lowercase needle => display label
     */
    private function skillPhraseMap(): array
    {
        return [
            'haccp' => 'HACCP',
            'hygiene' => 'Hygiene',
            'gastronomie' => 'Gastronomie',
            'gäste betreuen' => 'Gäste betreuen',
            'gaste betreuen' => 'Gäste betreuen',
            'bestellungen' => 'Bestellungen',
            'bankett' => 'Bankett',
            'teamarbeit' => 'Teamarbeit',
            'teamwork' => 'Teamwork',
            'zuverlässigkeit' => 'Zuverlässigkeit',
            'kommunikation' => 'Kommunikation',
            'lernbereitschaft' => 'Lernbereitschaft',
            'microsoft' => 'Microsoft Office',
            'ms office' => 'Microsoft Office',
            'office' => 'Microsoft Office',
            'servicekraft' => 'Service',
            'service' => 'Service',
            'küche' => 'Küche',
            'kochen' => 'Kochen',
            'kellner' => 'Service',
            'bedienung' => 'Bedienung',
            'rezeption' => 'Rezeption',
            'kundenberatung' => 'Kundenberatung',
        ];
    }

    private function skillKey(string $name): string
    {
        return mb_strtolower(trim($name));
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
