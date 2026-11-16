<?php

namespace App\Support;

use App\Models\Profile;

/**
 * Formats structured profile fields for LLM CV / cover prompts.
 */
class ProfilePromptContext
{
    /**
     * @return list<string>
     */
    public static function lines(Profile $profile): array
    {
        $lines = [
            'Profil:',
            '- Branche: '.Profile::joinLabels($profile->industry),
            '- App-Sprache: '.($profile->language ?? ''),
            '- Ausbildungsniveau (Matching): '.($profile->education ?? ''),
            '- Wunschweg: '.($profile->desired_path ?? ''),
            '- Wunschberuf/-fach: '.($profile->preferred_occupation ?? ''),
            '- Verfügbar ab: '.($profile->available_from?->format('Y-m-d') ?? ''),
            '- Umzugsbereit: '.self::boolLabel($profile->willing_to_relocate),
        ];

        $about = trim((string) ($profile->about ?? ''));
        if ($about !== '') {
            $lines[] = '- Über mich / Stärken: '.$about;
        }

        $note = trim((string) ($profile->work_residence_note ?? ''));
        if ($note !== '') {
            $lines[] = '- Aufenthalt / Arbeitshinweis: '.$note;
        }

        $licenses = is_array($profile->driving_licenses) ? $profile->driving_licenses : [];
        if ($licenses !== []) {
            $lines[] = '- Führerschein: '.implode(', ', array_map('strval', $licenses));
        }

        $lines = [...$lines, ...self::listBlock('Ausbildung (Details)', self::formatEducationEntries($profile))];
        $lines = [...$lines, ...self::listBlock('Berufserfahrung', self::formatExperiences($profile))];
        $lines = [...$lines, ...self::listBlock('Kenntnisse', self::formatSkills($profile))];
        $lines = [...$lines, ...self::listBlock('Sprachen', self::formatLanguages($profile))];

        return $lines;
    }

    private static function boolLabel(mixed $value): string
    {
        if ($value === true) {
            return 'ja';
        }
        if ($value === false) {
            return 'nein';
        }

        return '';
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private static function listBlock(string $title, array $items): array
    {
        if ($items === []) {
            return [];
        }

        return ['', $title.':', ...array_map(fn (string $item): string => '- '.$item, $items)];
    }

    /**
     * @return list<string>
     */
    private static function formatEducationEntries(Profile $profile): array
    {
        $entries = is_array($profile->education_entries) ? $profile->education_entries : [];
        $out = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $parts = array_filter([
                self::str($entry['level'] ?? null),
                self::str($entry['program'] ?? null),
                self::str($entry['institution'] ?? null),
                self::range(self::str($entry['start'] ?? null), self::str($entry['end'] ?? null)),
                self::str($entry['status'] ?? null),
                self::str($entry['grade_note'] ?? null),
            ]);
            if ($parts !== []) {
                $out[] = implode(' | ', $parts);
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function formatExperiences(Profile $profile): array
    {
        $entries = is_array($profile->experiences) ? $profile->experiences : [];
        $out = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $end = ! empty($entry['current']) ? 'heute' : self::str($entry['end'] ?? null);
            $parts = array_filter([
                self::str($entry['title'] ?? null),
                self::str($entry['organization'] ?? null),
                self::str($entry['location'] ?? null),
                self::range(self::str($entry['start'] ?? null), $end),
                self::str($entry['description'] ?? null),
            ]);
            if ($parts !== []) {
                $out[] = implode(' | ', $parts);
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function formatSkills(Profile $profile): array
    {
        $entries = is_array($profile->skills) ? $profile->skills : [];
        $out = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = self::str($entry['name'] ?? null);
            if ($name === null) {
                continue;
            }
            $level = self::str($entry['level'] ?? null);
            $out[] = $level !== null ? $name.' ('.$level.')' : $name;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function formatLanguages(Profile $profile): array
    {
        $entries = is_array($profile->languages) ? $profile->languages : [];
        $out = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $language = self::str($entry['language'] ?? null);
            if ($language === null) {
                continue;
            }
            $level = self::str($entry['level'] ?? null);
            $out[] = $level !== null ? $language.' ('.$level.')' : $language;
        }

        return $out;
    }

    private static function str(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function range(?string $start, ?string $end): ?string
    {
        if ($start === null && $end === null) {
            return null;
        }
        if ($start !== null && $end !== null) {
            return $start.'–'.$end;
        }

        return $start ?? $end;
    }
}
